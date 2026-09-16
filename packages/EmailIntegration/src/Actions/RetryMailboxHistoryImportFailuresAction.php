<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\Artisan;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class RetryMailboxHistoryImportFailuresAction
{
    public function execute(ConnectedAccount $account): void
    {
        $batchId = $account->history_import_batch_id;

        if (! is_string($batchId) || $batchId === '') {
            return;
        }

        Artisan::call('queue:retry-batch', ['id' => [$batchId]]);
    }
}
