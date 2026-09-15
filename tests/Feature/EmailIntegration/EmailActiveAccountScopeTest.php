<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $this->email = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
    ]);
});

it('shows emails while the connected account is active', function (): void {
    expect(Email::query()->whereKey($this->email->id)->exists())->toBeTrue();
});

it('shows forwarded inbound mail that has no connected account', function (): void {
    $forwarded = Email::factory()->inbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => null,
        'creation_source' => EmailCreationSource::BCC_INBOUND,
    ]);

    expect(Email::query()->whereKey($forwarded->id)->exists())->toBeTrue();
});

it('hides emails once the connected account is disconnected', function (): void {
    $this->account->delete();

    expect(Email::query()->whereKey($this->email->id)->exists())->toBeFalse();
});

it('still exposes the emails when the scope is removed for audit views', function (): void {
    $this->account->delete();

    expect(
        Email::query()
            ->withoutGlobalScope(ActiveAccountScope::class)
            ->whereKey($this->email->id)
            ->exists()
    )->toBeTrue();
});
