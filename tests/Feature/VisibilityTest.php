<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Log;
use Vaslv\FilamentAppVersion\AppVersionPlugin;

/**
 * Visibility precedence table:
 *
 *                   guest                    authenticated
 *               ┌───────────────────────┬───────────────────────┐
 *   default     │  hidden               │  shown                │
 *   showToGuests│  shown                │  shown                │
 *   visible(fn) │  the result of fn     │  the result of fn     │
 *               └───────────────────────┴───────────────────────┘
 *
 * An explicit visible() has the last word. The safe default is needed because
 * hooks such as AUTH_LOGIN_FORM_AFTER render before login, and a version on a
 * public login page is a hint to whoever is looking for a CVE against a
 * specific release.
 */
function chip(AppVersionPlugin $plugin): string
{
    $panel = Panel::make()->id('test');

    $plugin->register($panel);

    return panelHooksByName($panel)[PanelsRenderHook::TOPBAR_LOGO_AFTER];
}

beforeEach(function () {
    config()->set('app.version', '1.19.16');
});

it('hides the version from a guest by default', function () {
    expect(chip(AppVersionPlugin::make()))->toBe('');
});

it('shows the version to an authenticated user by default', function () {
    $this->actingAs(new User);

    expect(chip(AppVersionPlugin::make()))->toContain('v1.19.16');
});

it('shows the version to a guest after an explicit showToGuests()', function () {
    expect(chip(AppVersionPlugin::make()->showToGuests()))->toContain('v1.19.16');
});

it('showToGuests(false) restores the default behaviour', function () {
    expect(chip(AppVersionPlugin::make()->showToGuests(false)))->toBe('');
});

it('visible(false) hides the chip even from an authenticated user', function () {
    $this->actingAs(new User);

    expect(chip(AppVersionPlugin::make()->visible(false)))->toBe('');
});

// Two cases, two tests, not two chip() calls in a row: the visibility result is
// memoised by panel id for the whole request, so a second call in the same test
// would get the answer of the first one. Exactly the behaviour memoisation
// exists for.
it('visible(fn) can hide the chip', function () {
    $this->actingAs(new User);

    expect(chip(AppVersionPlugin::make()->visible(fn (): bool => false)))->toBe('');
});

it('visible(fn) can show the chip', function () {
    $this->actingAs(new User);

    expect(chip(AppVersionPlugin::make()->visible(fn (): bool => true)))->toContain('v1.19.16');
});

it('visible() overrides showToGuests()', function () {
    $plugin = AppVersionPlugin::make()->showToGuests()->visible(false);

    expect(chip($plugin))->toBe('');
});

it('warns about the visible() and showToGuests() contradiction when debug is on', function () {
    config()->set('app.debug', true);

    Log::spy();

    chip(AppVersionPlugin::make()->showToGuests()->visible(true));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'showToGuests() is ignored'));
});

it('stays quiet about the contradiction when debug is off', function () {
    config()->set('app.debug', false);

    Log::spy();

    chip(AppVersionPlugin::make()->showToGuests()->visible(true));

    Log::shouldNotHaveReceived('warning');
});

// Four cells of the precedence table that until now no assertion covered. The
// test specialist proved it by mutation: replacing isVisible() with
// `$panel->auth()->check() && (bool) $this->evaluate(...)` left the whole suite
// green, so a regression taking away the ability of visible(true) to show the
// chip to a guest would have made it to a release.
it('visible(true) shows the chip to a guest', function () {
    expect(chip(AppVersionPlugin::make()->visible(true)))->toContain('v1.19.16');
});

it('visible(fn) shows the chip to a guest', function () {
    expect(chip(AppVersionPlugin::make()->visible(fn (): bool => true)))->toContain('v1.19.16');
});

it('visible(fn) hides the chip from a guest', function () {
    expect(chip(AppVersionPlugin::make()->visible(fn (): bool => false)))->toBe('');
});

it('showToGuests() does not get in the way of an authenticated user', function () {
    $this->actingAs(new User);

    expect(chip(AppVersionPlugin::make()->showToGuests()))->toContain('v1.19.16');
});

// A Filament idiom. It used to be an ArgumentCountError, that is a 500 on every
// admin panel page for anyone who copied the closure out of the documentation.
it('passes the panel into the visible() closure', function () {
    $seen = null;

    chip(AppVersionPlugin::make()->visible(function (Panel $panel) use (&$seen): bool {
        $seen = $panel->getId();

        return true;
    }));

    expect($seen)->toBe('test');
});
