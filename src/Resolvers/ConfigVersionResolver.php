<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Resolvers;

use Illuminate\Support\Facades\Config;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

/**
 * Reads the version from the config by dot path.
 *
 * This is the only correct way to support an environment variable. At runtime
 * env() returns null once the config is cached: under config:cache Laravel
 * does not load .env at all. So env() is called INSIDE
 * config/filament-app-version.php, while the cache is being built, and at
 * runtime it is config() that gets read.
 *
 * A non-string value (an array, a number, a bool) counts as absent: the chain
 * moves on to the next source.
 */
final class ConfigVersionResolver implements VersionResolver
{
    public function __construct(
        private readonly string $key,
    ) {}

    public static function make(string $key): self
    {
        return new self($key);
    }

    public function resolve(): ?string
    {
        $value = Config::get($this->key);

        return is_string($value) ? $value : null;
    }
}
