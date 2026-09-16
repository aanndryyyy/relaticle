<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

mutates(EmailAccountsPage::class, MailboxHistoryImportService::class);

it('retries failed imports from the accounts page callout', function (string $theme): void {
    Queue::fake();
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => 'history-done',
    ]));
    $batch = resolve(MailboxHistoryImportService::class)->startBatch($account);
    $account->update(['history_import_batch_id' => $batch->id]);
    $batch->add([new StoreEmailJob($account, 'missing-message')]);
    $batch->recordFailedJob('failed-job', new RuntimeException('Provider unavailable'));

    Artisan::spy();

    $this->visit('/app/login')->{$theme}()
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->navigate("/app/{$workspace->slug}/email-settings/accounts")
        ->assertSee(__('filament/pages/email-accounts.history_import_failure.badge'))
        ->assertSee(__('filament/pages/email-accounts.actions.retry_failed_import.label'))
        ->click(__('filament/pages/email-accounts.actions.retry_failed_import.label'))
        ->assertSee(__('filament/pages/email-accounts.notifications.retry_failed_import_queued.title'))
        ->assertNoJavaScriptErrors();

    Artisan::shouldHaveReceived('call')->with('queue:retry', ['id' => 'failed-job'])->once();
})->with(['inLightMode', 'inDarkMode']);
