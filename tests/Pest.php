<?php

declare(strict_types=1);

use Filament\Panel;
use Vaslv\FilamentAppVersion\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * The closures the plugin registered on the panel's render hooks.
 *
 * The only place in the whole suite that reaches into a protected Filament
 * property. This reflection used to be copied into six files under six
 * different names, and the day Filament renames the property would have cost
 * six different error messages. Pest loads every test file into one global
 * namespace, so six names were also six chances at a fatal redeclaration.
 *
 * @return array<int, Closure>
 */
function panelHooks(Panel $panel): array
{
    /** @var array<string, array<string, array<int, Closure>>> $hooks */
    $hooks = (new ReflectionProperty($panel, 'renderHooks'))->getValue($panel);

    $closures = [];

    foreach ($hooks as $scopes) {
        foreach ($scopes as $scoped) {
            foreach ($scoped as $closure) {
                $closures[] = $closure;
            }
        }
    }

    return $closures;
}

/**
 * The same, but keyed by hook name, for when it matters what rendered where.
 *
 * @return array<string, string>
 */
function panelHooksByName(Panel $panel): array
{
    /** @var array<string, array<string, array<int, Closure>>> $hooks */
    $hooks = (new ReflectionProperty($panel, 'renderHooks'))->getValue($panel);

    $rendered = [];

    foreach ($hooks as $hook => $scopes) {
        $html = '';

        foreach ($scopes as $scoped) {
            foreach ($scoped as $closure) {
                $html .= $closure();
            }
        }

        $rendered[$hook] = $html;
    }

    return $rendered;
}
