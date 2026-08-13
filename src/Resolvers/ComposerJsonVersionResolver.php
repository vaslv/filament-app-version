<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Resolvers;

use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

/**
 * Reads the "version" field from the application's composer.json.
 *
 * The handiest source when the single point of truth about the version has to
 * live in the repository: the release bump is made in one place and reaches
 * the UI on its own.
 *
 * Throws nothing. No file, an unreadable file, broken JSON, no key, a
 * non-string value: all of those are null, meaning "the source gave nothing".
 * A broken composer.json must not take the admin panel down.
 */
final class ComposerJsonVersionResolver implements VersionResolver
{
    public function __construct(
        private readonly ?string $path = null,
    ) {}

    public static function make(?string $path = null): self
    {
        return new self($path);
    }

    public function resolve(): ?string
    {
        $path = $this->path ?? base_path('composer.json');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        if (! is_array($data)) {
            return null;
        }

        $version = $data['version'] ?? null;

        return is_string($version) ? $version : null;
    }
}
