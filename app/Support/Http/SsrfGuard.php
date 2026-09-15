<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Exceptions\SsrfGuardException;
use App\Exceptions\UploadException;
use App\Support\Media\UploadAllowlist;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final readonly class SsrfGuard
{
    /**
     * Ranges PHP's own filter reports as public. The translation prefixes matter
     * most: 2002::/16 and 64:ff9b::/96 each embed an IPv4 address, so they reach
     * loopback and RFC1918 on any host with IPv6.
     *
     * @var list<string>
     */
    private const array DENIED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '224.0.0.0/4',
        '2002::/16',
        '64:ff9b::/96',
        '2001:db8::/32',
    ];

    public static function isAllowed(string $url): bool
    {
        try {
            self::assertPublicHost($url);

            return true;
        } catch (SsrfGuardException $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * An HTTP client that re-validates every redirect hop against this guard.
     *
     * The underlying client follows redirects by default, so validating only the
     * initial URL would let an attacker-controlled public host redirect the request
     * to an internal address (SSRF, CWE-918). Callers must still validate the initial
     * URL with {@see self::isAllowed()}. The guard below only covers redirect hops.
     */
    public static function guardedHttpClient(): PendingRequest
    {
        return Http::withOptions(self::redirectGuardOptions());
    }

    /**
     * Guzzle options whose on_redirect callback aborts the request before any
     * non-public redirect target is contacted, reporting the block for parity
     * with the initial-URL check in {@see self::isAllowed()}.
     *
     * @return array{allow_redirects: array<string, mixed>}
     */
    public static function redirectGuardOptions(): array
    {
        return [
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'on_redirect' => static function (
                    RequestInterface $request,
                    ResponseInterface $response,
                    UriInterface $uri,
                ): void {
                    try {
                        self::assertPublicHost((string) $uri);
                    } catch (SsrfGuardException $exception) {
                        report($exception);

                        throw $exception;
                    }
                },
            ],
        ];
    }

    public static function pinnedClient(string $url): PendingRequest
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? 443) : null;

        throw_unless($scheme === 'https' && $port === 443, SsrfGuardException::class, 'Only https URLs on port 443 are allowed');

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $addresses = self::resolveAddresses($host);

        throw_if($addresses === [], SsrfGuardException::class, "Could not resolve host: {$host}");

        foreach ($addresses as $address) {
            throw_unless(self::isPublicAddress($address), SsrfGuardException::class, "Refusing to fetch from non-public address: {$address}");
        }

        $address = $addresses[0];
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        // CURLOPT_RESOLVE pins the connection to the address checked above, so a
        // DNS answer cannot change between the check and the fetch.
        return Http::withOptions([
            'allow_redirects' => false,
            'decode_content' => false,
            'connect_timeout' => 10,
            'timeout' => 30,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"]],
            'progress' => static function (int $downloadTotal, int $downloaded): void {
                throw_if(max($downloadTotal, $downloaded) > UploadAllowlist::maxBytes(), UploadException::tooLarge(UploadAllowlist::maxBytes()));
            },
        ]);
    }

    public static function assertPublicHost(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        throw_if(! is_string($host) || $host === '', SsrfGuardException::class, 'Invalid host in URL');

        $host = trim($host, '[]');

        $addresses = self::resolveAddresses($host);

        throw_if($addresses === [], SsrfGuardException::class, "Could not resolve host: {$host}");

        foreach ($addresses as $address) {
            throw_unless(self::isPublicAddress($address), SsrfGuardException::class, "Refusing to fetch from non-public address: {$address}");
        }
    }

    /**
     * @return list<string>
     */
    private static function resolveAddresses(string $host): array
    {
        return resolve(HostResolver::class)->addresses($host);
    }

    private static function isPublicAddress(string $address): bool
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        if (! $public) {
            return false;
        }

        return ! array_any(
            self::DENIED_RANGES,
            fn (string $range): bool => self::withinRange($address, $range),
        );
    }

    private static function withinRange(string $address, string $range): bool
    {
        [$subnet, $prefix] = explode('/', $range);

        $packed = inet_pton($address);
        $packedSubnet = inet_pton($subnet);

        if ($packed === false || $packedSubnet === false || strlen($packed) !== strlen($packedSubnet)) {
            return false;
        }

        $wholeBytes = intdiv((int) $prefix, 8);

        if (strncmp($packed, $packedSubnet, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = (int) $prefix % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $remainingBits) & 0xFF);

        return ($packed[$wholeBytes] & $mask) === ($packedSubnet[$wholeBytes] & $mask);
    }
}
