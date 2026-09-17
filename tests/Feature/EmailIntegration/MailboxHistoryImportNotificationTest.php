<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Actions\RetryMailboxHistoryImportFailuresAction;
use Relaticle\EmailIntegration\Actions\StartMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

mutates(
    CompleteMailboxHistoryImportAction::class,
    InitialCalendarSyncJob::class,
    InitialEmailSyncJob::class,
    MailboxHistoryImportCompletedNotification::class,
    MailboxHistoryImportService::class,
    RetryMailboxHistoryImportFailuresAction::class,
    StartMailboxHistoryImportAction::class,
    StoreEmailJob::class,
);

function mailboxImportNotificationUser(): User
{
    return User::factory()->withWorkspace()->create();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mailboxImportNotificationAccount(User $user, array $attributes = []): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->current_workspace_id,
        'sync_inbox' => true,
        ...$attributes,
    ]));
}

function mailboxImportFetchedEmail(string $providerMessageId): FetchedEmailData
{
    return new FetchedEmailData(
        providerMessageId: $providerMessageId,
        rfcMessageId: "<{$providerMessageId}@example.com>",
        threadId: "thread-{$providerMessageId}",
        inReplyTo: null,
        subject: 'Quarterly review',
        snippet: 'Quarterly review',
        sentAt: now(),
        direction: EmailDirection::INBOUND,
        folder: EmailFolder::Inbox,
        hasAttachments: false,
        isRead: true,
        bodyText: 'Quarterly review',
        bodyHtml: '<p>Quarterly review</p>',
        participants: [
            ['email_address' => 'sender@example.com', 'name' => 'Sender', 'role' => 'from'],
        ],
        attachments: [],
    );
}

function mailboxImportCalendarEvent(string $providerEventId): CalendarEventData
{
    return new CalendarEventData(
        providerEventId: $providerEventId,
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Pipeline review',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );
}

/**
 * @param  list<string>  $messageIds
 */
function bindMailboxImportMailService(array $messageIds, callable $fetchMessage): void
{
    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')
        ->andReturn(new MailBackfillPage(
            messageIds: collect($messageIds),
            nextPageToken: null,
            cursor: 'history-done',
        ));
    $fetchMessage($service);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);
}

/**
 * @param  list<CalendarEventData>  $events
 */
function bindMailboxImportCalendarService(array $events = [], ?Throwable $initialSyncException = null): void
{
    $service = Mockery::mock(CalendarServiceInterface::class);

    if ($initialSyncException instanceof Throwable) {
        $service->shouldReceive('initialSync')->andThrow($initialSyncException);
    } else {
        $service->shouldReceive('initialSync')->andReturn(new CalendarSyncResult(
            events: $events,
            nextSyncToken: 'calendar-done',
        ));
    }

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(CalendarServiceFactoryInterface::class, $factory);
}

function workMailboxImportQueueOnce(): void
{
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'emails-sync',
        '--once' => true,
        '--sleep' => 0,
        '--stop-when-empty' => true,
    ]);

    test()->travel(20)->minutes();
}

function workMailboxImportQueueUntilEmpty(int $maxJobs = 50): void
{
    $processed = 0;

    while ($processed < $maxJobs && DB::table('jobs')->where('queue', 'emails-sync')->exists()) {
        workMailboxImportQueueOnce();
        $processed++;
    }
}

function assertMailboxImportMail(User $user, MailboxHistoryImportCompletedNotification $notification, string $subject, string $line): void
{
    $mail = $notification->toMail($user);

    expect($mail->subject)->toBe($subject)
        ->and((string) $mail->render())->toContain($line)
        ->and($mail)->toBeInstanceOf(MailMessage::class);
}

it('notifies with persisted email counts when the import succeeds', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user);

    bindMailboxImportMailService(['ok-1', 'ok-2'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
        $service->shouldReceive('fetchMessage')->with('ok-2')->andReturn(mailboxImportFetchedEmail('ok-2'));
    });

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty();

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();
    $imported = __('filament/notifications/mailbox-import-complete.imported_without_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 2, ['count' => 2]),
    ]);

    expect($account->fresh()->emails()->count())->toBe(2)
        ->and($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title'))
        ->and($notification->data['status'])->toBe('success')
        ->and($notification->data['viewData']['kind'])->toBe('complete')
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]))
        ->and($notification->data['body'])->not->toContain('calendar')
        ->and($notification->data['actions'] ?? [])->toBe([]);

    assertMailboxImportMail(
        $user,
        new MailboxHistoryImportCompletedNotification($account->fresh(), $account->history_import_batch_id),
        __('filament/notifications/mailbox-import-complete.mail.subject'),
        __('filament/notifications/mailbox-import-complete.mail.line', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]),
    );
});

