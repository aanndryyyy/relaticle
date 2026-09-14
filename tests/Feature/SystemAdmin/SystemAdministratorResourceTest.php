<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\CreateSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\EditSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\ListSystemAdministrators;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\SystemAdministratorResource;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Rules\KeepsALastSuperAdministrator;

mutates(SystemAdministratorResource::class, KeepsALastSuperAdministrator::class);

beforeEach(function (): void {
    $this->actingAs(
        SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]),
        'sysadmin',
    );
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('renders the create page', function (): void {
    livewire(CreateSystemAdministrator::class)->assertOk();
});

it('creates an administrator who can authenticate with the given password', function (): void {
    livewire(CreateSystemAdministrator::class)
        ->fillForm([
            'name' => 'New Admin',
            'email' => 'new-admin@example.test',
            'role' => SystemAdministratorRole::SuperAdministrator->value,
            'password' => 'creation-password',
            'password_confirmation' => 'creation-password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = SystemAdministrator::query()->where('email', 'new-admin@example.test')->firstOrFail();

    expect($created->password)->not->toBe('creation-password')
        ->and(Auth::guard('sysadmin')->attempt([
            'email' => 'new-admin@example.test',
            'password' => 'creation-password',
        ]))->toBeTrue();
});

it('changes an administrator password', function (): void {
    $target = SystemAdministrator::factory()->create();

    livewire(EditSystemAdministrator::class, ['record' => $target->getKey()])
        ->fillForm([
            'password' => 'rotated-password',
            'password_confirmation' => 'rotated-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Auth::guard('sysadmin')->attempt([
        'email' => $target->email,
        'password' => 'rotated-password',
    ]))->toBeTrue();
});

it('keeps the existing password when the field is left blank', function (): void {
    $target = SystemAdministrator::factory()->create(['password' => 'original-password']);

    livewire(EditSystemAdministrator::class, ['record' => $target->getKey()])
        ->fillForm(['name' => 'Renamed Admin'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->name)->toBe('Renamed Admin')
        ->and(Auth::guard('sysadmin')->attempt([
            'email' => $target->email,
            'password' => 'original-password',
        ]))->toBeTrue();
});

it('refuses to give the last Super Administrator another role', function (): void {
    $lastSuperAdministrator = Auth::guard('sysadmin')->user();
    SystemAdministrator::factory()->administrator()->create();

    livewire(EditSystemAdministrator::class, ['record' => $lastSuperAdministrator->getKey()])
        ->fillForm(['role' => SystemAdministratorRole::Administrator->value])
        ->call('save')
        ->assertHasFormErrors(['role']);

    expect($lastSuperAdministrator->refresh()->role)->toBe(SystemAdministratorRole::SuperAdministrator);
});

it('gives a Super Administrator another role once a second one exists', function (): void {
    $target = SystemAdministrator::factory()->create();

    livewire(EditSystemAdministrator::class, ['record' => $target->getKey()])
        ->fillForm(['role' => SystemAdministratorRole::Administrator->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->refresh()->role)->toBe(SystemAdministratorRole::Administrator);
});

it('renders each role with its own label and badge colour', function (): void {
    $superAdministrator = SystemAdministrator::factory()->create();
    $administrator = SystemAdministrator::factory()->administrator()->create();

    livewire(ListSystemAdministrators::class)
        ->assertTableColumnFormattedStateSet('role', 'Super Administrator', $superAdministrator)
        ->assertTableColumnFormattedStateSet('role', 'Administrator', $administrator)
        ->assertTableColumnStateSet('role', SystemAdministratorRole::Administrator, $administrator);

    expect(SystemAdministratorRole::SuperAdministrator->getColor())->toBe('danger')
        ->and(SystemAdministratorRole::Administrator->getColor())->toBe('warning');
});
