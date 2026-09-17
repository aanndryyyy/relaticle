<?php

declare(strict_types=1);

use App\Http\Middleware\StopImpersonationOnLogout;
use App\Livewire\App\Profile\ScheduledDeletionInterstitial;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\Company;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\Impersonation\Impersonator;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

use function Pest\Laravel\actingAs;

mutates(Impersonator::class, StopImpersonationOnLogout::class, ScheduledDeletionInterstitial::class);

function impersonationLink(User $target, array $parameters = []): string
{
    return URL::temporarySignedRoute(
        'impersonation.start',
        now()->addMinutes(5),
        ['user' => $target->getKey(), ...$parameters]
    );
}

beforeEach(function (): void {
    $this->administrator = SystemAdministrator::factory()->create();
    $this->customer = User::factory()->withWorkspace()->create();
});

it('signs the administrator in as the customer', function (): void {
    actingAs($this->administrator, 'sysadmin')
        ->get(impersonationLink($this->customer))
        ->assertRedirect(url()->getAppUrl());

    expect(Auth::guard('web')->id())->toBe($this->customer->getKey())
        ->and(Auth::guard('sysadmin')->id())->toBe($this->administrator->getKey());
});

it('keeps the customer signed in on the next panel request', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));

    $this->get(url()->getAppUrl($this->customer->currentWorkspace->slug))
        ->assertSuccessful();

    expect(Auth::guard('web')->id())->toBe($this->customer->getKey());
});

it('lands in the named workspace', function (): void {
    $workspace = $this->customer->currentWorkspace;

    actingAs($this->administrator, 'sysadmin')
        ->get(impersonationLink($this->customer, ['workspace' => $workspace->getKey()]))
        ->assertRedirect(url()->getAppUrl($workspace->slug));
});

it('ignores a workspace the target does not belong to', function (): void {
    $stranger = User::factory()->withWorkspace()->create();

    actingAs($this->administrator, 'sysadmin')
        ->get(impersonationLink($this->customer, ['workspace' => $stranger->currentWorkspace->getKey()]))
        ->assertRedirect(url()->getAppUrl());
});

it('restores the account the administrator was signed into', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));

    $this->delete(route('impersonation.stop'))
        ->assertRedirect(url()->getSysadminUrl('users'));

    $this->delete(route('impersonation.stop'));

    expect(Auth::guard('web')->id())->toBe($administratorsOwnAccount->getKey());
});

it('records the stop when the administrator switches to another customer', function (): void {
    $otherCustomer = User::factory()->withWorkspace()->create();

    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));
    $this->get(impersonationLink($otherCustomer));

    $stopped = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->where('event', 'impersonation_stopped')
        ->get();

    expect($stopped)->toHaveCount(1)
        ->and($stopped->first()->subject_id)->toBe($this->customer->getKey())
        ->and(Auth::guard('web')->id())->toBe($otherCustomer->getKey());
});

it('crosses from the sysadmin host to the app host when the panels are domain routed', function (): void {
    config([
        'app.url' => 'https://relaticle.test',
        'app.app_panel_domain' => 'app.relaticle.test',
        'app.sysadmin_domain' => 'sysadmin.relaticle.test',
    ]);

    URL::forceRootUrl('https://sysadmin.relaticle.test');
    $link = impersonationLink($this->customer);
    URL::forceRootUrl(null);

    actingAs($this->administrator, 'sysadmin')
        ->get($link)
        ->assertRedirect('https://app.relaticle.test');

    expect(Auth::guard('web')->id())->toBe($this->customer->getKey());
});

it('signs out of the app when the administrator had no account signed in', function (): void {
    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));

    $this->delete(route('impersonation.stop'));

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::guard('sysadmin')->id())->toBe($this->administrator->getKey());
});

it('leaves the customer login timestamp and remember token untouched', function (): void {
    $this->customer->forceFill([
        'last_login_at' => now()->subMonths(3),
        'remember_token' => 'keep-me-signed-in',
    ])->saveQuietly();

    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));
    $this->delete(route('impersonation.stop'));

    $this->customer->refresh();

    expect($this->customer->last_login_at->isSameDay(now()->subMonths(3)))->toBeTrue()
        ->and($this->customer->remember_token)->toBe('keep-me-signed-in');
});

