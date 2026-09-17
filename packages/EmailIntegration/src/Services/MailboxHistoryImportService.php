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
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class MailboxHistoryImportService
{
    private const string AWAITING_RETRY_SUCCESS_NOTICE_PREFIX = 'email-integration:history-import-awaiting-retry-success-notice:';

    private const string FAILURE_GENERATION_PREFIX = 'email-integration:history-import-failure-generation:';

    private const string CALENDAR_FAILURES_PREFIX = 'email-integration:history-import-calendar-failures:';

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

    public function failureGeneration(string $batchId): int
    {
        return (int) Cache::get(self::FAILURE_GENERATION_PREFIX.$batchId, 0);
    }

    public function recordFailureGeneration(string $batchId): int
    {
        $key = self::FAILURE_GENERATION_PREFIX.$batchId;

        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->addMonth());

            return 1;
        }

        return (int) Cache::increment($key);
    }

    public function recordCalendarFailures(string $accountId, int $count): void
    {
        Cache::put(self::CALENDAR_FAILURES_PREFIX.$accountId, max(0, $count), now()->addWeek());
    }

    public function calendarFailureCount(string $accountId): int
    {
        return max(0, (int) Cache::get(self::CALENDAR_FAILURES_PREFIX.$accountId, 0));
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

        $batch = Bus::findBatch($account->history_import_batch_id);

        if ($batch instanceof Batch && $batch->totalJobs > 0 && ! $this->batchIsComplete($batch)) {
            return true;
        }

        if ($account->sync_cursor !== null) {
            return false;
        }

        return $account->status === EmailAccountStatus::ACTIVE
            && $batch instanceof Batch
            && ! $batch->cancelled();
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

            return $this->batchProgressPercent($batch);
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
     * Distinct unresolved batch jobs, not Laravel's cumulative failed-attempt counter.
     */
    private function batchFailedJobCount(Batch $batch): int
    {
        return count($batch->failedJobIds);
    }

    /**
     * Provider listing is done once every store job has left the queue. Failures to
     * persist a message in Relaticle do not roll this back; they surface as import issue.
     *
     * @return int<0, 100>
     */
    public function batchProgressPercent(Batch $batch): int
    {
        if ($batch->totalJobs <= 0) {
            return 0;
        }

        if ($batch->pendingJobs === 0) {
            return 100;
        }

        $percent = (int) round(($this->batchProcessedJobCount($batch) / $batch->totalJobs) * 100);

        return max(0, min(100, $percent));
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
        $complete = static function (Batch $batch) use ($accountId): void {
            resolve(CompleteMailboxHistoryImportAction::class)->execute($accountId, $batch->id);
        };

        return Bus::batch([])
            ->name("Mailbox history import: {$account->email_address}")
            ->onQueue('emails-sync')
            ->allowFailures()
            ->progress($complete)
            ->finally($complete)
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
