<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Actions\RetryMailboxHistoryImportFailuresAction;
use Relaticle\EmailIntegration\Actions\StartMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\EmailSyncDebugStoreFailure;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

mutates(
    ConnectedAccount::class,
    InitialEmailSyncJob::class,
    RetryMailboxHistoryImportFailuresAction::class,
    StartMailboxHistoryImportAction::class,
    MailboxHistoryImportService::class,
    StoreEmailJob::class,
);

function attachHistoryImportBatch(ConnectedAccount $account): string
{
    $service = resolve(MailboxHistoryImportService::class);
    $batch = $service->startBatch($account);
    $account->update(['history_import_batch_id' => $batch->id]);

    return $batch->id;
}

it('uses allowFailures on the history import batch', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    Bus::fake([]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->allowsFailures()
        && $batch->jobs->count() === 0);
});

it('adds store jobs to the import batch and skips messages already stored', function (): void {
    Notification::fake();
    Queue::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    attachHistoryImportBatch($account);

    Email::factory()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->user_id,
        'connected_account_id' => $account->getKey(),
        'provider_message_id' => 'M-existing',
    ]);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')
        ->once()
        ->with(null, null)
        ->andReturn(new MailBackfillPage(
            messageIds: collect(['M-existing', 'M-new']),
            nextPageToken: null,
            cursor: 'history-1',
        ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    app()->call(
        [new InitialEmailSyncJob($account, historyImportBatchId: $account->history_import_batch_id), 'handle'],
        ['mailFactory' => $factory],
    );

    $batch = Bus::findBatch((string) $account->history_import_batch_id);

    expect($batch?->totalJobs)->toBe(1);
});

it('does not cancel the import batch when a store job fails', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    $batch = Bus::batch([])->allowFailures()->dispatch();

    $job = new StoreEmailJob($account, 'msg-fail');
    $job->withBatchId($batch->id);
    $job->failed(new RuntimeException('Provider timeout'));

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE);
});

it('reports batch summary totals after the import batch finishes', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 0,
        'failed_jobs' => 2,
        'finished_at' => now()->getTimestamp(),
    ]);

    $summary = resolve(MailboxHistoryImportService::class)->summary($account->fresh());

    expect($summary?->totalJobs)->toBe(10)
        ->and($summary?->successfulJobs)->toBe(8)
        ->and($summary?->failedJobs)->toBe(2)
        ->and($summary?->finished)->toBeTrue();
});

it('treats the import batch as finished when all jobs are done but some failed', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 0,
        'failed_jobs' => 3,
        'finished_at' => null,
    ]);

    $service = resolve(MailboxHistoryImportService::class);

    expect($service->summary($account->fresh())?->finished)->toBeTrue()
        ->and($account->fresh()?->isEmailHistoryImportRunning())->toBeFalse()
        ->and($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeTrue();
});

it('treats the import batch as finished when failed jobs remain pending in job_batches', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'finished_at' => null,
    ]);

    $service = resolve(MailboxHistoryImportService::class);

    expect($service->summary($account->fresh())?->finished)->toBeTrue()
        ->and($account->fresh()?->isEmailHistoryImportRunning())->toBeFalse();
});

it('re-import history creates a new batch and resets the mailbox cursor', function (): void {
    Bus::fake([RelinkMailboxHistoryJob::class, InitialEmailSyncJob::class]);
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'done',
        'history_import_batch_id' => 'old-batch',
    ]));

    resolve(StartMailboxHistoryImportAction::class)->execute($account->fresh());

    expect($account->fresh())
        ->sync_cursor->toBeNull()
        ->and($account->fresh()?->history_import_batch_id)->not->toBe('old-batch');

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->allowsFailures());
});

it('re-import dispatches store jobs only for messages missing locally', function (): void {
    Notification::fake();
    Queue::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'done',
    ]));

    Email::factory()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->user_id,
        'connected_account_id' => $account->getKey(),
        'provider_message_id' => 'M1',
    ]);

    attachHistoryImportBatch($account);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(['M1', 'M2']),
        nextPageToken: null,
        cursor: 'history-2',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    app()->call(
        [new InitialEmailSyncJob($account, historyImportBatchId: $account->history_import_batch_id), 'handle'],
        ['mailFactory' => $factory],
    );

    expect(Bus::findBatch((string) $account->history_import_batch_id)?->totalJobs)->toBe(1);
});

it('blocks duplicate re-import history requests for the same mailbox', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => null,
    ]));

    attachHistoryImportBatch($account);

    expect(fn () => resolve(StartMailboxHistoryImportAction::class)->execute($account->fresh()))
        ->toThrow(RuntimeException::class);
});

it('blocks concurrent re-import history starts with a cache lock', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'done',
    ]));

    $lock = Cache::lock(resolve(MailboxHistoryImportService::class)->lockKey($account), 60);
    $lock->get();

    try {
        expect(fn () => resolve(StartMailboxHistoryImportAction::class)->execute($account))
            ->toThrow(RuntimeException::class);
    } finally {
        $lock->release();
    }
});

