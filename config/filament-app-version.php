<?php

declare(strict_types=1);

use Vaslv\FilamentAppVersion\Resolvers\ComposerJsonVersionResolver;
use Vaslv\FilamentAppVersion\Resolvers\ConfigVersionResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Application version
    |--------------------------------------------------------------------------
    |
    | The point where CI injects the build version. env() is called HERE, inside
    | the config file, not at runtime: with `php artisan config:cache` Laravel
    | does not load .env at all, and an env() call at runtime would return null,
    | silently and only in production. Inside a config file the value freezes
    | when the cache is built and works under any caching mode.
    |
    */

    'version' => env('APP_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Fallback value
    |--------------------------------------------------------------------------
    |
    | What to show when no source produced a version. null means "render no chip
    | at all", which is more honest in production than showing "vdev".
    |
    */

    'fallback' => 'dev',

    /*
    |--------------------------------------------------------------------------
    | Resolver chain
    |--------------------------------------------------------------------------
    |
    | Version sources in descending order of priority. The first one to return a
    | non-empty string wins. An explicit human decision (the config) beats a
    | passive fact of the repository (composer.json).
    |
    | An entry is a class name, or [class name, ...constructor arguments]. Only
    | strings and arrays: closures MUST NOT go here, they will not survive
    | config:cache (Laravel does not serialise a Closure, and that is a fatal
    | error while the cache is built, not a degradation). If you need an
    | arbitrary source, use CallbackVersionResolver in the panel provider, where
    | closures are allowed.
    |
    | The order of this chain is part of the package's public contract and
    | changes only in a major version.
    |
    */

    'resolvers' => [
        [ConfigVersionResolver::class, 'filament-app-version.version'],
        [ConfigVersionResolver::class, 'app.version'],
        [ComposerJsonVersionResolver::class],
    ],

];
