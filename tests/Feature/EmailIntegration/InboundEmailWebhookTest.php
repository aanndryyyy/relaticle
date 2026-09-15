<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Jobs\ProcessInboundEmailJob;

mutates(ProcessInboundEmailJob::class);

beforeEach(function (): void {
    Config::set('inbound-email.webhook_secret', 'test-inbound-secret');
    Storage::fake('local');
    Bus::fake([ProcessInboundEmailJob::class]);
});

/**
 * @return array{timestamp: string, nonce: string, signature: string}
 */
function inboundWebhookHeaders(string $envelopeFrom, string $envelopeTo, string $secret = 'test-inbound-secret'): array
{
    $timestamp = (string) now()->timestamp;
    $nonce = (string) Str::uuid();
    $payload = "{$timestamp}.{$nonce}.{$envelopeFrom}.{$envelopeTo}";
    $signature = hash_hmac('sha256', $payload, $secret);

    return [
        'timestamp' => $timestamp,
        'nonce' => $nonce,
        'signature' => $signature,
    ];
}

it('rejects inbound webhook requests without a valid signature', function (): void {
    $response = $this->call(
        'POST',
        '/webhooks/inbound-email',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'message/rfc822'],
        'raw-body',
    );

    $response->assertUnauthorized();
});

it('accepts a signed inbound webhook and queues processing', function (): void {
    $to = 'w_01jfrzjr9d6akm4n4ay26xqt9x_token@relaticle.email';
    $from = 'member@example.com';
    $headers = inboundWebhookHeaders($from, $to);
    $body = "From: Jane <jane@example.com>\r\nSubject: Hi\r\n\r\nHello";

    $response = $this->call(
        'POST',
        '/webhooks/inbound-email',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'message/rfc822',
            'HTTP_X_CRM_EMAIL_TIMESTAMP' => $headers['timestamp'],
            'HTTP_X_CRM_EMAIL_NONCE' => $headers['nonce'],
            'HTTP_X_CRM_EMAIL_SIGNATURE' => $headers['signature'],
            'HTTP_X_CRM_EMAIL_FROM' => $from,
            'HTTP_X_CRM_EMAIL_TO' => $to,
        ],
        $body,
    );

    $response->assertAccepted();

    Bus::assertDispatched(ProcessInboundEmailJob::class, function (ProcessInboundEmailJob $job) use ($from, $to): bool {
        return $job->envelopeFrom === $from && $job->envelopeTo === $to;
    });
});

it('rejects replayed inbound webhook nonces', function (): void {
    $to = 'w_test@relaticle.email';
    $from = 'member@example.com';
    $headers = inboundWebhookHeaders($from, $to);
    $post = fn () => $this->call(
        'POST',
        '/webhooks/inbound-email',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'message/rfc822',
            'HTTP_X_CRM_EMAIL_TIMESTAMP' => $headers['timestamp'],
            'HTTP_X_CRM_EMAIL_NONCE' => $headers['nonce'],
            'HTTP_X_CRM_EMAIL_SIGNATURE' => $headers['signature'],
            'HTTP_X_CRM_EMAIL_FROM' => $from,
            'HTTP_X_CRM_EMAIL_TO' => $to,
        ],
        'body',
    );

    $post()->assertAccepted();
    $post()->assertStatus(409);
});
