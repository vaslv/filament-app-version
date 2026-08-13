<?php

declare(strict_types=1);

use Vaslv\FilamentAppVersion\Resolvers\FileVersionResolver;

function versionFile(string $contents, string $name = 'VERSION'): string
{
    $path = sys_get_temp_dir().'/filament-app-version-test-'.uniqid().'-'.$name;

    file_put_contents($path, $contents);

    test()->beforeApplicationDestroyed(static fn () => @unlink($path));

    return $path;
}

it('reads the whole file', function () {
    $path = versionFile('1.19.16');

    expect(FileVersionResolver::make($path)->resolve())->toBe('1.19.16');
});

it('strips the newline echo left behind', function () {
    // Exactly what RUN echo "$CI_COMMIT_TAG" > VERSION produces.
    $path = versionFile("1.19.16\n");

    expect(FileVersionResolver::make($path)->resolve())->toBe('1.19.16');
});

it('returns null for an empty file', function (string $contents) {
    $path = versionFile($contents);

    expect(FileVersionResolver::make($path)->resolve())->toBeNull();
})->with(['empty' => [''], 'whitespace' => ["  \n\t "]]);

it('returns null when the file does not exist', function () {
    expect(FileVersionResolver::make('/nope/VERSION')->resolve())->toBeNull();
});

it('returns null when the path is a directory', function () {
    expect(FileVersionResolver::make(sys_get_temp_dir())->resolve())->toBeNull();
});

it('reads JSON by dot path', function () {
    $path = versionFile('{"build":{"version":"1.19.16","sha":"a1b2c3d"}}', 'build.json');

    expect(FileVersionResolver::make($path)->key('build.version')->resolve())->toBe('1.19.16');
});

it('reads JSON by a top-level key', function () {
    $path = versionFile('{"version":"1.19.16"}', 'build.json');

    expect(FileVersionResolver::make($path)->key('version')->resolve())->toBe('1.19.16');
});

it('does not throw on broken JSON in key mode', function () {
    $path = versionFile('{"version": ', 'build.json');

    expect(FileVersionResolver::make($path)->key('version')->resolve())->toBeNull();
});

it('returns null when the key is missing from the JSON', function () {
    $path = versionFile('{"name":"acme"}', 'build.json');

    expect(FileVersionResolver::make($path)->key('version')->resolve())->toBeNull();
});

it('returns null when the value at the key is not a string', function () {
    $path = versionFile('{"version":{"major":1}}', 'build.json');

    expect(FileVersionResolver::make($path)->key('version')->resolve())->toBeNull();
});

// The key became the second constructor argument, so JSON mode can finally be
// declared from the config, where an entry is [class-string, ...args].
it('accepts the key as the second constructor argument', function () {
    $path = versionFile('{"build":{"version":"1.19.16"}}', 'build.json');

    expect(FileVersionResolver::make($path, 'build.version')->resolve())->toBe('1.19.16')
        ->and((new FileVersionResolver($path, 'build.version'))->resolve())->toBe('1.19.16');
});

// key() returns a NEW instance: the other five resolvers are immutable, and a
// shared instance changing under your feet was the one exception here.
it('key() does not mutate the original instance', function () {
    $path = versionFile('{"version":"1.19.16"}', 'build.json');

    $plain = FileVersionResolver::make($path);
    $json = $plain->key('version');

    expect($json)->not->toBe($plain)
        ->and($json->resolve())->toBe('1.19.16')
        // The original is still in whole-file mode.
        ->and($plain->resolve())->toBe('{"version":"1.19.16"}');
});
