<?php

declare(strict_types=1);

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\EmailSyncDebugStoreFailure;

mutates(EmailSyncDebugStoreFailure::class);

function debugStoreFailureAccount(): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
}

it('ignores debug fail settings outside the local environment', function (): void {
    config()->set('email-integration.sync.debug_fail_first_n', 5);

    $debug = resolve(EmailSyncDebugStoreFailure::class);

    expect(fn (): null => $debug->failJobIfConfigured(debugStoreFailureAccount(), 'msg-1') ?? null)
        ->not->toThrow(RuntimeException::class);
});

it('fails the first n distinct message ids and keeps failing them on retry', function (): void {
    config()->set('email-integration.sync.debug_fail_first_n', 2);

    app()->detectEnvironment(fn (): string => 'local');

    $account = debugStoreFailureAccount();
    $debug = resolve(EmailSyncDebugStoreFailure::class);

    expect(fn () => $debug->failJobIfConfigured($account, 'msg-1'))->toThrow(RuntimeException::class)
        ->and(fn () => $debug->failJobIfConfigured($account, 'msg-1'))->toThrow(RuntimeException::class)
        ->and(fn () => $debug->failJobIfConfigured($account, 'msg-2'))->toThrow(RuntimeException::class)
        ->and(fn (): null => $debug->failJobIfConfigured($account, 'msg-3') ?? null)
        ->not->toThrow(RuntimeException::class);
});

it('fails only the targeted message id when one is configured', function (): void {
    config()->set('email-integration.sync.debug_fail_message_id', 'target-msg');
    config()->set('email-integration.sync.debug_fail_first_n', 0);

    app()->detectEnvironment(fn (): string => 'local');

    $account = debugStoreFailureAccount();
    $debug = resolve(EmailSyncDebugStoreFailure::class);

    expect(fn () => $debug->failJobIfConfigured($account, 'target-msg'))->toThrow(RuntimeException::class)
        ->and(fn (): null => $debug->failJobIfConfigured($account, 'other-msg') ?? null)
        ->not->toThrow(RuntimeException::class);
});
