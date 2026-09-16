<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class MailboxHistoryImportCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ConnectedAccount $account,
        public readonly ?string $batchId = null,
        public readonly bool $afterFailedImportRetry = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lineKey = $this->afterFailedImportRetry
            ? 'filament/notifications/mailbox-import-complete.mail.retry_line'
            : 'filament/notifications/mailbox-import-complete.mail.line';

        return (new MailMessage)
            ->subject($this->afterFailedImportRetry
                ? __('filament/notifications/mailbox-import-complete.mail.retry_subject')
                : __('filament/notifications/mailbox-import-complete.mail.subject'))
            ->greeting(__('filament/notifications/mailbox-import-complete.mail.greeting', [
                'name' => $notifiable instanceof User ? $notifiable->name : '',
            ]))
            ->line(__($lineKey, [
                'email' => $this->account->email_address,
                'count' => $this->account->initial_sync_imported,
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $titleKey = $this->afterFailedImportRetry
            ? 'filament/notifications/mailbox-import-complete.retry_success.title'
            : 'filament/notifications/mailbox-import-complete.title';
        $bodyKey = $this->afterFailedImportRetry
            ? 'filament/notifications/mailbox-import-complete.retry_success.body'
            : 'filament/notifications/mailbox-import-complete.body';

        return FilamentNotification::make()
            ->viewData(['batch_id' => $this->batchId])
            ->title(__($titleKey))
            ->body(__($bodyKey, [
                'email' => $this->account->email_address,
                'count' => $this->account->initial_sync_imported,
            ]))
            ->success()
            ->icon('heroicon-o-check-circle')
            ->getDatabaseMessage();
    }
}
