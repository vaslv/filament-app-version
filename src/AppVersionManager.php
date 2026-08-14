<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion;

use Closure;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;
use Vaslv\FilamentAppVersion\Internal\VersionChain;

/**
 * The store for everything that is resolved once per request.
 *
 * Registered through $app->scoped(), NOT singleton(). The difference only shows
 * under Octane/FrankenPHP, where the container outlives the request: a
 * singleton would freeze the values for the whole lifetime of the worker, and a
 * CallbackVersionResolver reading from Redis would keep handing back a stale
 * one until a restart. scoped() is reset between requests.
 *
 * The key is mandatory because there can be more than one chip, on different
 * panels or two on the same one, and each has its own chain and its own
 * visibility rule. Memoising without a key would mean the first chip rendered
 * decides the version and the visibility for all the rest.
 *
 * The class is NOT marked internal and NOT final, though it used to be both.
 * The marking was a lie: the class stands in the signature of the protected
 * method AppVersionPlugin::versionFor(), serves as the binding key in the
 * container and as the accessor of the AppVersion facade, so it could not have
 * been renamed in any case. And final broke the standard Laravel idiom:
 * AppVersion::shouldReceive() and AppVersion::spy() threw Mockery\Exception,
 * which means a documented entry point could not be faked in a consumer's
 * tests.
 *
 * Only get() is contractual. The other methods are public because
 * AppVersionPlugin lives in a different namespace.
 */
class AppVersionManager
{
    /** @var array<string, string|null> */
    private array $versions = [];

    /** @var array<string, bool> */
    private array $visibility = [];

    /** @var array<string, string> */
    private array $html = [];

    /** @var array<string, true> */
    private array $once = [];

    /**
     * @param  list<VersionResolver>  $resolvers
     */
    public function __construct(
        private readonly array $resolvers,
        private readonly ?string $fallback,
    ) {}

    /**
     * The version from the global chain in config/filament-app-version.php.
     */
    public function get(): ?string
    {
        return $this->version('global', $this->resolvers, $this->fallback);
    }

    /**
     * The version from an arbitrary chain, memoised under its own key.
     *
     * @param  array<int, mixed>  $resolvers
     */
    public function version(string $key, array $resolvers, ?string $fallback): ?string
    {
        // array_key_exists, not ??=: null is a valid result (fallback(null)
        // means "render no chip at all"), and without this check the chain
        // would be re-run on every call.
        if (! array_key_exists($key, $this->versions)) {
            $this->versions[$key] = (new VersionChain($resolvers, $fallback))->resolve();
        }

        return $this->versions[$key];
    }

    /**
     * The result of the visibility check, memoised under its own key.
     *
     * The chip renders on two hooks at once (the topbar and the sidebar
     * header), so without memoisation a closure like fn () => $user->can('...')
     * would hit the permission check twice on every page of the panel.
     */
    public function visible(string $key, Closure $resolve): bool
    {
        return $this->visibility[$key] ??= (bool) $resolve();
    }

    /**
     * The finished chip markup, built once per request.
     *
     * Both default hooks emit a byte-for-byte identical string, so without this
     * the second pass rebuilt the ComponentAttributeBag and rendered the Blade
     * view again. The saving on its own is modest (~30 µs per request), but the
     * tooltip is computed here too: a tooltip(Closure) reading from Redis was
     * still making two network round trips per page instead of one, even though
     * that is exactly what the visibility memoisation next to it exists for.
     */
    public function html(string $key, Closure $build): string
    {
        if (! array_key_exists($key, $this->html)) {
            $html = $build();

            $this->html[$key] = is_string($html) ? $html : '';
        }

        return $this->html[$key];
    }

    /**
     * True the first time it is called with a key in this request, false after.
     *
     * For markup that belongs to the page rather than to a chip: a panel may
     * carry two plugin instances, an application version and an API version
     * say, and both would emit the same <style> into the same sidebar header.
     * Keyed and per-request for the same reason everything else here is —
     * under Octane the container outlives the request, and a flag that did not
     * reset would drop the style for every request but the worker's first.
     */
    public function once(string $key): bool
    {
        if (isset($this->once[$key])) {
            return false;
        }

        $this->once[$key] = true;

        return true;
    }

    /**
     * @return list<VersionResolver>
     */
    public function resolvers(): array
    {
        return $this->resolvers;
    }

    public function fallback(): ?string
    {
        return $this->fallback;
    }
}
