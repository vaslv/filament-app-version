<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\View;
use Illuminate\View\ComponentAttributeBag;
use Vaslv\FilamentAppVersion\AppVersionPlugin;

/**
 * The set of variables the chip view receives is just as much a public contract
 * as the method names are, people simply tend to remember it after the first
 * update it breaks. A variable may be added in a minor release; removing or
 * renaming one takes a major.
 */
function renderChip(AppVersionPlugin $plugin): string
{
    $panel = Panel::make()->id('test');

    $plugin->register($panel);

    return panelHooksByName($panel)[PanelsRenderHook::TOPBAR_LOGO_AFTER];
}

beforeEach(function () {
    $this->actingAs(new User);

    config()->set('app.version', '1.19.16');
});

it('passes the view exactly four variables of the right types', function () {
    $captured = [];

    View::composer('filament-app-version::chip', function ($view) use (&$captured): void {
        $captured = $view->getData();
    });

    renderChip(AppVersionPlugin::make());

    // Intersecting with the expected set would throw a FIFTH variable away
    // before the comparison, so half of the assertion, the "exactly four" half,
    // was unprovable, and silent growth of the set is exactly what is worth
    // catching: it is covered by semver. So we subtract Blade's own keys and
    // check the remainder for full equality.
    $contract = array_diff_key($captured, array_flip(['__env', 'app', 'errors', 'obLevel', 'componentName']));

    expect(array_keys($contract))->toEqualCanonicalizing(['version', 'prefix', 'tooltip', 'attributes'])
        ->and($contract['version'])->toBeString()->toBe('1.19.16')
        ->and($contract['prefix'])->toBeString()->toBe('v')
        ->and($contract['tooltip'])->toBeString()
        ->and($contract['attributes'])->toBeInstanceOf(ComponentAttributeBag::class);
});

it('respects a custom view', function () {
    View::addNamespace('tests', __DIR__.'/../Fixtures/views');

    $html = renderChip(AppVersionPlugin::make()->view('tests::custom-chip'));

    expect(trim($html))->toBe('custom:v1.19.16');
});

it('concatenates the user style with the mandatory one', function () {
    $html = renderChip(AppVersionPlugin::make()->extraAttributes(['style' => 'opacity:1;']));

    // Count style= on the chip itself only: the hidden screen reader label has
    // an inline style of its own, and it has nothing to do with this assertion.
    expect($html)->toContain('light-dark(var(--primary-700')
        ->and($html)->toContain('opacity:1;')
        ->and(substr_count(chipTag($html), 'style='))->toBe(1);
});

it('lets arbitrary attributes through into the markup', function () {
    $html = renderChip(AppVersionPlugin::make()->extraAttributes(['data-testid' => 'app-version']));

    expect($html)->toContain('data-testid="app-version"');
});

it('lets a user title win over the package tooltip', function () {
    $html = renderChip(
        AppVersionPlugin::make()
            ->tooltip('Package tooltip')
            ->extraAttributes(['title' => 'Custom tooltip']),
    );

    expect($html)->toContain('title="Custom tooltip"')
        ->and($html)->not->toContain('Package tooltip');
});

it('escapes the version that came from the source', function () {
    config()->set('app.version', '1.0<script>alert(1)</script>');

    $html = renderChip(AppVersionPlugin::make());

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

// A closure is half of the public tooltip(string|Closure|null) signature, and
// until now nothing covered it: a mutation replacing evaluate() with
// is_string($this->tooltip) left the suite green, while a closure tooltip
// silently disappeared.
it('evaluates a closure tooltip', function () {
    expect(renderChip(AppVersionPlugin::make()->tooltip(fn (): string => 'From a closure')))
        ->toContain('title="From a closure"');
});

it('passes the panel into the tooltip() closure', function () {
    expect(renderChip(AppVersionPlugin::make()->tooltip(fn (Panel $panel): string => 'panel:'.$panel->getId())))
        ->toContain('title="panel:test"');
});

it('drops the title when the tooltip returned something other than a string', function () {
    $html = renderChip(AppVersionPlugin::make()->tooltip(fn () => null));

    expect($html)->toContain('1.19.16')
        ->and($html)->not->toContain('title=');
});

it('drops the title on tooltip(null)', function () {
    $html = renderChip(AppVersionPlugin::make()->tooltip(null));

    expect($html)->toContain('1.19.16')
        ->and($html)->not->toContain('title=');
});

it('an empty tooltip leaves no empty title', function () {
    $html = renderChip(AppVersionPlugin::make()->tooltip(''));

    expect($html)->toContain('1.19.16')
        ->and($html)->not->toContain('title=');
});

// toBeString() was no good here: for a missing key trans() returns the KEY
// itself, and that is a string too. Mutation confirmed it, renaming the key in
// the English translation file left the suite green, while users got
// title="filament-app-version::filament-app-version.tooltip".
it('substitutes the English tooltip by default', function () {
    $this->app->setLocale('en');

    expect(renderChip(AppVersionPlugin::make()))->toContain('title="Application version"');
});

it('survives a translation replaced by an array', function () {
    app('translator')->addLines(
        ['filament-app-version.tooltip' => ['nested' => 'array']],
        'en',
        'filament-app-version',
    );

    $html = renderChip(AppVersionPlugin::make());

    expect($html)->toContain('1.19.16')
        ->and($html)->not->toContain('title=');
});

// The chip is a non-interactive span: it never takes focus, so the title cannot
// be reached from the keyboard, and a screen reader usually suppresses it on an
// element that has text of its own. Without the hidden label, assistive
// technology announces a bare "v1.19.16" without a word about it being the
// application version.
it('gives a screen reader a label, not just a title attribute', function () {
    $html = renderChip(AppVersionPlugin::make()->tooltip('Application version'));

    expect($html)->toContain('clip-path:inset(50%)')
        ->and($html)->toContain('Application version: ')
        ->and($html)->toContain('title="Application version"');
});

it('the hidden label repeats the title that really is in the markup', function () {
    $html = renderChip(
        AppVersionPlugin::make()
            ->tooltip('Package tooltip')
            ->extraAttributes(['title' => 'Custom tooltip']),
    );

    expect($html)->toContain('Custom tooltip: ')
        ->and($html)->not->toContain('Package tooltip');
});

it('leaves no empty label when there is no tooltip', function () {
    $html = renderChip(AppVersionPlugin::make()->tooltip(null));

    expect($html)->not->toContain('clip-path');
});

// A long version out of CI must not push the topbar layout around: nowrap with
// no cap made the chip an unshrinkable flex item as wide as the whole string.
it('caps the width so a long version is clipped instead of pushing the topbar', function () {
    config()->set('app.version', '1.2.3-rc4+build.20260813.deadbeef');

    $html = renderChip(AppVersionPlugin::make());

    expect($html)->toContain('white-space:nowrap')
        ->and($html)->toContain('max-width:100%')
        ->and($html)->toContain('overflow:hidden')
        ->and($html)->toContain('text-overflow:ellipsis');
});

// A version identifier is a machine token: in an RTL panel it must not be
// reordered and must not mirror brackets.
it('isolates the writing direction', function () {
    $html = renderChip(AppVersionPlugin::make());

    expect($html)->toContain('unicode-bidi:isolate')
        ->and($html)->toContain('direction:ltr');
});
