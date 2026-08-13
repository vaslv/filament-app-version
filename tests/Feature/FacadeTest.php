<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Auth\User;
use Vaslv\FilamentAppVersion\AppVersionPlugin;
use Vaslv\FilamentAppVersion\Facades\AppVersion;
use Vaslv\FilamentAppVersion\Resolvers\ConfigVersionResolver;

/**
 * Two-level ownership of the chain (decision D10).
 *
 *   AppVersion::get()        → ALWAYS the global chain from the config
 *   ->resolvers([...])       → only THIS panel's chip
 *
 * Why not the other way round. The case the facade exists for at all is the
 * cache-buster of a custom theme:
 *
 *     ->theme(asset('css/filament/admin/theme.css').'?v='.AppVersion::get())
 *     ->plugin(AppVersionPlugin::make()->resolvers([...]))
 *
 * ->theme() runs BEFORE ->plugin() in the same fluent chain. At the moment the
 * facade is called the plugin does not exist yet, so "the facade reads the
 * plugin's settings" would be a promise that physically cannot be kept. It
 * would silently not work for everyone who put the calls in this order, which
 * is everyone.
 */
function chipHtml(Panel $panel): string
{
    return panelHooksByName($panel)[PanelsRenderHook::TOPBAR_LOGO_AFTER];
}

beforeEach(function () {
    $this->actingAs(new User);

    config()->set('app.version', '1.19.16');
    config()->set('app.release', '9.9.9-panel');
});

it('works with no panel at all, the cache-buster case', function () {
    expect(AppVersion::get())->toBe('1.19.16');
});

it('is not overridden by a panel chain', function () {
    $panel = Panel::make()->id('admin');

    AppVersionPlugin::make()
        ->resolvers([ConfigVersionResolver::make('app.release')])
        ->register($panel);

    // The panel chip shows its own value, the facade the global one. Both
    // values live at the same time and do not get in each other's way.
    expect(chipHtml($panel))->toContain('9.9.9-panel')
        ->and(AppVersion::get())->toBe('1.19.16');
});

it('returns the same value before and after the plugin is registered', function () {
    $before = AppVersion::get();

    $panel = Panel::make()->id('admin');
    AppVersionPlugin::make()
        ->resolvers([ConfigVersionResolver::make('app.release')])
        ->register($panel);
    chipHtml($panel);

    expect(AppVersion::get())->toBe($before)->toBe('1.19.16');
});

// The previous version of this test pushed a string that was already URL-safe
// through rawurlencode() and checked that it had not changed, which tested
// rawurlencode() rather than the package. Take a version that REALLY needs the
// encoding: CI that builds the version out of a branch name produces one
// regularly.
it('works as a cache-buster: a version from a branch name can be encoded', function () {
    config()->set('app.version', 'feature/chip 1.0');

    expect(AppVersion::get())->toBe('feature/chip 1.0')
        ->and('https://example.test/css/theme.css?v='.rawurlencode((string) AppVersion::get()))
        ->toBe('https://example.test/css/theme.css?v=feature%2Fchip%201.0');
});

// The standard Laravel idiom. While the facade accessor was final, all of this
// threw Mockery\Exception, and a consumer could not write a test around the
// cache-buster the README recommends.
it('can be faked with shouldReceive', function () {
    AppVersion::shouldReceive('get')->once()->andReturn('mocked');

    expect(AppVersion::get())->toBe('mocked');
});

it('can be faked with spy', function () {
    config()->set('app.version', '1.19.16');

    AppVersion::spy();

    AppVersion::get();

    AppVersion::shouldHaveReceived('get')->once();
});

it('gives a panel without its own chain the global result', function () {
    $panel = Panel::make()->id('admin');

    AppVersionPlugin::make()->register($panel);

    expect(chipHtml($panel))->toContain('1.19.16')
        ->and(AppVersion::get())->toBe('1.19.16');
});

it('returns null when there is no version and the fallback is removed globally', function () {
    config()->set('app.version', null);
    config()->set('filament-app-version.version', null);
    config()->set('filament-app-version.fallback', null);

    expect(AppVersion::get())->toBeNull();
});
