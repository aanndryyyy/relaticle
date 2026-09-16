<?php

declare(strict_types=1);

use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use Tests\Helpers\FakeTranslations;

mutates(SetUserLocale::class);

test('an authenticated panel request renders in the stored locale', function () {
    FakeTranslations::inLocale('da', ['Chats' => 'Samtaler']);

    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'da']);

    $this->actingAs($user)
        ->get('/app/'.$user->currentWorkspace->slug)
        ->assertOk()
        ->assertSee('Samtaler')
        ->assertDontSee('>Chats<', false);
});

test('a stored locale drives the app, date and number locales', function () {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'da']);

    $this->actingAs($user)->get('/app/'.$user->currentWorkspace->slug)->assertOk();

    expect(app()->getLocale())->toBe('da')
        ->and(Date::getLocale())->toBe('da')
        ->and(Number::defaultLocale())->toBe('da');
});

test('a user without a stored locale leaves the app default alone', function () {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => null]);

    $this->actingAs($user)->get('/app/'.$user->currentWorkspace->slug)->assertOk();

    expect(app()->getLocale())->toBe('en');
});

test('a locale no longer offered falls back to the app default', function () {
    $user = User::factory()->withPersonalWorkspace()->create();
    $user->forceFill(['locale' => 'ne'])->saveQuietly();

    $this->actingAs($user)->get('/app/'.$user->currentWorkspace->slug)->assertOk();

    expect(app()->getLocale())->toBe('en');
});

test('a guest request stays on the app default', function () {
    $this->get('/app/login')->assertOk();

    expect(app()->getLocale())->toBe('en');
});
