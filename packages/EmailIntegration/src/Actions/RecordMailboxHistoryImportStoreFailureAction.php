<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class RecordMailboxHistoryImportStoreFailureAction
{
    public function execute(ConnectedAccount $account, string $historyImportBatchId, string $message): void
    {
        if ($account->history_import_batch_id !== $historyImportBatchId) {
            return;
        }

        $account->update([
            'last_error' => Str::limit(trim($message), 2000),
        ]);
    }
}
