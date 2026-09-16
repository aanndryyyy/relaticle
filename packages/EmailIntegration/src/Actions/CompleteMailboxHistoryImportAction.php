<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

final readonly class CompleteMailboxHistoryImportAction
{
    public function __construct(
        private MailboxHistoryImportService $mailboxHistoryImport,
    ) {}

    public function execute(string $accountId, string $batchId): void
    {
        DB::transaction(function () use ($accountId, $batchId): void {
            $account = ConnectedAccount::query()->lockForUpdate()->find($accountId);
            $batch = Bus::findBatch($batchId);

            // pendingJobs still counts in-flight store work; failedJobIds are permanent failures only.
            if (! $account instanceof ConnectedAccount || ! $batch instanceof Batch
                || $account->history_import_batch_id !== $batchId
                || $account->sync_cursor === null
                || $account->status !== EmailAccountStatus::ACTIVE
                || $batch->cancelled()
                || $batch->pendingJobs > count($batch->failedJobIds)) {
                return;
            }

            $user = $account->user;

            if ($user === null) {
                return;
            }

            $updates = ['initial_sync_imported' => $account->emails()->count()];

            if ($batch->failedJobIds === []) {
                $updates['last_error'] = null;
            }

            $account->update($updates);

            if ($batch->failedJobIds !== []) {
                return;
            }

            $afterRetry = $this->mailboxHistoryImport->pullAwaitingRetrySuccessNotice($batchId);

            if ($afterRetry) {
                $this->notifyImportComplete($user, $account, $batchId, afterRetry: true);

                return;
            }

            if ($user->notifications()
                ->where('type', MailboxHistoryImportCompletedNotification::class)
                ->where('data->viewData->batch_id', $batchId)
                ->exists()) {
                return;
            }

            $this->notifyImportComplete($user, $account, $batchId, afterRetry: false);
        });
    }

    private function notifyImportComplete(User $user, ConnectedAccount $account, string $batchId, bool $afterRetry): void
    {
        $notification = new MailboxHistoryImportCompletedNotification($account, $batchId, $afterRetry);
        $user->notifyNow($notification, ['database']);

        dispatch(new SendQueuedNotifications(collect([$user]), $notification, ['mail'])->afterCommit());
    }
}
