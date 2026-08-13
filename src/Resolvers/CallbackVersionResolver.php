<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Resolvers;

use Closure;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

/**
 * Any source of a version you like: Redis, Vault, a table, whatever.
 *
 *     CallbackVersionResolver::make(fn () => Cache::get('release'))
 *
 * Lives in the panel provider only. A closure cannot go into a config file,
 * because it will not survive config:cache: Laravel does not serialize a
 * Closure, and on production that is a fatal error while the cache is being
 * built, not a degradation.
 *
 * An exception thrown by the closure is deliberately NOT caught here. It is
 * the consumer's code, and its fate belongs to the chain under the general
 * rule: rethrow when debug is on, log a warning and carry on in production.
 * Swallowing it quietly right here would hide someone else's typo.
 */
final class CallbackVersionResolver implements VersionResolver
{
    public function __construct(
        private readonly Closure $callback,
    ) {}

    public static function make(Closure $callback): self
    {
        return new self($callback);
    }

    public function resolve(): ?string
    {
        $value = ($this->callback)();

        return is_string($value) ? $value : null;
    }
}
