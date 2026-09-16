<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Models\User;
use App\Models\Workspace;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Relaticle\EmailIntegration\Actions\RetryMailboxHistoryImportFailuresAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

/**
 * Notifications with a compact trigger sized for the sidebar's search row.
 * Filament picks between a topbar icon button and a full-width sidebar button
 * from the configured position; neither fits beside the search field.
 */
final class AppDatabaseNotifications extends DatabaseNotifications
{
    #[On('retry-mailbox-history-import')]
    public function retryMailboxHistoryImport(string $accountId, string $batchId): void
    {
        $user = $this->getUser();
        $workspace = filament()->getTenant();
        abort_unless($user instanceof User && $workspace instanceof Workspace, 403);

        $account = ConnectedAccount::query()->ownedBy($user, $workspace)->findOrFail($accountId);
        $retried = resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account, $batchId);

        Notification::make()
            ->title($retried
                ? __('filament/pages/email-accounts.notifications.retry_failed_import_queued.title')
                : __('filament/notifications/mailbox-import-complete.failures.unavailable'))
            ->status($retried ? 'success' : 'warning')
            ->send();
    }

    public function getTrigger(): View
    {
        return view('filament.app.notifications-trigger');
    }
}
