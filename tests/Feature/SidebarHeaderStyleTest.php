<?php

declare(strict_types=1);

use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Auth\User;
use Vaslv\FilamentAppVersion\AppVersionPlugin;

// The sidebar header's logo container is "flex: 1 1 0%" in Filament, so the
// chip — the last child of the header — was pushed to the far edge instead of
// sitting next to the logo it belongs to. The package neutralises that with one
// rule, and the rule travels with the chip: on that hook only, once per
// request, and never when there is no chip to place.

beforeEach(function () {
    $this->actingAs(new User);

    config()->set('app.version', '1.19.16');
});

it('emits the header rule on the sidebar hook, before the chip', function () {
    $panel = Panel::make()->id('test');

    AppVersionPlugin::make()->register($panel);

    $html = panelHooksByName($panel)[PanelsRenderHook::SIDEBAR_LOGO_AFTER];

    expect($html)->toContain('.fi-sidebar-header-logo-ctn{flex:0 1 auto}')
        ->and($html)->toContain('[data-filament-app-version]{margin-inline-end:auto}')
        ->and(strpos($html, '<style>'))->toBeLessThan(strpos($html, '1.19.16'));
});

// The rule addresses the chip by this attribute, so a chip without it is a rule
// pointing at nothing — the logo keeps the space and the pair drifts to the
// middle of the header.
it('marks the chip with the attribute the rule selects on', function () {
    $panel = Panel::make()->id('test');

    AppVersionPlugin::make()->extraAttributes(['data-testid' => 'app-version'])->register($panel);

    $html = panelHooksByName($panel)[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect(chipTag($html))->toContain('data-filament-app-version="true"')
        ->and(chipTag($html))->toContain('data-testid="app-version"');
});

it('leaves the topbar chip alone', function () {
    $panel = Panel::make()->id('test');

    AppVersionPlugin::make()->register($panel);

    $html = panelHooksByName($panel)[PanelsRenderHook::TOPBAR_LOGO_AFTER];

    expect($html)->toContain('1.19.16')
        ->and($html)->not->toContain('<style>');
});

it('emits the rule once for two plugins on one panel', function () {
    $panel = Panel::make()->id('test');

    AppVersionPlugin::make()->register($panel);
    AppVersionPlugin::make()->resolvers([])->fallback('2.0.0')->register($panel);

    $html = panelHooksByName($panel)[PanelsRenderHook::SIDEBAR_LOGO_AFTER];

    expect(substr_count($html, '<style>'))->toBe(1)
        ->and($html)->toContain('1.19.16')
        ->and($html)->toContain('2.0.0');
});

it('emits nothing at all when the chip is hidden', function () {
    $panel = Panel::make()->id('test');

    AppVersionPlugin::make()->visible(false)->register($panel);

    $html = panelHooksByName($panel)[PanelsRenderHook::SIDEBAR_LOGO_AFTER];

    expect($html)->toBe('');
});

it('does not emit the rule when the chip renders on other hooks', function () {
    $panel = Panel::make()->id('test');

    AppVersionPlugin::make()
        ->renderHooks([PanelsRenderHook::TOPBAR_LOGO_AFTER])
        ->register($panel);

    $rendered = implode('', panelHooksByName($panel));

    expect($rendered)->toContain('1.19.16')
        ->and($rendered)->not->toContain('<style>');
});
