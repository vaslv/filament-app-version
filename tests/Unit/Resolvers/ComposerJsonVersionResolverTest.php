<?php

declare(strict_types=1);

use Vaslv\FilamentAppVersion\Resolvers\ComposerJsonVersionResolver;

it('reads the version field', function () {
    $path = $this->writeComposerJson('{"name":"acme/app","version":"1.19.16"}');

    expect(ComposerJsonVersionResolver::make($path)->resolve())->toBe('1.19.16');
});

it('looks at the application composer.json by default', function () {
    $this->writeComposerJson('{"version":"2.0.0"}');

    expect(ComposerJsonVersionResolver::make()->resolve())->toBe('2.0.0');
});

it('returns null when the file does not exist', function () {
    expect(ComposerJsonVersionResolver::make('/nope/composer.json')->resolve())->toBeNull();
});

it('returns null when there is no version key', function () {
    $path = $this->writeComposerJson('{"name":"acme/app"}');

    expect(ComposerJsonVersionResolver::make($path)->resolve())->toBeNull();
});

it('does not throw on broken JSON', function () {
    $path = $this->writeComposerJson('{"name": "acme/app", ');

    expect(ComposerJsonVersionResolver::make($path)->resolve())->toBeNull();
});

it('returns null when version is not a string', function () {
    $path = $this->writeComposerJson('{"version":{"major":1}}');

    expect(ComposerJsonVersionResolver::make($path)->resolve())->toBeNull();
});

it('does not throw when the JSON is not an object', function () {
    $path = $this->writeComposerJson('"just a string"');

    expect(ComposerJsonVersionResolver::make($path)->resolve())->toBeNull();
});
