<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

            $retried = 0;

            foreach ($failedJobUuids as $failedJobUuid) {
                if (Artisan::call('queue:retry', ['id' => $failedJobUuid]) === 0) {
                    $retried++;
                }
            }

            if ($retried === 0) {
                return false;
            }

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

        $uuids = DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$batchId.'%')
            ->where('payload', 'like', '%StoreEmailJob%')
            ->pluck('uuid');

        return array_values(array_map(
            static fn (mixed $uuid): string => (string) $uuid,
            $uuids->all(),
        ));
    }
}
