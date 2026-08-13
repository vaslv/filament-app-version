<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Contracts;

/**
 * A single source of the application version.
 *
 * A resolver returns the value as it found it, or null when the source gave
 * nothing. Trimming whitespace, rejecting empty strings and moving on to the
 * next source are VersionChain's job, not the resolver's.
 *
 * An implementation must NOT throw on an expected absence (no file, no key,
 * broken JSON): that is a null. Throw only on something genuinely unforeseen;
 * the chain catches that and decides what to do by debug mode.
 */
interface VersionResolver
{
    public function resolve(): ?string;
}