it('does not create duplicate email rows when store runs twice for the same provider message', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_inbox' => true,
        'sync_sent' => true,
    ]));

    $payload = new FetchedEmailData(
        providerMessageId: 'dup-msg',
        threadId: 'thread-1',
        rfcMessageId: '<dup@example.com>',
        inReplyTo: null,
        subject: 'Duplicate',
        snippet: 'Duplicate',
        bodyText: 'Duplicate',
        bodyHtml: '<p>Duplicate</p>',
        direction: EmailDirection::INBOUND,
        folder: EmailFolder::Inbox,
        sentAt: now(),
        isRead: true,
        hasAttachments: false,
        participants: [],
        attachments: [],
    );

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')->once()->andReturn($payload);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    $action = resolve(StoreEmailAction::class);

    $debug = resolve(EmailSyncDebugStoreFailure::class);

    (new StoreEmailJob($account, 'dup-msg'))->handle($factory, $action, $debug);
    (new StoreEmailJob($account, 'dup-msg'))->handle($factory, $action, $debug);

    expect(Email::query()->where('connected_account_id', $account->getKey())->count())->toBe(1);
});

it('registers withoutOverlapping middleware on store email jobs', function (): void {
    $account = ConnectedAccount::factory()->make();
    $job = new StoreEmailJob($account, 'msg-1');

    expect($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('finishes pagination while the import batch continues processing', function (): void {
    Notification::fake();
    Queue::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    attachHistoryImportBatch($account);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(['M1']),
        nextPageToken: null,
        cursor: 'history-1',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    app()->call(
        [new InitialEmailSyncJob($account, historyImportBatchId: $account->history_import_batch_id), 'handle'],
        ['mailFactory' => $factory],
    );

    expect($account->fresh()?->sync_cursor)->toBe('history-1')
        ->and($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()?->isEmailHistoryImportRunning())->toBeTrue();
});

it('does not surface the failure summary while store jobs are still running', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-1',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 3,
        'failed_jobs' => 1,
        'finished_at' => null,
    ]);

    expect($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeFalse()
        ->and($account->fresh()?->showsMailboxHistoryImportProgressOnAccountsPage())->toBeTrue();
});

it('uses finishing import copy instead of a percent badge while store jobs run', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 224,
        'pending_jobs' => 3,
        'failed_jobs' => 0,
        'finished_at' => null,
    ]);

    expect($account->fresh()?->isMailboxHistoryImportStoringPhase())->toBeTrue()
        ->and($account->fresh()?->showsPercentOnImportBadge())->toBeFalse()
        ->and($account->fresh()?->historyImportProcessedLabel())->toContain('221')
        ->and($account->fresh()?->historyImportProcessedLabel())->toContain('224');
});

it('does not treat an empty history batch as running after listing finishes', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
        'initial_sync_estimated' => 100,
        'initial_sync_imported' => 99,
    ]));
    attachHistoryImportBatch($account);

    $service = resolve(MailboxHistoryImportService::class);

    expect($account->fresh()?->isEmailHistoryImportRunning())->toBeFalse()
        ->and($service->progressPercent($account->fresh()))->toBe(100);
});

it('does not show a false 99 percent while listing before any store jobs exist', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'initial_sync_estimated' => 100,
        'initial_sync_imported' => 99,
    ]));
    attachHistoryImportBatch($account);

    expect(resolve(MailboxHistoryImportService::class)->progressPercent($account))->toBe(0);
});

it('bases import percent on jobs finished not on success versus failure', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'finished_at' => null,
    ]);

    $service = resolve(MailboxHistoryImportService::class);

    expect($service->processedJobCount($account->fresh()))->toBe(8)
        ->and($service->progressPercent($account->fresh()))->toBe(80);
});

it('reaches one hundred percent when no store jobs are still pending', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 0,
        'failed_jobs' => 2,
        'finished_at' => now()->getTimestamp(),
    ]);

    expect(resolve(MailboxHistoryImportService::class)->progressPercent($account->fresh()))->toBe(100);
});

it('retries only failed jobs from the history import batch', function (): void {
    Artisan::spy();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'history_import_batch_id' => 'batch-retry',
    ]));

    DB::table('job_batches')->insert([
        'id' => 'batch-retry',
        'name' => 'Mailbox history import',
        'total_jobs' => 2,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-uuid-1']),
        'options' => '[]',
        'cancelled_at' => null,
        'created_at' => now()->getTimestamp(),
        'finished_at' => now()->getTimestamp(),
    ]);

    resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($account);

    Artisan::shouldHaveReceived('call')
        ->with('queue:retry-batch', ['id' => ['batch-retry']])
        ->once();
});

it('surfaces the failure summary after the import batch finishes with failed jobs', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-1',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'finished_at' => now()->getTimestamp(),
    ]);

    expect($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($account->fresh()?->showsMailboxHistoryImportProgressOnAccountsPage())->toBeFalse();
});
