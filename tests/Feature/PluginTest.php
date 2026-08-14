<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\VerticalAlignment;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Log;
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

// The scale exists so the chip can be tuned to a panel, but the DEFAULT is a
// released look: 1.0.0 shipped 0.75rem/1rem hard-coded, and size() must leave
// it alone until someone calls it. Anything else restyles every existing panel
// in a minor release.
it('leaves the default size exactly as it was before the scale existed', function () {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('font-size:0.75rem;line-height:1rem;');
});

it('scales the text', function (Size|string $size, string $expected) {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()->size($size)))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain($expected);
})->with([
    'xs' => [Size::ExtraSmall, 'font-size:0.625rem;line-height:0.875rem;'],
    'sm' => [Size::Small, 'font-size:0.6875rem;line-height:1rem;'],
    'md' => [Size::Medium, 'font-size:0.75rem;line-height:1rem;'],
    'lg' => [Size::Large, 'font-size:0.875rem;line-height:1.25rem;'],
    'xl' => [Size::ExtraLarge, 'font-size:1rem;line-height:1.5rem;'],
    'the string form' => ['lg', 'font-size:0.875rem;line-height:1.25rem;'],
]);

// The size has to reach the pill too, otherwise xl text sits in md padding and
// the chip stops looking like one thing.
it('scales the pill along with the text', function (Size $size, string $expected) {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()->badge()->size($size)))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain($expected);
})->with([
    'xs' => [Size::ExtraSmall, 'padding:0 0.25rem;border-radius:0.25rem;'],
    'sm' => [Size::Small, 'padding:0.0625rem 0.375rem;border-radius:0.25rem;'],
    'md' => [Size::Medium, 'padding:0.125rem 0.5rem;border-radius:0.375rem;'],
    'lg' => [Size::Large, 'padding:0.25rem 0.625rem;border-radius:0.375rem;'],
    'xl' => [Size::ExtraLarge, 'padding:0.375rem 0.75rem;border-radius:0.5rem;'],
]);

// One size would not do: pillStyle() is a match over the scale, so a padding
// that leaks into bare text at a single step survives a single-step test.
it('leaves bare text without a pill at any size', function (Size $size) {
    $html = renderedHooks(panelWith(AppVersionPlugin::make()->size($size)))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    // The chip's own tag only: the hidden screen reader label carries a
    // padding:0 of its own, and it has nothing to do with the pill.
    expect(chipTag($html))->not->toContain('padding:')
        ->and(chipTag($html))->not->toContain('border-radius');
})->with([
    'xs' => Size::ExtraSmall,
    'sm' => Size::Small,
    'md' => Size::Medium,
    'lg' => Size::Large,
    'xl' => Size::ExtraLarge,
]);

// "The previous value stays" is what the READMEs and the changelog promise, and
// asserting the DEFAULT is still there proves nothing: a size() that reset to
// Medium instead of ignoring the input would pass that just as well.
it('keeps the size it already had when the new one is unknown', function () {
    config()->set('app.debug', true);

    Log::spy();

    $plugin = AppVersionPlugin::make()->size(Size::ExtraLarge)->size('huge');

    expect(renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER])
        ->toContain('font-size:1rem;line-height:1.5rem;');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, "size('huge')"));
});

it('keeps the alignment it already had when the new one is unknown', function () {
    config()->set('app.debug', true);

    Log::spy();

    $plugin = AppVersionPlugin::make()->verticalAlignment(VerticalAlignment::End)->verticalAlignment('top');

    expect(renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER])
        ->toContain('vertical-align:bottom;align-self:flex-end;');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, "verticalAlignment('top')"));
});

// Two declarations, because the hook decides which one is live: a flex row and
// a grid item ignore vertical-align, a chip inside running text ignores
// align-self. Dropping either leaves the method working on half the hooks.
it('emits both the flex and the inline declaration', function (VerticalAlignment|string $alignment, string $expected) {
    $plugin = AppVersionPlugin::make()->verticalAlignment($alignment);

    expect(renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER])->toContain($expected);
})->with([
    'start' => [VerticalAlignment::Start, 'vertical-align:top;align-self:flex-start;'],
    'end' => [VerticalAlignment::End, 'vertical-align:bottom;align-self:flex-end;'],
    'the string form' => ['end', 'vertical-align:bottom;align-self:flex-end;'],
]);

// Centre is the default, and a default must not carry align-self. Filament's
// own column-flex hooks — .fi-sidebar-nav, .fi-sidebar — read align-self on the
// HORIZONTAL axis, so an unconditional align-self:center would quietly pull
// every chip on SIDEBAR_NAV_END and its neighbours out of the sidebar's full
// width and into its middle, without anyone calling the method.
it('adds no align-self when the chip is centred', function (AppVersionPlugin $plugin) {
    $html = renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('vertical-align:middle;')
        ->and($html)->not->toContain('align-self');
})->with([
    'the default' => fn () => AppVersionPlugin::make(),
    'centre asked for explicitly' => fn () => AppVersionPlugin::make()->verticalAlignment(VerticalAlignment::Center),
]);

// warn() is shared by three call sites now. Without this, a warn() that fired
// on every configuration would still leave the suite green: the only debug-on
// assertions feed it invalid input, and the debug-off one is the TestCase
// default anyway.
it('says nothing about a size and an alignment it understands', function () {
    config()->set('app.debug', true);

    Log::spy();

    renderedHooks(panelWith(
        AppVersionPlugin::make()->size(Size::Large)->verticalAlignment(VerticalAlignment::Start),
    ));

    Log::shouldNotHaveReceived('warning');
});

it('stays quiet about an unknown size and alignment when debug is off', function () {
    config()->set('app.debug', false);

    Log::spy();

    renderedHooks(panelWith(AppVersionPlugin::make()->size('huge')->verticalAlignment('top')));

    Log::shouldNotHaveReceived('warning');
});

// The user's own style is appended last and wins, size() and
// verticalAlignment() included. Losing that order would make
// extraAttributes(['style' => 'font-size:...']) a coin toss, and the README
// offers it as the way to restyle without a custom view.
it('lets the user style win over the size', function () {
    $plugin = AppVersionPlugin::make()
        ->size(Size::ExtraLarge)
        ->extraAttributes(['style' => 'font-size:2rem;']);

    $html = renderedHooks(panelWith($plugin))[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toMatch('/font-size:1rem;.*font-size:2rem;/');
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
