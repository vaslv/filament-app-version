<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use Vaslv\FilamentAppVersion\Resolvers\EnvVersionResolver;

afterEach(function () {
    putenv('APP_VERSION');
    putenv('APP_RELEASE');
    unset($_ENV['APP_VERSION'], $_ENV['APP_RELEASE'], $_SERVER['APP_VERSION'], $_SERVER['APP_RELEASE']);
});

it('reads a process environment variable', function () {
    $_SERVER['APP_VERSION'] = '1.19.16';

    expect(EnvVersionResolver::make()->resolve())->toBe('1.19.16');
});

it('reads the variable it was given a name for', function () {
    $_SERVER['APP_RELEASE'] = 'a1b2c3d';

    expect(EnvVersionResolver::make('APP_RELEASE')->resolve())->toBe('a1b2c3d');
});

it('returns null when the variable is not set', function () {
    expect(EnvVersionResolver::make('NOTHING_HERE_AT_ALL')->resolve())->toBeNull();
});

it('returns null for a non-string value', function () {
    $_SERVER['APP_VERSION'] = 'true';

    // Laravel's environment repository casts "true" to a bool, which for us
    // means the source gave something that is not a string, so the chain moves
    // on.
    expect(Env::get('APP_VERSION'))->toBeBool()
        ->and(EnvVersionResolver::make()->resolve())->toBeNull();
});

it('looks at APP_VERSION by default', function () {
    $_SERVER['APP_VERSION'] = '2.0.0';
    $_SERVER['APP_RELEASE'] = '3.0.0';

    expect(EnvVersionResolver::make()->resolve())->toBe('2.0.0');
});
