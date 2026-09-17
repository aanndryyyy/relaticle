<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

mutates(EmailAccountsPage::class, MailboxHistoryImportService::class);

it('retries failed imports from the accounts page callout', function (string $theme): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);
    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 1,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-job']),
        'finished_at' => now()->getTimestamp(),
    ]);
    insertHistoryImportFailedJob($account, $batchId, 'failed-job');

    Artisan::partialMock()
        ->shouldReceive('call')
        ->once()
        ->with('queue:retry', ['id' => 'failed-job'])
        ->andReturnUsing(function (): int {
            DB::table('failed_jobs')->where('uuid', 'failed-job')->delete();

            return 0;
        });

    visit('/app/login')->{$theme}()
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/email-settings/accounts")
        ->waitForText(__('filament/pages/email-accounts.history_import_failure.badge'))
        ->assertSee(__('filament/pages/email-accounts.actions.retry_failed_import.label'))
        ->click(__('filament/pages/email-accounts.actions.retry_failed_import.label'))
        ->waitForText(__('filament/pages/email-accounts.notifications.retry_failed_import_queued.title'))
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