it('notifies with issues when some store jobs permanently fail', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
        $service->shouldReceive('fetchMessage')->with('fail-1')->andThrow(new RuntimeException('Provider unavailable'));
    });

    $ok = new StoreEmailJob($account, 'ok-1');
    $ok->tries = 1;
    $fail = new StoreEmailJob($account, 'fail-1');
    $fail->tries = 1;
    Bus::findBatch($batchId)->add([$ok, $fail]);
    workMailboxImportQueueUntilEmpty();

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();
    $imported = __('filament/notifications/mailbox-import-complete.imported_without_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 1, ['count' => 1]),
    ]);
    $failures = trans_choice('filament/notifications/mailbox-import-complete.failed_messages', 1, ['count' => 1]);

    expect($account->emails()->count())->toBe(1)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title_with_issues'))
        ->and($notification->data['status'])->toBe('warning')
        ->and($notification->data['viewData']['kind'])->toBe('partial')
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body_with_issues', [
            'imported' => $imported,
            'failures' => $failures,
            'email' => $account->email_address,
        ]))
        ->and(collect($notification->data['actions'] ?? [])->pluck('name')->all())->toContain('reviewAndRetry');

    Livewire::test(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.history_import_failure.badge'))
        ->assertActionVisible(TestAction::make('retryFailedImport')->arguments(['account_id' => $account->getKey()]));

    $mailNotification = new MailboxHistoryImportCompletedNotification($account->fresh(), $batchId, failedEmailCount: 1);
    assertMailboxImportMail(
        $user,
        $mailNotification,
        __('filament/notifications/mailbox-import-complete.mail.subject_with_issues'),
        __('filament/notifications/mailbox-import-complete.mail.line_with_issues', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => $failures,
        ]),
    );
    expect($mailNotification->toMail($user)->actionText)->toBe(__('filament/notifications/mailbox-import-complete.actions.review_and_retry'));
});

it('waits for calendar store work before sending the import summary', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService([mailboxImportCalendarEvent('evt-1')]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    $sawEmailDoneWhileCalendarPending = false;

    while (DB::table('jobs')->where('queue', 'emails-sync')->exists()) {
        workMailboxImportQueueOnce();
        $account->refresh();

        if ($account->sync_cursor !== null
            && $account->emails()->count() === 1
            && $account->calendar_sync_cursor === null
            && $user->notifications()->count() === 0) {
            $sawEmailDoneWhileCalendarPending = true;
        }
    }

    $imported = __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 1, ['count' => 1]),
        'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', 1, ['count' => 1]),
    ]);

    expect($sawEmailDoneWhileCalendarPending)->toBeTrue()
        ->and($account->fresh()->calendar_sync_cursor)->toBe('calendar-done')
        ->and($account->meetings()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title'))
        ->and($user->notifications()->sole()->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body', [
            'imported' => $imported,
            'email' => $account->email_address,
            'failures' => '',
        ]));
});

it('reports calendar failures in the import summary', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    bindMailboxImportMailService(['ok-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
    });
    bindMailboxImportCalendarService(initialSyncException: new RuntimeException('Calendar API unavailable'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty();

    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();
    $imported = __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 1, ['count' => 1]),
        'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', 0, ['count' => 0]),
    ]);

    expect($account->emails()->count())->toBe(1)
        ->and($account->meetings()->count())->toBe(0)
        ->and($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title_with_issues'))
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body_with_issues', [
            'imported' => $imported,
            'failures' => __('filament/notifications/mailbox-import-complete.calendar_did_not_finish'),
            'email' => $account->email_address,
        ]));
});

it('does not send a retry-success notice while a retried job still fails', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->twice()->andThrow(new RuntimeException('Provider unavailable'));
    });

    $job = new StoreEmailJob($account, 'fail-1');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    workMailboxImportQueueOnce();

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');

    Livewire::test(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.history_import_failure.badge'))
        ->callAction(TestAction::make('retryFailedImport')->arguments(['account_id' => $account->getKey()]));

    workMailboxImportQueueOnce();

    expect($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');
});

