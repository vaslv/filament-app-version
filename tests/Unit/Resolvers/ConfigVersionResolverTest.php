<?php

declare(strict_types=1);

use Vaslv\FilamentAppVersion\Resolvers\ConfigVersionResolver;

it('reads a string by dot path', function () {
    config()->set('app.version', '1.19.16');

    expect(ConfigVersionResolver::make('app.version')->resolve())->toBe('1.19.16');
});

it('returns null when the key is missing', function () {
    expect(ConfigVersionResolver::make('nothing.here')->resolve())->toBeNull();
});

it('returns null for a non-string value', function (mixed $value) {
    config()->set('app.version', $value);

    expect(ConfigVersionResolver::make('app.version')->resolve())->toBeNull();
})->with([
    'array' => [['1.0.0']],
    'int' => [20260813],
    'float' => [1.19],
    'bool' => [true],
]);