it('refuses an administrator who may not impersonate', function (): void {
    $administrator = SystemAdministrator::factory()->administrator()->create();

    actingAs($administrator, 'sysadmin')
        ->get(impersonationLink($this->customer))
        ->assertForbidden();

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('refuses an unsigned link', function (): void {
    actingAs($this->administrator, 'sysadmin')
        ->get('/impersonate/'.$this->customer->getKey())
        ->assertForbidden();
});

it('refuses a caller who is not signed into the sysadmin panel', function (): void {
    $this->get(impersonationLink($this->customer))->assertRedirect();

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('records the start and the stop against the administrator', function (): void {
    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));
    $this->delete(route('impersonation.stop'));

    $events = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->whereIn('event', ['impersonation_started', 'impersonation_stopped'])
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('causer_id')->unique()->all())->toBe([$this->administrator->getKey()])
        ->and($events->pluck('subject_id')->unique()->all())->toBe([$this->customer->getKey()]);
});

it('tags a write made during impersonation with the administrator', function (): void {
    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));

    $company = Company::factory()->create([
        'workspace_id' => $this->customer->currentWorkspace->getKey(),
        'creator_id' => $this->customer->getKey(),
    ]);

    $activity = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->where('subject_id', $company->getKey())
        ->latest('id')
        ->first();

    expect($activity->properties->get('impersonated_by'))->toBe($this->administrator->getKey());
});

it('leaves an ordinary write untagged', function (): void {
    actingAs($this->customer, 'web');

    $company = Company::factory()->create([
        'workspace_id' => $this->customer->currentWorkspace->getKey(),
        'creator_id' => $this->customer->getKey(),
    ]);

    $activity = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->where('subject_id', $company->getKey())
        ->latest('id')
        ->first();

    expect($activity->properties->get('impersonated_by'))->toBeNull();
});

it('drops the administrators identity confirmation when assuming the customer', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    session()->put('auth.password_confirmed_at', time());
    AuthenticationSession::startOperation($administratorsOwnAccount, 'set_password', null);

    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));
    Auth::shouldUse('web');

    $this->get(route('auth.socialite.link.redirect', ['provider' => 'google']))
        ->assertRedirect(route('password.confirm'));

    expect(AuthenticationSession::pendingOperation())->toBe([]);
});

it('drops the confirmation the customer session held when restoring the administrator', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));
    session()->put('auth.password_confirmed_at', time());

    $this->delete(route('impersonation.stop'));
    Auth::shouldUse('web');

    $this->get(route('auth.socialite.link.redirect', ['provider' => 'google']))
        ->assertRedirect(route('password.confirm'));
});

it('keeps an mfa enrolled customer signed in on the next panel request', function (): void {
    $customer = User::factory()->withConfirmedMfa()->withWorkspace()->create();

    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($customer));

    $this->get(url()->getAppUrl($customer->currentWorkspace->slug))
        ->assertSuccessful();

    expect(Auth::guard('web')->id())->toBe($customer->getKey());
});

it('keeps the administrators own mfa session complete after stopping', function (): void {
    $administratorsOwnAccount = User::factory()->withConfirmedMfa()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    AuthenticationSession::markComplete($administratorsOwnAccount);
    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));

    $this->delete(route('impersonation.stop'));

    $this->get(url()->getAppUrl($administratorsOwnAccount->currentWorkspace->slug))
        ->assertSuccessful();

    expect(Auth::guard('web')->id())->toBe($administratorsOwnAccount->getKey());
});

it('ends the impersonation instead of signing the customer out', function (string $routeName, array $parameters): void {
    $this->customer->forceFill(['remember_token' => 'keep-me-signed-in'])->saveQuietly();

    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));

    $this->post(route($routeName, $parameters))
        ->assertRedirect(url()->getSysadminUrl('users'));

    expect($this->customer->refresh()->remember_token)->toBe('keep-me-signed-in')
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and(session('impersonation.target_id'))->toBeNull();
})->with([
    'panel sign out' => ['filament.app.auth.logout', []],
    'fortify sign out' => ['logout', []],
    'invitation account switch' => ['workspace-invitations.token.switch', ['token' => str_repeat('a', 40)]],
]);

it('ends the impersonation from the scheduled deletion interstitial instead of signing the customer out', function (): void {
    $customer = User::factory()->withPersonalWorkspace()->scheduledForDeletion()->create();
    $customer->forceFill(['remember_token' => 'keep-me-signed-in'])->saveQuietly();

    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($customer));
    Auth::shouldUse('web');
    app()->rebinding('request', fn (Application $app, Request $request) => $request->setLaravelSession($app['session.store']));

    livewire(ScheduledDeletionInterstitial::class)
        ->callAction('logout')
        ->assertRedirect(url()->getSysadminUrl('users'));

    expect($customer->refresh()->remember_token)->toBe('keep-me-signed-in')
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('leaves the sysadmin panels own sign out alone', function (): void {
    actingAs($this->administrator, 'sysadmin')->get(impersonationLink($this->customer));

    $this->post(route('filament.sysadmin.auth.logout'))
        ->assertRedirect(url()->getSysadminUrl('login'));

    expect(Auth::guard('sysadmin')->check())->toBeFalse();
});
