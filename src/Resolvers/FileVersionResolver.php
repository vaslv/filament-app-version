<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Resolvers;

use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

/**
 * Reads the version from a file laid down by the build.
 *
 * The Docker image classic: `RUN echo "$CI_COMMIT_TAG" > /app/VERSION`. The
 * file goes into the image along with the code, so the value is available
 * without .env and without access to .git, which is normally left out of the
 * image.
 *
 * Two modes:
 *
 *   FileVersionResolver::make(base_path('VERSION'))
 *       → the whole file as the value, edge spaces and newlines trimmed
 *
 *   FileVersionResolver::make(base_path('build.json'))->key('version')
 *       → JSON, the value at a dot path
 *
 * Throws nothing: no file, an unreadable file, broken JSON, no key, a
 * non-string value: all of those are null, meaning "the source gave nothing".
 */
final class FileVersionResolver implements VersionResolver
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $key = null,
    ) {}

    public static function make(string $path, ?string $key = null): self
    {
        return new self($path, $key);
    }

    /**
     * Switches the resolver into JSON mode and sets the dot path to the value.
     *
     * Returns a NEW instance instead of mutating the current one. The other
     * five resolvers are readonly, and this was the only one whose shared
     * instance changed under your feet. The key became the second constructor
     * argument at the same time, so JSON mode is finally reachable from the
     * config too, where the entry is declared as
     * [FileVersionResolver::class, '/app/build.json', 'version'].
     */
    public function key(string $key): self
    {
        return new self($this->path, $key);
    }

    public function resolve(): ?string
    {
        $contents = $this->read();

        if ($contents === null) {
            return null;
        }

        if ($this->key === null) {
            $value = trim($contents);

            return $value === '' ? null : $value;
        }

        $data = json_decode($contents, true);

        if (! is_array($data)) {
            return null;
        }

        $value = data_get($data, $this->key);

        return is_string($value) ? $value : null;
    }

    private function read(): ?string
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        return $contents === false ? null : $contents;
    }
}
