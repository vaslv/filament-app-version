<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Resolvers;

use Illuminate\Support\Env;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

/**
 * Reads the version from the PROCESS environment.
 *
 * WARNING: this resolver does not work everywhere, and that is not a bug, it
 * is how Laravel behaves.
 *
 * Under `php artisan config:cache` the framework does not load the .env file
 * at all: LoadEnvironmentVariables returns early. So a variable that lives
 * ONLY in .env is invisible at runtime and the resolver returns null,
 * silently, and only on production, where the config is cached. On a local
 * machine it all works.
 *
 * It does see the variable when the variable came from the process
 * environment: `environment:` or `env_file:` in compose, a Deployment in
 * Kubernetes, Heroku config vars, an `export` in a systemd unit. It does not
 * see it when .env is mounted into the container as a separate file and read
 * only while the config cache is being built, the usual Docker deployment
 * layout, where `php artisan optimize` runs at start-up.
 *
 * That is why it is not in the DEFAULT chain. The supported way to carry a
 * variable through is config/filament-app-version.php, where env() is called
 * inside the config file and the value freezes at config:cache time. This
 * resolver is for people who know for certain that the variable is in the
 * process environment.
 */
final class EnvVersionResolver implements VersionResolver
{
    public function __construct(
        private readonly string $key = 'APP_VERSION',
    ) {}

    public static function make(string $key = 'APP_VERSION'): self
    {
        return new self($key);
    }

    public function resolve(): ?string
    {
        $value = Env::get($this->key);

        return is_string($value) ? $value : null;
    }
}
