<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Vaslv\FilamentAppVersion\AppVersionManager;
use Vaslv\FilamentAppVersion\Resolvers\ComposerJsonVersionResolver;
use Vaslv\FilamentAppVersion\Resolvers\ConfigVersionResolver;

/**
 * The whole env design of the package rests on config:cache, which is why this
 * test sits here, next to the service provider, and not at the end of
 * development.
 *
 * The constraint it guards: Laravel does not serialise a Closure. One closure
 * that makes it into config/filament-app-version.php turns
 * `php artisan config:cache` into a fatal error in production, not into a
 * degradation but into a failed deploy. CI has to catch that, not the user.
 */
afterEach(function () {
    // The config cache is written into the Testbench skeleton and would
    // outlive this test, breaking every test after it. Always clean it up.
    $cached = $this->app->getCachedConfigPath();

    if (is_file($cached)) {
        @unlink($cached);
    }
});

it('survives php artisan config:cache', function () {
    $this->artisan('config:cache')->assertSuccessful();

    expect($this->app->getCachedConfigPath())->toBeFile();
});

it('holds no closures in the package config', function () {
    $config = require __DIR__.'/../../config/filament-app-version.php';

    $closures = [];

    array_walk_recursive($config, function (mixed $value, int|string $key) use (&$closures): void {
        if ($value instanceof Closure) {
            $closures[] = $key;
        }
    });

    expect($closures)->toBeEmpty(
        'A closure in the package config breaks config:cache fatally. '.
        'An arbitrary version source is CallbackVersionResolver in the panel provider.',
    );
});

it('describes the default chain declaratively and survives var_export', function () {
    $config = require __DIR__.'/../../config/filament-app-version.php';

    // var_export is exactly what Laravel serialises the config cache with.
    expect(fn () => var_export($config, true))->not->toThrow(Throwable::class)
        ->and($config['resolvers'])->toBe([
            [ConfigVersionResolver::class, 'filament-app-version.version'],
            [ConfigVersionResolver::class, 'app.version'],
            [ComposerJsonVersionResolver::class],
        ]);
});

// The central decision of the package, that env() is called INSIDE the config
// and not at runtime, was until now pinned down by nothing. Mutation proved it:
// replacing 'version' => env('APP_VERSION') with 'version' => null left all
// 113 tests green while APP_VERSION quietly stopped working in production,
// where the config is cached.
it('freezes APP_VERSION inside the config, not at runtime', function () {
    $_SERVER['APP_VERSION'] = '1.19.16';

    try {
        $config = require __DIR__.'/../../config/filament-app-version.php';

        expect($config['version'])->toBe('1.19.16');
    } finally {
        unset($_SERVER['APP_VERSION']);
    }
});

// Installing the package into an application whose config is ALREADY cached:
// Laravel skips mergeConfigFrom entirely in that case, the resolvers key does
// not exist, and the chain used to come out empty, a permanent "vdev" with no
// explanation.
it('keeps the default chain when the application config is cached without the package', function () {
    $items = app(Repository::class)->all();
    $items['app']['version'] = '1.19.16';

    // Exactly the state an application with a cached config ends up in: the
    // package section is not there at all, because mergeConfigFrom never ran.
    // We swap the whole repository, because set() does not remove a key.
    unset($items['filament-app-version']);

    $this->app->instance('config', new Repository($items));
    $this->app->forgetScopedInstances();

    expect(app(Repository::class)->has('filament-app-version.resolvers'))->toBeFalse()
        ->and(app(AppVersionManager::class)->get())->toBe('1.19.16');
});

// An empty array, on the other hand, is a deliberate "the chain is switched
// off", and substituting the default for it would be ignoring a direct
// instruction.
it('respects a deliberately emptied chain', function () {
    config()->set('filament-app-version.resolvers', []);
    config()->set('filament-app-version.fallback', 'none');
    config()->set('app.version', '1.19.16');

    expect(app(AppVersionManager::class)->get())->toBe('none');
});
