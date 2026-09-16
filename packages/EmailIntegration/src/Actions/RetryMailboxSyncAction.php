<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

/**
 * Re-runs sync for a mailbox that reported a sync error, so a user can recover
 * dropped mail without a developer replaying failed jobs. A missing cursor means
 * the first import never finished, so that side restarts instead of resuming.
 */
final readonly class RetryMailboxSyncAction
{
    public function execute(ConnectedAccount $account): void
    {
        $account->update([
            'last_error' => null,
            'status' => $account->status === EmailAccountStatus::ERROR
                ? EmailAccountStatus::ACTIVE
                : $account->status,
        ]);

        if ($account->hasEmail()) {
            dispatch($account->sync_cursor === null
                ? new InitialEmailSyncJob($account)
                : new IncrementalEmailSyncJob($account))->afterCommit();
        }

        if (! $account->hasCalendar()) {
            return;
        }

        dispatch($account->calendar_sync_cursor === null
            ? new InitialCalendarSyncJob($account)
            : new IncrementalCalendarSyncJob($account, reconcileAfter: true))->afterCommit();
    }
}
