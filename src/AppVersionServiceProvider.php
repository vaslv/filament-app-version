<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

final class AppVersionServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-app-version')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations();
    }

    public function packageRegistered(): void
    {
        // scoped(), not singleton(): under Octane the container outlives the
        // request, and a singleton would freeze the version for the whole
        // lifetime of the worker. See AppVersionManager.
        $this->app->scoped(
            AppVersionManager::class,
            fn (Application $app): AppVersionManager => new AppVersionManager(
                $this->globalResolvers($app),
                $this->globalFallback($app),
            ),
        );
    }

    private function globalFallback(Application $app): ?string
    {
        $fallback = $this->config($app)->get('filament-app-version.fallback', 'dev');

        return is_string($fallback) ? $fallback : null;
    }

    private function config(Application $app): Repository
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);

        return $config;
    }

    /**
     * Turns the chain declared in the config into resolver objects.
     *
     * Config entries are deliberately declarative (a string, or [class,
     * ...args]) so that the config survives config:cache. The parsing happens
     * here.
     *
     * @return list<VersionResolver>
     */
    private function globalResolvers(Application $app): array
    {
        $config = $this->config($app);

        // mergeConfigFrom is skipped entirely when the application config is
        // already cached. A consumer who built the cache BEFORE installing the
        // package got an empty chain and a permanent "vdev", without a single
        // hint as to why. A missing key and an empty array are two different
        // things here: the second one means "the chain was disabled on
        // purpose", and that we respect.
        $entries = $config->has('filament-app-version.resolvers')
            ? $config->get('filament-app-version.resolvers')
            : $this->shippedResolvers();

        if (! is_array($entries)) {
            return [];
        }

        $resolvers = [];

        foreach ($entries as $entry) {
            $resolver = $this->makeResolver($app, $entry);

            if ($resolver instanceof VersionResolver) {
                $resolvers[] = $resolver;
            }
        }

        return $resolvers;
    }

    /**
     * The chain from the config file that ships inside the package itself.
     *
     * Read directly so that the default is declared in exactly one place,
     * config/filament-app-version.php, and cannot drift apart from a copy of it
     * in the code.
     *
     * @return array<int, mixed>
     */
    private function shippedResolvers(): array
    {
        /** @var array{resolvers?: mixed} $shipped */
        $shipped = require __DIR__.'/../config/filament-app-version.php';

        $entries = $shipped['resolvers'] ?? [];

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * Parses a single config entry into a resolver.
     *
     * A broken entry is a configuration error, not a source failure, so the
     * policy is the same as in VersionChain::resolveOne(): rethrow when debug
     * mode is on, log a warning and skip otherwise. A typo in the config has no
     * business taking production down over a version chip. If you change the
     * policy here, change it there as well.
     */
    private function makeResolver(Application $app, mixed $entry): ?VersionResolver
    {
        try {
            $class = is_array($entry) ? ($entry[0] ?? null) : $entry;
            $arguments = is_array($entry) ? array_values(array_slice($entry, 1)) : [];

            if (! is_string($class) || ! class_exists($class)) {
                throw new InvalidArgumentException(sprintf(
                    'filament-app-version: resolver entry must be a class-string or [class-string, ...args], got %s.',
                    get_debug_type($entry),
                ));
            }

            // The contract is checked BEFORE the constructor. Otherwise a typo
            // in the name that happened to match an existing class ran that
            // class's constructor with all of its side effects and only then
            // got rejected.
            if (! is_subclass_of($class, VersionResolver::class)) {
                throw new InvalidArgumentException(sprintf(
                    'filament-app-version: %s must implement %s.',
                    $class,
                    VersionResolver::class,
                ));
            }

            // With no positional arguments the container builds the resolver:
            // otherwise a third-party implementation with dependencies in its
            // constructor simply cannot be declared in the config, because new
            // bypasses the container, the entry is silently rejected and the
            // chain falls through to the fallback. With arguments the container
            // is no use: makeWith() takes named ones only.
            $resolver = $arguments === []
                ? $app->make($class)
                : new $class(...$arguments);

            if (! $resolver instanceof VersionResolver) {
                throw new InvalidArgumentException(sprintf(
                    'filament-app-version: %s must implement %s.',
                    $class,
                    VersionResolver::class,
                ));
            }

            return $resolver;
        } catch (Throwable $e) {
            if (App::hasDebugModeEnabled()) {
                throw $e;
            }

            Log::warning('[filament-app-version] invalid resolver entry in config, skipping', [
                'entry' => is_array($entry) ? ($entry[0] ?? null) : $entry,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
