<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

final readonly class RetryMailboxHistoryImportFailuresAction
{
    public function __construct(
        private MailboxHistoryImportService $mailboxHistoryImport,
    ) {}

    public function execute(User $user, ConnectedAccount $account, string $batchId): bool
    {
        abort_unless($account->user_id === $user->getKey()
            && $user->belongsToWorkspace($account->workspace), 403);

        return (bool) Cache::lock('retry-mailbox-history:'.$batchId, 60)->get(function () use ($account, $batchId): bool {
            $account->refresh();
            $batch = Bus::findBatch($batchId);

            if ($account->history_import_batch_id !== $batchId || $account->sync_cursor === null
                || ! $batch instanceof Batch || $batch->cancelled()) {
                return false;
            }

            $failedJobUuids = $this->resolveFailedJobUuids($batch, $batchId);

            if ($failedJobUuids === []) {
                return false;
            }

            /** @var list<string> $retryableUuids */
            $retryableUuids = DB::table('failed_jobs')
                ->whereIn('uuid', $failedJobUuids)
                ->pluck('uuid')
                ->map(static fn (mixed $uuid): string => (string) $uuid)
                ->values()
                ->all();

            if ($retryableUuids === []) {
                return false;
            }

            foreach ($retryableUuids as $failedJobUuid) {
                Artisan::call('queue:retry', ['id' => $failedJobUuid]);
            }

            $remainingUuids = DB::table('failed_jobs')
                ->whereIn('uuid', $retryableUuids)
                ->pluck('uuid')
                ->map(static fn (mixed $uuid): string => (string) $uuid)
                ->all();

            if (array_diff($retryableUuids, $remainingUuids) === []) {
                return false;
            }

            $account->update(['last_error' => null]);

            $this->mailboxHistoryImport->markAwaitingRetrySuccessNotice($batchId);

            return true;
        });
    }

    /**
     * @return list<string>
     */
    private function resolveFailedJobUuids(Batch $batch, string $batchId): array
    {
        if ($batch->failedJobIds !== []) {
            return array_values($batch->failedJobIds);
        }

        $storeJobName = class_basename(StoreEmailJob::class);
        $uuids = [];

        foreach (DB::table('failed_jobs')->where('queue', 'emails-sync')->get(['uuid', 'payload']) as $row) {
            $payload = json_decode((string) $row->payload, true);

            if (! is_array($payload)) {
                continue;
            }

            $displayName = $payload['displayName'] ?? '';

            if (! is_string($displayName) || ! str_contains($displayName, $storeJobName)) {
                continue;
            }

            $command = $payload['data']['command'] ?? '';

            if (! is_string($command) || ! str_contains($command, $batchId)) {
                continue;
            }

            $uuids[] = (string) $row->uuid;
        }

        return array_values(array_unique($uuids));
    }
}
