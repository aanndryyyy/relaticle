<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\Cache;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;
use RuntimeException;

final readonly class StartMailboxHistoryImportAction
{
    public function __construct(
        private MailboxHistoryImportService $mailboxHistoryImport,
    ) {}

    public function execute(ConnectedAccount $connectedAccount): void
    {
        $account = $connectedAccount->fresh() ?? $connectedAccount;

        $lock = Cache::lock($this->mailboxHistoryImport->lockKey($account), 60);

        throw_unless($lock->get(), RuntimeException::class, 'A mailbox history import is already starting for this account.');

        try {
            throw_if($account->sync_cursor === null && $this->mailboxHistoryImport->isRunning($account), RuntimeException::class, 'A mailbox history import is already running for this account.');

            $hadSyncCursor = $account->sync_cursor !== null;

            $batch = $this->mailboxHistoryImport->startBatch($account);

            $account->update(['history_import_batch_id' => $batch->id]);

            if ($hadSyncCursor) {
                $account->update([
                    'sync_cursor' => null,
                    'initial_sync_estimated' => null,
                ]);
            }

            $account = $account->fresh() ?? $account;

            dispatch(new RelinkMailboxHistoryJob($account))->afterCommit();
            dispatch(new InitialEmailSyncJob($account, historyImportBatchId: $batch->id))->afterCommit();

            if (! $account->hasCalendar()) {
                return;
            }

            if ($account->calendar_sync_cursor !== null) {
                MailboxSyncTracker::markCalendarStarted($account);
                dispatch(new IncrementalCalendarSyncJob($account, reconcileAfter: true))->afterCommit();

                return;
            }

            dispatch(new InitialCalendarSyncJob($account))->afterCommit();
        } finally {
            $lock->release();
        }
    }
}
