<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\PersonalAccessToken;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\Ink\Models\Tag;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Pages\Auth\EditProfile;
use Relaticle\SystemAdmin\Filament\Pages\Settings\ManageAiSettings;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\EditUser;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(SystemAdministrator::class, SystemAdministratorRole::class);

it('rejects an injected staff role on the administrator profile', function (): void {
    $administrator = SystemAdministrator::factory()->administrator()->create();
    $this->actingAs($administrator, 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    livewire(EditProfile::class)
        ->set('data.role', SystemAdministratorRole::SuperAdministrator->value)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($administrator->refresh()->role)->toBe(SystemAdministratorRole::Administrator);
});

it('denies guest and customer blog gates for classes and records in every panel context', function (?string $panel): void {
    Filament::setCurrentPanel($panel);
    $customer = User::factory()->make();

    foreach ([Post::class, Category::class, new Post, new Category] as $target) {
        foreach (['viewAny', 'view', 'create', 'update', 'restore', 'restoreAny', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny'] as $ability) {
            expect(Gate::forUser(null)->allows($ability, $target))->toBeFalse();
            expect(Gate::forUser($customer)->allows($ability, $target))->toBeFalse();
        }
    }
})->with(['outside a panel' => null, 'customer panel' => 'app', 'staff panel' => 'sysadmin']);

describe('SystemAdmin Security', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('sysadmin');
    });

    it('enforces complete authentication isolation', function () {
        $admin = SystemAdministrator::factory()->create();
        $user = User::factory()->create();

        expect($admin->canAccessPanel(Filament::getPanel('app')))->toBeFalse()
            ->and($user->canAccessPanel(Filament::getPanel('sysadmin')))->toBeFalse();

        $this->actingAs($admin, 'sysadmin');
        $this->assertAuthenticatedAs($admin, 'sysadmin');
        $this->assertGuest('web');
    });

    it('enforces role-based authorization', function () {
        $superAdmin = SystemAdministrator::factory()->create([
            'role' => SystemAdministratorRole::SuperAdministrator,
        ]);

        $otherAdmin = SystemAdministrator::factory()->create([
            'role' => SystemAdministratorRole::SuperAdministrator,
        ]);

        $this->actingAs($superAdmin, 'sysadmin');

        expect(auth('sysadmin')->user()->can('create', SystemAdministrator::class))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('viewAny', SystemAdministrator::class))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('update', $otherAdmin))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('delete', $otherAdmin))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('delete', $superAdmin))->toBeFalse();
    });

    it('redirects unauthenticated visitors to sysadmin login', function (string $route) {
        $this->get($route)->assertRedirect('/sysadmin/login');
    })->with([
        'dashboard' => '/sysadmin',
        'companies' => '/sysadmin/companies',
        'imports' => '/sysadmin/imports',
        'users' => '/sysadmin/users',
        'workspaces' => '/sysadmin/workspaces',
        'system-administrators' => '/sysadmin/system-administrators',
    ]);

    it('blocks regular app users from accessing sysadmin panel', function (string $route) {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get($route)
            ->assertRedirect('/sysadmin/login');
    })->with([
        'dashboard' => '/sysadmin',
        'companies' => '/sysadmin/companies',
        'users' => '/sysadmin/users',
    ]);

    it('denies a customer every ability the sysadmin policies answer', function (string $model) {
        $user = User::factory()->create();

        expect($user->can('viewAny', $model))->toBeFalse()
            ->and($user->can('view', $model))->toBeFalse()
            ->and($user->can('create', $model))->toBeFalse()
            ->and($user->can('update', $model))->toBeFalse()
            ->and($user->can('delete', $model))->toBeFalse();
    })->with([
        'companies' => Company::class,
        'workspaces' => Workspace::class,
        'users' => User::class,
        'posts' => Post::class,
        'tags' => Tag::class,
    ]);

    it('blocks unverified sysadmin from accessing panel routes', function () {
        $unverifiedAdmin = SystemAdministrator::factory()->unverified()->create();

        $this->actingAs($unverifiedAdmin, 'sysadmin')
            ->get('/sysadmin')
            ->assertForbidden();
    });

    it('allows verified sysadmin to access panel routes', function () {
        $admin = SystemAdministrator::factory()->create();

        $this->actingAs($admin, 'sysadmin')
            ->get('/sysadmin/system-administrators')
            ->assertOk();
    });

});

