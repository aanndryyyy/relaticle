<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use RuntimeException;

/**
 * Local-only switch for reproducing store failures during a mailbox import, so the
 * recovery path can be walked without waiting for a real provider outage. The picked
 * ids are cached so retries keep failing the same messages; clear the cache to reroll.
 */
final readonly class EmailSyncDebugStoreFailure
{
    public function failJobIfConfigured(ConnectedAccount $account, string $messageId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $messageIdToFail = Config::get('email-integration.sync.debug_fail_message_id');

        throw_if(is_string($messageIdToFail) && $messageIdToFail !== '' && $messageId === $messageIdToFail, RuntimeException::class, 'EMAIL_SYNC_DEBUG_FAIL_MESSAGE_ID forced store failure.');

        $firstN = Config::integer('email-integration.sync.debug_fail_first_n');

        if ($firstN <= 0) {
            return;
        }

        $assignedKey = $this->assignedMessageIdsKey($account);

        /** @var list<string> $assigned */
        $assigned = cache()->get($assignedKey, []);

        throw_if(in_array($messageId, $assigned, true), RuntimeException::class, 'EMAIL_SYNC_DEBUG_FAIL_FIRST_N forced store failure.');

        if (count($assigned) >= $firstN) {
            return;
        }

        $assigned[] = $messageId;
        cache()->put($assignedKey, $assigned, now()->addDay());

        throw new RuntimeException('EMAIL_SYNC_DEBUG_FAIL_FIRST_N forced store failure.');
    }

    private function enabled(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        return Config::integer('email-integration.sync.debug_fail_first_n') > 0
            || filled(Config::get('email-integration.sync.debug_fail_message_id'));
    }

    private function assignedMessageIdsKey(ConnectedAccount $account): string
    {
        return 'email-sync-debug-fail-ids:'.$account->getKey();
    }
}
