<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Jobs\ProcessInboundEmailJob;
use Symfony\Component\HttpFoundation\Response;

final readonly class InboundEmailWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('inbound-email.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            abort(Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $timestamp = (string) $request->header('X-CRM-Email-Timestamp', '');
        $nonce = (string) $request->header('X-CRM-Email-Nonce', '');
        $signature = (string) $request->header('X-CRM-Email-Signature', '');
        $envelopeFrom = strtolower(trim((string) $request->header('X-CRM-Email-From', '')));
        $envelopeTo = strtolower(trim((string) $request->header('X-CRM-Email-To', '')));

        abort_unless($timestamp && $nonce && $signature && $envelopeTo !== '', Response::HTTP_UNAUTHORIZED);
        abort_if(! ctype_digit($timestamp), Response::HTTP_UNAUTHORIZED);
        abort_if(abs(now()->getTimestamp() - (int) $timestamp) > 300, Response::HTTP_UNAUTHORIZED);

        $payload = "{$timestamp}.{$nonce}.{$envelopeFrom}.{$envelopeTo}";
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        abort_unless(hash_equals($expectedSignature, $signature), Response::HTTP_UNAUTHORIZED);

        $nonceKey = 'inbound-email:webhook-nonce:'.hash('sha256', $nonce);
        abort_unless(Cache::add($nonceKey, true, now()->addMinutes(10)), Response::HTTP_CONFLICT);

        $rawMessage = $request->getContent();

        abort_if($rawMessage === '', Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if(strlen($rawMessage) > (int) config('inbound-email.max_raw_email_bytes'), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);

        $disk = (string) config('inbound-email.raw_disk');
        $rawPath = 'inbound-email/raw/'.now()->format('Y/m/d').'/'.Str::ulid().'.eml';

        Storage::disk($disk)->put($rawPath, $rawMessage);

        ProcessInboundEmailJob::dispatch(
            rawDisk: $disk,
            rawPath: $rawPath,
            envelopeFrom: $envelopeFrom,
            envelopeTo: $envelopeTo,
        );

        return response()->json(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }
}