describe('Administrator role', function () {
    beforeEach(function () {
        Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

        $this->administrator = SystemAdministrator::factory()->administrator()->create();
        $this->actingAs($this->administrator, 'sysadmin');
    });

    it('reads every panel index it is allowed to see', function (string $route) {
        $this->get($route)->assertOk();
    })->with([
        'dashboard' => '/sysadmin',
        'companies' => '/sysadmin/companies',
        'users' => '/sysadmin/users',
        'workspaces' => '/sysadmin/workspaces',
        'subscriptions' => '/sysadmin/billing/subscriptions',
        'ai credit balances' => '/sysadmin/ai/credit-balances',
        'activities' => '/sysadmin/activity',
        'posts' => '/sysadmin/posts',
        'categories' => '/sysadmin/categories',
        'tags' => '/sysadmin/tags',
    ]);

    it('writes but never deletes', function (string $model) {
        $administrator = auth('sysadmin')->user();

        expect($administrator->can('viewAny', $model))->toBeTrue()
            ->and($administrator->can('view', $model))->toBeTrue()
            ->and($administrator->can('create', $model))->toBeTrue()
            ->and($administrator->can('update', $model))->toBeTrue()
            ->and($administrator->can('restore', $model))->toBeTrue()
            ->and($administrator->can('delete', $model))->toBeFalse()
            ->and($administrator->can('deleteAny', $model))->toBeFalse()
            ->and($administrator->can('forceDelete', $model))->toBeFalse()
            ->and($administrator->can('forceDeleteAny', $model))->toBeFalse();
    })->with([
        'companies' => Company::class,
        'people' => People::class,
        'opportunities' => Opportunity::class,
        'tasks' => Task::class,
        'notes' => Note::class,
        'users' => User::class,
        'workspaces' => Workspace::class,
        'posts' => Post::class,
        'categories' => Category::class,
        'tags' => Tag::class,
    ]);

    it('is offered no delete action on a record it may edit', function () {
        $user = User::factory()->withPersonalWorkspace()->create();

        livewire(EditUser::class, ['record' => $user->getKey()])
            ->assertOk()
            ->assertActionHidden(TestAction::make('delete'));
    });

    it('cannot reach the system administrators resource on any route', function (string $route) {
        $this->get($route)->assertForbidden();
    })->with([
        'index' => '/sysadmin/system-administrators',
        'create' => '/sysadmin/system-administrators/create',
    ]);

    it('cannot reach its own administrator record', function () {
        $this->get('/sysadmin/system-administrators/'.$this->administrator->getKey().'/edit')
            ->assertForbidden();

        expect(auth('sysadmin')->user()->can('update', $this->administrator))->toBeFalse()
            ->and(auth('sysadmin')->user()->can('create', SystemAdministrator::class))->toBeFalse()
            ->and(auth('sysadmin')->user()->can('delete', $this->administrator))->toBeFalse()
            ->and(auth('sysadmin')->user()->can('deleteAny', SystemAdministrator::class))->toBeFalse()
            ->and(auth('sysadmin')->user()->can('viewAny', PersonalAccessToken::class))->toBeFalse();
    });

    it('keeps the writes that are not deletes', function () {
        $balance = AiCreditBalance::factory()->create();

        expect(auth('sysadmin')->user()->can('transfer', Subscription::class))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('update', $balance))->toBeTrue();

        $this->get(ManageAiSettings::getUrl())->assertOk();
    });
});
