<?php

declare(strict_types=1);

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Auth\User;
use Vaslv\FilamentAppVersion\AppVersionPlugin;

/**
 * The most expensive assumption in the whole design: the position of
 * ->plugin(...) in the panel provider chain decides the output order on ONE
 * hook.
 *
 * The topbar layout rests on it everywhere another plugin writes to the same
 * hook: the chip has to come before the menu, otherwise you get menu → logo →
 * version. If Filament ever changes the order in which it walks the hooks, the
 * layout breaks for everyone who relies on this, and it breaks silently.
 *
 * A "the chip showed up in the markup" check does NOT cover that: it passes
 * with the right order and with the reverse one. What is needed is two outputs
 * on one hook with distinguishable markers and a comparison of their positions
 * in the resulting HTML.
 */

/**
 * A stand-in plugin: prints a marker on the same hook as the version chip.
 */
function markerPlugin(string $marker): Plugin
{
    return new class($marker) implements Plugin
    {
        public function __construct(private readonly string $marker) {}

        public function getId(): string
        {
            return 'marker-'.$this->marker;
        }

        public function register(Panel $panel): void
        {
            $panel->renderHook(
                PanelsRenderHook::TOPBAR_LOGO_AFTER,
                fn (): string => '<!--'.$this->marker.'-->',
            );
        }

        public function boot(Panel $panel): void
        {
            //
        }
    };
}

function topbarHtml(Panel $panel): string
{
    return panelHooksByName($panel)[PanelsRenderHook::TOPBAR_LOGO_AFTER];
}

beforeEach(function () {
    $this->actingAs(new User);

    config()->set('app.version', '1.19.16');
});

it('puts the chip before a plugin registered later', function () {
    $panel = Panel::make()->id('test');

    AppVersionPlugin::make()->register($panel);
    markerPlugin('menu')->register($panel);

    $html = topbarHtml($panel);

    expect(strpos($html, '1.19.16'))->toBeLessThan(strpos($html, '<!--menu-->'));
});

it('puts the chip after a plugin registered earlier', function () {
    $panel = Panel::make()->id('test');

    markerPlugin('menu')->register($panel);
    AppVersionPlugin::make()->register($panel);

    $html = topbarHtml($panel);

    expect(strpos($html, '<!--menu-->'))->toBeLessThan(strpos($html, '1.19.16'));
});

it('keeps the order among several neighbours on one hook', function () {
    $panel = Panel::make()->id('test');

    markerPlugin('first')->register($panel);
    AppVersionPlugin::make()->register($panel);
    markerPlugin('last')->register($panel);

    $html = topbarHtml($panel);

    expect(strpos($html, '<!--first-->'))->toBeLessThan(strpos($html, '1.19.16'))
        ->and(strpos($html, '1.19.16'))->toBeLessThan(strpos($html, '<!--last-->'));
});