it('sends one recovery notice after every failed import job succeeds', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->once()->ordered()->andThrow(new RuntimeException('Provider unavailable'));
        $service->shouldReceive('fetchMessage')->once()->ordered()->andReturn(mailboxImportFetchedEmail('fail-1'));
    });

    $job = new StoreEmailJob($account, 'fail-1');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    workMailboxImportQueueOnce();

    Livewire::test(EmailAccountsPage::class)
        ->callAction(TestAction::make('retryFailedImport')->arguments(['account_id' => $account->getKey()]));

    workMailboxImportQueueOnce();

    $notifications = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->get();

    expect($account->emails()->count())->toBe(1)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeFalse()
        ->and($notifications)->toHaveCount(2)
        ->and($notifications->pluck('data.viewData.kind')->all())->toContain('partial', 'retry_success')
        ->and($notifications->pluck('data.title')->all())->toContain(__('filament/notifications/mailbox-import-complete.retry_success.title'));

    $retryMail = (new MailboxHistoryImportCompletedNotification($account->fresh(), $batchId, afterFailedImportRetry: true))->toMail($user);
    expect($retryMail->subject)->toBe(__('filament/notifications/mailbox-import-complete.mail.retry_subject'));
});

it('counts persisted rows and ignores skipped store jobs', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, [
        'capabilities' => ['email' => true, 'calendar' => true],
    ]);

    bindMailboxImportMailService(['ok-1', 'ok-2', 'gone-1', 'fail-1'], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('ok-1')->andReturn(mailboxImportFetchedEmail('ok-1'));
        $service->shouldReceive('fetchMessage')->with('ok-2')->andReturn(mailboxImportFetchedEmail('ok-2'));
        $service->shouldReceive('fetchMessage')->with('gone-1')->andThrow(new GoogleServiceException('Requested entity was not found.', 404));
        $service->shouldReceive('fetchMessage')->with('fail-1')->andThrow(new RuntimeException('Provider unavailable'));
    });
    bindMailboxImportCalendarService([
        mailboxImportCalendarEvent('evt-1'),
        mailboxImportCalendarEvent('evt-2'),
    ]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    workMailboxImportQueueUntilEmpty();

    $imported = __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
        'emails' => trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 2, ['count' => 2]),
        'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', 2, ['count' => 2]),
    ]);
    $failures = trans_choice('filament/notifications/mailbox-import-complete.failed_messages', 1, ['count' => 1]);
    $notification = $user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->sole();

    expect($account->emails()->count())->toBe(2)
        ->and($account->meetings()->count())->toBe(2)
        ->and($notification->data['body'])->toBe(__('filament/notifications/mailbox-import-complete.body_with_issues', [
            'imported' => $imported,
            'failures' => $failures,
            'email' => $account->email_address,
        ]))
        ->and($notification->data['body'])->not->toContain(trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 3, ['count' => 3]))
        ->and($notification->data['body'])->not->toContain(trans_choice('filament/notifications/mailbox-import-complete.imported_emails', 4, ['count' => 4]));
});

it('does not send duplicate import notices from repeated completion callbacks or retry clicks', function (): void {
    config()->set('queue.default', 'database');
    $user = mailboxImportNotificationUser();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = mailboxImportNotificationAccount($user, ['sync_cursor' => 'history-done']);
    $batchId = attachHistoryImportBatch($account);

    bindMailboxImportMailService([], function (MailServiceInterface $service): void {
        $service->shouldReceive('fetchMessage')->with('fail-1')->andThrow(new RuntimeException('Provider unavailable'));
    });

    $job = new StoreEmailJob($account, 'fail-1');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    workMailboxImportQueueUntilEmpty();

    resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $batchId);
    resolve(CompleteMailboxHistoryImportAction::class)->execute((string) $account->getKey(), $batchId);

    foreach (Bus::findBatch($batchId)->options['finally'] as $callback) {
        $callback(Bus::findBatch($batchId));
    }

    expect($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1);

    Livewire::test(EmailAccountsPage::class)
        ->callAction(TestAction::make('retryFailedImport')->arguments(['account_id' => $account->getKey()]))
        ->assertNotified(__('filament/pages/email-accounts.notifications.retry_failed_import_queued.title'));

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeFalse()
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->sole()->data['viewData']['kind'])->toBe('partial');
});
