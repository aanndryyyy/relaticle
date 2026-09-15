<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\EnsureWorkspaceInboundAddressAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\ForwardingAddressSettingsPage;
use Relaticle\EmailIntegration\Models\UserForwardingSettings;
use Relaticle\EmailIntegration\Models\WorkspaceInboundAddress;

mutates(
    EnsureWorkspaceInboundAddressAction::class,
    EmailAccountsPage::class,
    ForwardingAddressSettingsPage::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);

    config()->set('inbound-email.domain', 'inbound.relaticle.test');
});

it('replaces a legacy opaque forwarding address with the workspace slug on ensure', function (): void {
    $legacyLocal = 'w_'.strtolower($this->workspace->getKey()).'_'.str_repeat('a', 16);

    WorkspaceInboundAddress::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'local_part' => $legacyLocal,
        'email' => $legacyLocal.'@inbound.relaticle.test',
        'is_active' => true,
    ]);

    $address = resolve(EnsureWorkspaceInboundAddressAction::class)->execute($this->workspace);

    expect($address->fullAddress())->toBe($this->workspace->slug.'@inbound.relaticle.test')
        ->and(WorkspaceInboundAddress::query()->where('local_part', $legacyLocal)->value('is_active'))->toBeFalse();
});

it('creates a workspace inbound address when ensuring forwarding', function (): void {
    $address = resolve(EnsureWorkspaceInboundAddressAction::class)->execute($this->workspace);

    expect($address->fullAddress())->toBe($this->workspace->slug.'@inbound.relaticle.test')
        ->and($address->local_part)->toBe($this->workspace->slug)
        ->and($address->workspace_id)->toBe($this->workspace->getKey());
});

it('shows the forwarding address on the accounts page', function (): void {
    $address = resolve(EnsureWorkspaceInboundAddressAction::class)->execute($this->workspace);

    livewire(EmailAccountsPage::class)
        ->assertSee($address->fullAddress())
        ->assertSee(__('filament/pages/email-accounts.in_sync'));
});

it('saves forwarding visibility settings from the forwarding settings page', function (): void {
    $address = resolve(EnsureWorkspaceInboundAddressAction::class)->execute($this->workspace);

    livewire(ForwardingAddressSettingsPage::class)
        ->fillForm([
            'sharing_tier' => EmailPrivacyTier::FULL->value,
            'full_access_user_ids' => [],
        ])
        ->callAction('save')
        ->assertHasNoActionErrors();

    $settings = UserForwardingSettings::query()
        ->where('user_id', $this->user->getKey())
        ->where('workspace_id', $this->workspace->getKey())
        ->first();

    expect($settings)->not->toBeNull()
        ->and($settings->sharing_tier)->toBe(EmailPrivacyTier::FULL);

    livewire(ForwardingAddressSettingsPage::class)
        ->assertSee($address->fullAddress());
});
