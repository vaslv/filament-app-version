<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Facades;

use Illuminate\Support\Facades\Facade;
use Vaslv\FilamentAppVersion\AppVersionManager;

/**
 * The application version from the GLOBAL chain in
 * config/filament-app-version.php.
 *
 * A panel plugin can override the chain with ->resolvers([...]), but that
 * affects only the chip of its own panel. The facade always reads the global
 * chain, because otherwise a call like
 * ->theme(asset(...).'?v='.AppVersion::get()) would be ambiguous: it runs
 * before ->plugin(...) in the same fluent chain, at a point where the plugin
 * does not exist yet.
 *
 * The package SHOWS the version. It is not the source of truth about it: if you
 * need a release identifier for Sentry, take it from the same place the package
 * takes it from.
 *
 * Only get() is contractual. The facade proxies AppVersionManager, which has
 * other public methods; they are public only because AppVersionPlugin lives in
 * a different namespace, and they are not covered by semver.
 *
 * Faked with the standard idiom: AppVersion::shouldReceive('get'),
 * AppVersion::spy(), AppVersion::partialMock().
 *
 * @method static string|null get()
 *
 * @see AppVersionManager
 */
final class AppVersion extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AppVersionManager::class;
    }
}
