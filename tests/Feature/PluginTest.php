<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Auth\User;
use Vaslv\FilamentAppVersion\AppVersionPlugin;
use Vaslv\FilamentAppVersion\Resolvers\ConfigVersionResolver;

/**
 * Calls the hooks the plugin registered and joins their output.
 *
 * Reads $panel->renderHooks by reflection: the property is protected and
 * Filament has no public getter. The hook test in vaslv/filament-topbar-menu is
 * built the same way.
 *
 * @return array<string, string>
 */
function renderedHooks(Panel $panel): array
{
    return panelHooksByName($panel);
}

function panelWith(AppVersionPlugin $plugin, string $id = 'test'): Panel
{
    $panel = Panel::make()->id($id);

    $plugin->register($panel);

    return $panel;
}

beforeEach(function () {
    // Authenticated by default: the chip is hidden from guests, and without
    // this almost every test below would be checking an empty string.
    $this->actingAs(new User);

    config()->set('app.version', '1.19.16');
});

it('registers the topbar and the collapsed sidebar by default', function () {
    $panel = panelWith(AppVersionPlugin::make());

    expect(array_keys(renderedHooks($panel)))->toBe([
        PanelsRenderHook::TOPBAR_LOGO_AFTER,
        PanelsRenderHook::SIDEBAR_LOGO_AFTER,
    ]);
});

it('renders the version with the prefix', function () {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('v1.19.16');
});

it('does not double the prefix when the version already starts with it', function () {
    config()->set('app.version', 'v1.19.16');

    $html = renderedHooks(panelWith(AppVersionPlugin::make()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('v1.19.16')
        ->and($html)->not->toContain('vv1.19.16');
});

it('can turn the prefix off', function () {
    $plugin = AppVersionPlugin::make()->prefix('');

    $html = renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('>1.19.16<');
});

it('respects a custom set of hooks', function () {
    $plugin = AppVersionPlugin::make()->renderHooks([PanelsRenderHook::AUTH_LOGIN_FORM_AFTER]);

    expect(array_keys(renderedHooks(panelWith($plugin))))
        ->toBe([PanelsRenderHook::AUTH_LOGIN_FORM_AFTER]);
});

// The colour comes from the panel's own variables and light-dark() switches the
// theme: Filament declares :root.dark { color-scheme: dark }, but it sets the
// theme itself with a CLASS, which an inline style could never reach.
it('paints the chip in the panel primary colour, switching the shade by theme', function () {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('color:light-dark(var(--primary-700, currentColor), var(--primary-400, currentColor))');
});

it('the neutral variant repeats the colour of an ordinary menu item', function () {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()->neutral()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('color:light-dark(var(--gray-700, currentColor), var(--gray-200, currentColor))');
});

// A guard that has already caught one attempt to add a fallback grey: not a
// single absolute colour in any combination of options.
it('contains no absolute colours under any settings', function (AppVersionPlugin $plugin) {
    $html = renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->not->toMatch('/#[0-9a-fA-F]{3,6}\b/')
        ->and($html)->not->toContain('rgb(')
        ->and($html)->not->toContain('oklch(');
})->with([
    'default' => fn () => AppVersionPlugin::make(),
    'badge' => fn () => AppVersionPlugin::make()->badge(),
    'neutral' => fn () => AppVersionPlugin::make()->neutral(),
    'badge + neutral' => fn () => AppVersionPlugin::make()->badge()->neutral(),
]);

// Bare text by default. The background and the ring appear only on request.
it('draws text with no background and no ring by default', function () {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->not->toContain('background-color')
        ->and($html)->not->toContain('box-shadow')
        ->and($html)->not->toContain('border-radius');
});

it('badge() adds a background and a hairline ring', function () {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()->badge()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('background-color:color-mix(in srgb, var(--primary-500, currentColor) 12%, transparent)')
        ->and($html)->toContain('box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--primary-500, currentColor) 24%, transparent)')
        ->and($html)->toContain('border-radius');
});

it('badge(false) goes back to bare text', function () {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()->badge(false)))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->not->toContain('background-color');
});

it('the badge takes the neutral colour along with the text', function () {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()->badge()->neutral()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('var(--gray-500, currentColor) 12%')
        ->and($html)->not->toContain('--primary-');
});

it('substitutes the translated tooltip', function () {
    $this->app->setLocale('ru');

    $html = renderedHooks(panelWith(AppVersionPlugin::make()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('title="Версия приложения"');
});

it('renders no chip when there is no version and the fallback is off', function () {
    config()->set('app.version', null);
    config()->set('filament-app-version.version', null);

    $plugin = AppVersionPlugin::make()->fallback(null);

    expect(renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER])->toBe('');
});

it('uses the panel chain instead of the global one', function () {
    config()->set('app.release', '2.0.0-panel');

    $plugin = AppVersionPlugin::make()->resolvers([
        ConfigVersionResolver::make('app.release'),
    ]);

    $html = renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('v2.0.0-panel')
        ->and($html)->not->toContain('1.19.16');
});

it('gives each panel its own version', function () {
    config()->set('app.release', '2.0.0-second');

    $first = panelWith(AppVersionPlugin::make(), 'first');
    $second = panelWith(
        AppVersionPlugin::make()->resolvers([ConfigVersionResolver::make('app.release')]),
        'second',
    );

    expect(renderedHooks($first)[PanelsRenderHook::TOPBAR_LOGO_AFTER])->toContain('v1.19.16')
        ->and(renderedHooks($second)[PanelsRenderHook::TOPBAR_LOGO_AFTER])->toContain('v2.0.0-second');
});

// The strtolower on both sides in prefixFor() was written deliberately but was
// never covered: mutating it down to a plain str_starts_with left the suite
// green, while the tag "V1.19.16" rendered as "vV1.19.16".
it('does not double the prefix regardless of case', function (string $version) {
    config()->set('app.version', $version);

    $html = renderedHooks(panelWith(AppVersionPlugin::make()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('>'.$version.'<');
})->with(['V1.19.16', 'v1.19.16']);

it('respects a multi-character prefix', function () {
    config()->set('app.version', 'release-9');

    $html = renderedHooks(panelWith(AppVersionPlugin::make()->prefix('release-')))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('>release-9<');
});

// The plugin id is what a consumer passes to filament('...'), that is, part of
// the public contract. Mutation showed that renaming it broke no test at all.
it('keeps the plugin id stable', function () {
    expect(AppVersionPlugin::make()->getId())->toBe('filament-app-version');
});
