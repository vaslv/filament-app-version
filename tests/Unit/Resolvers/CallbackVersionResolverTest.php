<?php

declare(strict_types=1);

use Vaslv\FilamentAppVersion\Internal\VersionChain;
use Vaslv\FilamentAppVersion\Resolvers\CallbackVersionResolver;

it('returns what the closure returned', function () {
    expect(CallbackVersionResolver::make(fn (): string => '1.19.16')->resolve())->toBe('1.19.16');
});

it('returns null when the closure returned null', function () {
    expect(CallbackVersionResolver::make(fn (): ?string => null)->resolve())->toBeNull();
});

it('returns null for a non-string result', function () {
    expect(CallbackVersionResolver::make(fn (): int => 42)->resolve())->toBeNull();
});

it('calls the closure lazily, only on resolve', function () {
    $calls = 0;

    $resolver = CallbackVersionResolver::make(function () use (&$calls): string {
        $calls++;

        return '1.0.0';
    });

    expect($calls)->toBe(0);

    $resolver->resolve();

    expect($calls)->toBe(1);
});

it('hands the exception to the chain instead of swallowing it', function () {
    config()->set('app.debug', false);

    $chain = new VersionChain([
        CallbackVersionResolver::make(fn () => throw new RuntimeException('redis down')),
        CallbackVersionResolver::make(fn (): string => '1.19.16'),
    ]);

    // The chain decides what happens to the exception by the common rule: in
    // production it logs a warning and moves on. The resolver catches nothing
    // itself.
    expect($chain->resolve())->toBe('1.19.16');
});
