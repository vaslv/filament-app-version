<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Vaslv\FilamentAppVersion\AppVersionManager;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;
use Vaslv\FilamentAppVersion\Facades\AppVersion;
use Vaslv\FilamentAppVersion\Resolvers\ComposerJsonVersionResolver;
use Vaslv\FilamentAppVersion\Resolvers\ConfigVersionResolver;

/**
 * Checks the promise of the package: after composer require and without a
 * single line of configuration the version resolves on its own, provided the
 * application already keeps it in config('app.version') or in the version
 * field of its own composer.json.
 */
it('takes the package config as the first priority', function () {
    config()->set('filament-app-version.version', '9.9.9');
    config()->set('app.version', '1.19.16');
    $this->writeComposerJson('{"version":"0.0.1"}');

    expect(AppVersion::get())->toBe('9.9.9');
});

it('falls back to config(app.version), the Laravel convention', function () {
    config()->set('filament-app-version.version', null);
    config()->set('app.version', '1.19.16');
    $this->writeComposerJson('{"version":"0.0.1"}');

    expect(AppVersion::get())->toBe('1.19.16');
});

it('falls back to composer.json when there is no config value', function () {
    config()->set('filament-app-version.version', null);
    config()->set('app.version', null);
    $this->writeComposerJson('{"version":"0.0.1"}');

    expect(AppVersion::get())->toBe('0.0.1');
});

it('returns the fallback when there is no version anywhere', function () {
    config()->set('filament-app-version.version', null);
    config()->set('app.version', null);

    // The path to composer.json is set explicitly. Otherwise the test rested
    // on ANOTHER test having deleted the file from the Testbench skeleton,
    // which made it depend on an execution order it does not control.
    config()->set('filament-app-version.resolvers', [
        [ConfigVersionResolver::class, 'filament-app-version.version'],
        [ConfigVersionResolver::class, 'app.version'],
        [ComposerJsonVersionResolver::class, '/nope/composer.json'],
    ]);

    expect(AppVersion::get())->toBe('dev');
});

it('memoises the result within a request', function () {
    config()->set('filament-app-version.version', '1.0.0');

    $manager = app(AppVersionManager::class);

    expect($manager->get())->toBe('1.0.0');

    // Change the source after the first resolve: memoisation has to hold the
    // old value.
    config()->set('filament-app-version.version', '2.0.0');

    expect($manager->get())->toBe('1.0.0')
        ->and(app(AppVersionManager::class))->toBe($manager);
});

it('skips a broken resolver entry in the config when debug is off', function () {
    config()->set('app.debug', false);
    config()->set('filament-app-version.resolvers', [
        'NoSuchClass',
        [ConfigVersionResolver::class, 'app.version'],
    ]);
    config()->set('app.version', '1.19.16');

    expect(app(AppVersionManager::class)->get())->toBe('1.19.16');
});

it('throws on a broken resolver entry when debug is on', function () {
    config()->set('app.debug', true);
    config()->set('filament-app-version.resolvers', ['NoSuchClass']);

    expect(fn () => app(AppVersionManager::class)->get())
        ->toThrow(InvalidArgumentException::class);
});

// The "the class exists but does not implement the contract" branch was
// covered by nothing: both config checks fed in a name that does not exist and
// tripped the earlier class_exists() branch instead. Mutation proved it:
// replacing the throw with a quiet `return null` left the suite green.
it('throws when the class exists but does not implement the contract', function () {
    config()->set('app.debug', true);
    config()->set('filament-app-version.resolvers', [stdClass::class]);

    expect(fn () => app(AppVersionManager::class)->get())
        ->toThrow(InvalidArgumentException::class, 'must implement');
});

it('skips a class without the contract when debug is off', function () {
    config()->set('app.debug', false);
    config()->set('filament-app-version.resolvers', [
        stdClass::class,
        [ConfigVersionResolver::class, 'app.version'],
    ]);
    config()->set('app.version', '1.19.16');

    expect(app(AppVersionManager::class)->get())->toBe('1.19.16');
});

// The contract is checked BEFORE the constructor: a typo that happens to match
// an existing class must not run that class's side effects.
it('does not call the constructor of a class that does not implement the contract', function () {
    config()->set('app.debug', false);
    config()->set('filament-app-version.resolvers', [SideEffectClass::class]);

    app(AppVersionManager::class)->get();

    expect(SideEffectClass::$constructed)->toBeFalse();
});

it('logs a warning about a broken resolver entry when debug is off', function () {
    config()->set('app.debug', false);
    config()->set('filament-app-version.resolvers', ['NoSuchClass']);

    Log::spy();

    app(AppVersionManager::class)->get();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'invalid resolver entry'));
});

it('survives a non-standard resolvers value in the config', function (mixed $entries) {
    config()->set('filament-app-version.resolvers', $entries);
    config()->set('filament-app-version.fallback', 'dev');

    expect(app(AppVersionManager::class)->get())->toBe('dev');
})->with(['string' => 'ConfigVersionResolver', 'number' => 42, 'bool' => true]);

// A resolver with a dependency in its constructor is built by the container.
// With new it could not be declared in the config at all: the entry was
// silently rejected and the chain fell through to the fallback.
it('builds a resolver with dependencies through the container', function () {
    config()->set('app.version', '1.19.16');
    config()->set('filament-app-version.resolvers', [InjectedResolver::class]);

    expect(app(AppVersionManager::class)->get())->toBe('1.19.16');
});

final class SideEffectClass
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
    }
}

final class InjectedResolver implements VersionResolver
{
    public function __construct(private readonly Repository $config) {}

    public function resolve(): ?string
    {
        $value = $this->config->get('app.version');

        return is_string($value) ? $value : null;
    }
}
