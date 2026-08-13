<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Internal;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

/**
 * Walks the resolvers in order and returns the first non-empty value.
 *
 *   resolvers[0] ──► null / '' / exception ───┐
 *   resolvers[1] ──► null / '' / exception ───┤
 *   resolvers[2] ──► "1.19.16" ───────────────┴──► "1.19.16"
 *   (exhausted) ──────────────────────────────────► fallback ('dev' or null)
 *
 * The value is returned as it came, with only the surrounding whitespace
 * trimmed: the package shows the version, it does not rewrite it. A leading "v"
 * is not stripped; that is the view's job, otherwise the service would be
 * silently changing a release identifier.
 *
 * An exception from a resolver is rethrown when debug mode is on and logged as
 * a warning otherwise. Loud where it can be fixed; quiet where an admin panel
 * must not be taken down over a version chip.
 *
 * @internal Chain composition is not part of the package's public contract.
 */
final class VersionChain implements VersionResolver
{
    /**
     * The list is deliberately declared as mixed, not list<VersionResolver>.
     *
     * ->resolvers([...]) on the plugin takes a plain array, and the type
     * guarantee there rested on a docblock alone, which is to say on nothing at
     * all for a consumer without PHPStan. Junk in the list produced a TypeError
     * ON ENTRY to the typed resolveOne(), outside its try, and the package took
     * the panel down at exactly the point where it promised not to. The check
     * now lives inside the try and follows the common policy: throw under
     * debug, log a warning and carry on in production.
     *
     * @param  array<int, mixed>  $resolvers
     */
    public function __construct(
        private readonly array $resolvers,
        private readonly ?string $fallback = 'dev',
    ) {}

    public function resolve(): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $value = $this->resolveOne($resolver);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            return $value;
        }

        return $this->fallback;
    }

    private function resolveOne(mixed $resolver): ?string
    {
        try {
            if (! $resolver instanceof VersionResolver) {
                throw new InvalidArgumentException(sprintf(
                    'filament-app-version: every resolver must implement %s, got %s.',
                    VersionResolver::class,
                    get_debug_type($resolver),
                ));
            }

            return $resolver->resolve();
        } catch (Throwable $e) {
            if (App::hasDebugModeEnabled()) {
                throw $e;
            }

            Log::warning('[filament-app-version] version resolver failed, skipping', [
                'resolver' => get_debug_type($resolver),
                'exception' => $e,
            ]);

            return null;
        }
    }
}
