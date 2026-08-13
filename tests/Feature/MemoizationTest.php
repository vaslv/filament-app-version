<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Vaslv\FilamentAppVersion\AppVersionManager;
use Vaslv\FilamentAppVersion\AppVersionPlugin;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

/**
 * The chip renders on two hooks at once, so both the version and the
 * visibility rule have to be computed once per request. Otherwise a closure of
 * the form fn () => $user->can('...') hits the permission check twice on every
 * page of the admin panel.
 *
 * The memoisation lives in AppVersionManager, which is bound through scoped().
 * Under Octane the container outlives the request, and a singleton would
 * freeze the value for the whole lifetime of the worker.
 */
function countingResolver(string $value): VersionResolver
{
    return new class($value) implements VersionResolver
    {
        public int $calls = 0;

        public function __construct(private readonly string $value) {}

        public function resolve(): ?string
        {
            $this->calls++;

            return $this->value;
        }
    };
}

beforeEach(function () {
    $this->actingAs(new User);
});

/**
 * @return list<Closure>
 */
function hookClosures(Panel $panel): array
{
    return panelHooks($panel);
}

it('resolves the version once for both hooks', function () {
    $resolver = countingResolver('1.19.16');

    $panel = Panel::make()->id('test');
    AppVersionPlugin::make()->resolvers([$resolver])->register($panel);

    $closures = hookClosures($panel);

    expect($closures)->toHaveCount(2);

    foreach ($closures as $closure) {
        expect($closure())->toContain('v1.19.16');
    }

    expect($resolver->calls)->toBe(1);
});

it('evaluates the visibility rule once for both hooks', function () {
    $calls = 0;

    $panel = Panel::make()->id('test');
    AppVersionPlugin::make()
        ->visible(function () use (&$calls): bool {
            $calls++;

            return true;
        })
        ->register($panel);

    foreach (hookClosures($panel) as $closure) {
        $closure();
    }

    expect($calls)->toBe(1);
});

it('does not mix up the memoisation of two panels', function () {
    $first = countingResolver('1.0.0');
    $second = countingResolver('2.0.0');

    $panelA = Panel::make()->id('a');
    AppVersionPlugin::make()->resolvers([$first])->register($panelA);

    $panelB = Panel::make()->id('b');
    AppVersionPlugin::make()->resolvers([$second])->register($panelB);

    expect(hookClosures($panelA)[0]())->toContain('v1.0.0')
        ->and(hookClosures($panelB)[0]())->toContain('v2.0.0');
});

it('scopes the manager to the request, not to the process', function () {
    $manager = app(AppVersionManager::class);

    expect(app(AppVersionManager::class))->toBe($manager);

    // This is exactly what Octane does between requests: it resets the scoped
    // instances. After the reset the manager has to be a new one, otherwise
    // the version freezes for the whole lifetime of the worker.
    $this->app->forgetScopedInstances();

    expect(app(AppVersionManager::class))->not->toBe($manager);
});

// array_key_exists rather than ??= in AppVersionManager::version() exists for
// exactly this case: null is a valid result (fallback(null) means "draw no
// chip"), and with ??= the chain would rerun on every hook. Mutation proved
// that until now nothing guarded this.
it('memoises the absence of a version too, not only a version it found', function () {
    $resolver = countingResolver('');

    $panel = Panel::make()->id('test');
    AppVersionPlugin::make()->resolvers([$resolver])->fallback(null)->register($panel);

    foreach (hookClosures($panel) as $closure) {
        expect($closure())->toBe('');
    }

    expect($resolver->calls)->toBe(1);
});

it('evaluates a negative visibility rule once as well', function () {
    $calls = 0;

    $panel = Panel::make()->id('test');
    AppVersionPlugin::make()
        ->visible(function () use (&$calls): bool {
            $calls++;

            return false;
        })
        ->register($panel);

    foreach (hookClosures($panel) as $closure) {
        expect($closure())->toBe('');
    }

    expect($calls)->toBe(1);
});

// Two chips on ONE panel: the application version and the API version side by
// side. Filament runs register() for every ->plugin(), even when the ids are
// the same, so both sets of hooks stay alive. With a key made of the panel id
// alone the second chip showed the version of the first.
it('does not mix two plugin instances on one panel', function () {
    $first = countingResolver('1.0.0');
    $second = countingResolver('2.0.0');

    $panel = Panel::make()->id('admin');
    AppVersionPlugin::make()->resolvers([$first])->register($panel);
    AppVersionPlugin::make()->resolvers([$second])->register($panel);

    $html = implode('', array_map(fn (Closure $closure): string => $closure(), hookClosures($panel)));

    expect($html)->toContain('v1.0.0')
        ->and($html)->toContain('v2.0.0');
});

it('does not mix the visibility of two instances on one panel', function () {
    $panel = Panel::make()->id('admin');

    AppVersionPlugin::make()->visible(true)->register($panel);
    AppVersionPlugin::make()
        ->visible(false)
        ->resolvers([countingResolver('2.0.0-HIDDEN')])
        ->register($panel);

    $html = implode('', array_map(fn (Closure $closure): string => $closure(), hookClosures($panel)));

    expect($html)->not->toContain('HIDDEN');
});

// The markup is built once for both hooks. Before, only the version and the
// visibility were memoised while a tooltip closure was evaluated on every
// hook, so tooltip(fn () => Cache::get(...)) made two round trips to Redis per
// page, even though the visibility memoisation right next to it exists for
// exactly that reason.
it('builds the markup once for both hooks', function () {
    $calls = 0;

    $panel = Panel::make()->id('test');
    AppVersionPlugin::make()
        ->tooltip(function () use (&$calls): string {
            $calls++;

            return 'Tooltip';
        })
        ->register($panel);

    $rendered = array_map(fn (Closure $closure): string => $closure(), hookClosures($panel));

    expect($rendered)->toHaveCount(2)
        ->and($rendered[0])->toBe($rendered[1])
        ->and($calls)->toBe(1);
});
