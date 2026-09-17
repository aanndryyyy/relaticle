<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class MailboxHistoryImportCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ConnectedAccount $account,
        public readonly ?string $batchId = null,
        public readonly bool $afterFailedImportRetry = false,
        public readonly int $failedEmailCount = 0,
        public readonly int $failedCalendarCount = 0,
        public readonly bool $calendarDidNotFinish = false,
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
        $mail = (new MailMessage)
            ->subject($this->mailSubject())
            ->greeting(__('filament/notifications/mailbox-import-complete.mail.greeting', [
                'name' => $notifiable instanceof User ? $notifiable->name : '',
            ]))
            ->line(__($this->mailLineKey(), [
                'email' => $this->account->email_address,
                'imported' => $this->importedSummary(),
                'failures' => $this->failureSummary(),
            ]));

        $url = $this->emailAccountsUrl();

        if ($this->hasIssues() && $url !== null) {
            $mail->action(
                __('filament/notifications/mailbox-import-complete.actions.review_and_retry'),
                $url,
            );
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->viewData([
                'batch_id' => $this->batchId,
                'kind' => $this->kind(),
            ])
            ->title(__($this->titleKey()))
            ->body(__($this->bodyKey(), [
                'email' => $this->account->email_address,
                'imported' => $this->importedSummary(),
                'failures' => $this->failureSummary(),
            ]))
            ->icon($this->hasIssues() ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle');

        if ($this->hasIssues()) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $url = $this->emailAccountsUrl();

        if ($this->hasIssues() && $url !== null) {
            $notification->actions([
                Action::make('reviewAndRetry')
                    ->label(__('filament/notifications/mailbox-import-complete.actions.review_and_retry'))
                    ->link()
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    public function hasIssues(): bool
    {
        return ! $this->afterFailedImportRetry
            && ($this->failedEmailCount > 0 || $this->failedCalendarCount > 0 || $this->calendarDidNotFinish);
    }

    private function kind(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'retry_success';
        }

        return $this->hasIssues() ? 'partial' : 'complete';
    }

    private function titleKey(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'filament/notifications/mailbox-import-complete.retry_success.title';
        }

        return $this->hasIssues()
            ? 'filament/notifications/mailbox-import-complete.title_with_issues'
            : 'filament/notifications/mailbox-import-complete.title';
    }

    private function bodyKey(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'filament/notifications/mailbox-import-complete.retry_success.body';
        }

        return $this->hasIssues()
            ? 'filament/notifications/mailbox-import-complete.body_with_issues'
            : 'filament/notifications/mailbox-import-complete.body';
    }

    private function mailSubject(): string
    {
        if ($this->afterFailedImportRetry) {
            return __('filament/notifications/mailbox-import-complete.mail.retry_subject');
        }

        return $this->hasIssues()
            ? __('filament/notifications/mailbox-import-complete.mail.subject_with_issues')
            : __('filament/notifications/mailbox-import-complete.mail.subject');
    }

    private function mailLineKey(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'filament/notifications/mailbox-import-complete.mail.retry_line';
        }

        return $this->hasIssues()
            ? 'filament/notifications/mailbox-import-complete.mail.line_with_issues'
            : 'filament/notifications/mailbox-import-complete.mail.line';
    }

    private function importedSummary(): string
    {
        $emails = trans_choice('filament/notifications/mailbox-import-complete.imported_emails', $this->emailCount(), [
            'count' => $this->emailCount(),
        ]);

        if (! $this->account->hasCalendar()) {
            return __('filament/notifications/mailbox-import-complete.imported_without_calendar', [
                'emails' => $emails,
            ]);
        }

        return __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
            'emails' => $emails,
            'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', $this->calendarCount(), [
                'count' => $this->calendarCount(),
            ]),
        ]);
    }

    private function failureSummary(): string
    {
        $parts = [];

        if ($this->failedEmailCount > 0) {
            $parts[] = trans_choice('filament/notifications/mailbox-import-complete.failed_messages', $this->failedEmailCount, [
                'count' => $this->failedEmailCount,
            ]);
        }

        if ($this->failedCalendarCount > 0) {
            $parts[] = trans_choice('filament/notifications/mailbox-import-complete.failed_calendar_events', $this->failedCalendarCount, [
                'count' => $this->failedCalendarCount,
            ]);
        } elseif ($this->calendarDidNotFinish) {
            $parts[] = __('filament/notifications/mailbox-import-complete.calendar_did_not_finish');
        }

        return implode(' ', $parts);
    }

    private function emailCount(): int
    {
        return $this->account->emails()->count();
    }

    private function calendarCount(): int
    {
        return $this->account->meetings()->count();
    }

    private function emailAccountsUrl(): ?string
    {
        $workspace = $this->account->workspace;

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return EmailAccountsPage::getUrl(panel: 'app', tenant: $workspace);
    }
}
