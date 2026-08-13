<?php

declare(strict_types=1);

use Vaslv\FilamentAppVersion\AppVersionManager;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;
use Vaslv\FilamentAppVersion\Internal\VersionChain;

/**
 * The rules the package promises to the outside world. A promise with no test
 * behind it is a wish.
 */
arch('the package never shells out')
    ->expect('Vaslv\FilamentAppVersion')
    ->not->toUse(['shell_exec', 'exec', 'system', 'proc_open', 'passthru', 'popen', 'eval']);

arch('the package leaves no debug calls behind')
    ->expect('Vaslv\FilamentAppVersion')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray', 'print_r']);

arch('strict types are declared everywhere')
    ->expect('Vaslv\FilamentAppVersion')
    ->toUseStrictTypes();

arch('every resolver implements the contract')
    ->expect('Vaslv\FilamentAppVersion\Resolvers')
    ->toImplement(VersionResolver::class);

arch('the contract stays an interface')
    ->expect(VersionResolver::class)
    ->toBeInterface();

// Chain composition is not part of the public contract, and the only thing
// that says so is the tag in the docblock. A refactor that loses it silently
// promotes the class to public API.
//
// AppVersionManager is DELIBERATELY absent here: it sits in the signature of a
// protected plugin method, it serves as a container key and as the facade
// accessor, so marking it internal was a lie.
it('keeps chain composition marked internal', function (string $class) {
    $docblock = (new ReflectionClass($class))->getDocComment();

    expect($docblock)->toBeString()
        ->and($docblock)->toContain('@internal');
})->with([
    VersionChain::class,
]);

// The facade can be faked with the standard Laravel idiom. While the accessor
// was final, AppVersion::shouldReceive() threw Mockery\Exception, and the
// documented entry point was untestable in a consumer's own tests.
it('leaves the facade fakeable', function () {
    expect((new ReflectionClass(AppVersionManager::class))->isFinal())->toBeFalse();
});
