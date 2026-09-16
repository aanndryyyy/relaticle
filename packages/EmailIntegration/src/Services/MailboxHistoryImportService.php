<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Data\MailboxHistoryImportSummary;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class MailboxHistoryImportService
{
    private const string AWAITING_RETRY_SUCCESS_NOTICE_PREFIX = 'email-integration:history-import-awaiting-retry-success-notice:';

    public function markAwaitingRetrySuccessNotice(string $batchId): void
    {
        Cache::put(self::AWAITING_RETRY_SUCCESS_NOTICE_PREFIX.$batchId, true, now()->addWeek());
    }

    public function pullAwaitingRetrySuccessNotice(string $batchId): bool
    {
        return (bool) Cache::pull(self::AWAITING_RETRY_SUCCESS_NOTICE_PREFIX.$batchId);
    }

    public function hasAwaitingRetrySuccessNotice(string $batchId): bool
    {
        return Cache::has(self::AWAITING_RETRY_SUCCESS_NOTICE_PREFIX.$batchId);
    }

    public function lockKey(ConnectedAccount $account): string
    {
        return 'email-history-import:'.$account->getKey();
    }

    public function isRunning(ConnectedAccount $account): bool
    {
        if (! $account->hasEmail() || blank($account->history_import_batch_id)) {
            return false;
        }

        if ($account->sync_cursor === null) {
            return true;
        }

        $batch = Bus::findBatch($account->history_import_batch_id);

        if (! $batch instanceof Batch) {
            return false;
        }

        if ($batch->totalJobs === 0) {
            return $account->sync_cursor === null;
        }

        return ! $this->batchIsComplete($batch);
    }

    public function summary(ConnectedAccount $account): ?MailboxHistoryImportSummary
    {
        if (blank($account->history_import_batch_id)) {
            return null;
        }

        $batch = Bus::findBatch($account->history_import_batch_id);

        if (! $batch instanceof Batch) {
            return null;
        }

        $pending = $batch->pendingJobs;
        $failed = $this->batchFailedJobCount($batch);
        $total = $batch->totalJobs;
        $successful = max(0, $total - $pending - $failed);

        return new MailboxHistoryImportSummary(
            totalJobs: $total,
            successfulJobs: $successful,
            failedJobs: $failed,
            finished: $this->batchIsComplete($batch),
        );
    }

    public function batchIsComplete(Batch $batch): bool
    {
        if ($batch->finished()) {
            return true;
        }

        if ($batch->totalJobs === 0) {
            return false;
        }

        if ($batch->pendingJobs === 0) {
            return true;
        }

        return ($batch->pendingJobs - $batch->failedJobs) === 0;
    }

    public function processedJobCount(ConnectedAccount $account): int
    {
        $batch = $this->historyImportBatch($account);

        if ($batch instanceof Batch && $batch->totalJobs > 0) {
            return $this->batchProcessedJobCount($batch);
        }

        return $account->initial_sync_imported;
    }

    public function totalJobCount(ConnectedAccount $account): int
    {
        $batch = $this->historyImportBatch($account);

        if ($batch instanceof Batch && $batch->totalJobs > 0) {
            return $batch->totalJobs;
        }

        if (filled($account->history_import_batch_id)) {
            return 0;
        }

        $estimated = $account->initial_sync_estimated;

        return is_int($estimated) && $estimated > 0 ? $estimated : max(1, $account->initial_sync_imported);
    }

    public function progressPercent(ConnectedAccount $account): int
    {
        $batch = $this->historyImportBatch($account);

        if ($batch instanceof Batch) {
            if ($batch->totalJobs === 0) {
                return $account->sync_cursor !== null ? 100 : 0;
            }

            $percent = $this->batchProgressPercent($batch);

            if ($account->sync_cursor !== null && ! $this->batchIsComplete($batch)) {
                return 100;
            }

            return $percent;
        }

        if ($account->sync_cursor !== null) {
            return 100;
        }

        if (filled($account->history_import_batch_id)) {
            return 0;
        }

        $total = $this->totalJobCount($account);

        if ($total <= 0) {
            return 0;
        }

        return min(100, (int) round(($account->initial_sync_imported / $total) * 100));
    }

    /**
     * Jobs that have left the queue (success or permanent failure), not still pending.
     */
    public function batchProcessedJobCount(Batch $batch): int
    {
        return max(0, $batch->totalJobs - $batch->pendingJobs);
    }

    /**
     * Laravel's failed_jobs counter increments on every failed attempt (including retries).
     * failed_job_ids lists each batch job once, which matches messages the user must retry.
     */
    private function batchFailedJobCount(Batch $batch): int
    {
        $failedJobIds = $batch->failedJobIds;

        if ($failedJobIds !== []) {
            return count($failedJobIds);
        }

        return $batch->failedJobs;
    }

    /**
     * @return int<0, 100>
     */
    public function batchProgressPercent(Batch $batch): int
    {
        if ($batch->totalJobs <= 0) {
            return 0;
        }

        return min(100, (int) round(($this->batchProcessedJobCount($batch) / $batch->totalJobs) * 100));
    }

    private function historyImportBatch(ConnectedAccount $account): ?Batch
    {
        if (blank($account->history_import_batch_id)) {
            return null;
        }

        $batch = Bus::findBatch($account->history_import_batch_id);

        return $batch instanceof Batch ? $batch : null;
    }

    public function startBatch(ConnectedAccount $account): Batch
    {
        $accountId = (string) $account->getKey();

        return Bus::batch([])
            ->name("Mailbox history import: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($accountId): void {
                resolve(CompleteMailboxHistoryImportAction::class)->execute($accountId, $batch->id);
            })
            ->dispatch();
    }

    /**
     * @param  list<string>  $messageIds
     */
    public function addStoreJobs(ConnectedAccount $account, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $batchId = $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '') {
            return;
        }

        $batch = Bus::findBatch($batchId);

        if (! $batch instanceof Batch) {
            return;
        }

        $jobs = collect(array_values(array_unique($messageIds)))
            ->chunk(Config::integer('email-integration.sync.batch_size', 50))
            ->flatMap(fn (Collection $chunk): array => $chunk
                ->map(fn (string $id): StoreEmailJob => new StoreEmailJob($account, $id))
                ->all())
            ->all();

        $batch->add($jobs);
    }
}
