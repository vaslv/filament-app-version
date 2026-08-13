<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;
use Vaslv\FilamentAppVersion\Internal\VersionChain;

/**
 * Stub resolver: returns the value it was given, or throws the exception it was
 * given. Counts its calls, so a test can check that the chain stopped.
 */
function stubResolver(string|Throwable|null $result): VersionResolver
{
    return new class($result) implements VersionResolver
    {
        public int $calls = 0;

        public function __construct(private readonly string|Throwable|null $result) {}

        public function resolve(): ?string
        {
            $this->calls++;

            if ($this->result instanceof Throwable) {
                throw $this->result;
            }

            return $this->result;
        }
    };
}

it('returns the first non-empty value', function () {
    $chain = new VersionChain([
        stubResolver(null),
        stubResolver('1.19.16'),
        $never = stubResolver('2.0.0'),
    ]);

    expect($chain->resolve())->toBe('1.19.16')
        ->and($never->calls)->toBe(0);
});

it('skips an empty string and a whitespace-only string', function (string $empty) {
    $chain = new VersionChain([
        stubResolver($empty),
        stubResolver('1.19.16'),
    ]);

    expect($chain->resolve())->toBe('1.19.16');
})->with(['', '   ', "\n\t "]);

it('trims whitespace at both ends', function () {
    $chain = new VersionChain([stubResolver("  1.19.16\n")]);

    expect($chain->resolve())->toBe('1.19.16');
});

it('does not strip a leading v: the package shows the version, it does not rewrite it', function () {
    $chain = new VersionChain([stubResolver('v1.19.16')]);

    expect($chain->resolve())->toBe('v1.19.16');
});

it('returns the fallback when every source is empty', function () {
    $chain = new VersionChain([stubResolver(null), stubResolver('')], 'dev');

    expect($chain->resolve())->toBe('dev');
});

it('returns null when the fallback is disabled', function () {
    $chain = new VersionChain([stubResolver(null)], null);

    expect($chain->resolve())->toBeNull();
});

it('rethrows a resolver exception when debug is on', function () {
    config()->set('app.debug', true);

    $chain = new VersionChain([
        stubResolver(new RuntimeException('boom')),
        stubResolver('1.19.16'),
    ]);

    expect(fn () => $chain->resolve())->toThrow(RuntimeException::class, 'boom');
});

it('logs a warning and moves on when debug is off', function () {
    config()->set('app.debug', false);

    Log::spy();

    $chain = new VersionChain([
        stubResolver(new RuntimeException('boom')),
        stubResolver('1.19.16'),
    ]);

    expect($chain->resolve())->toBe('1.19.16');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'version resolver failed'));
});

it('returns the fallback straight away with no resolvers', function () {
    expect((new VersionChain([], 'dev'))->resolve())->toBe('dev');
});
