<?php

declare(strict_types=1);

use Vaslv\FilamentAppVersion\Resolvers\GitVersionResolver;

const GIT_SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

/**
 * Builds a .git directory fixture on disk.
 *
 * The fixture is real, not a mock: the resolver exists precisely to read git's
 * on-disk layout correctly, and faking that layout away would mean testing the
 * wrong thing.
 *
 * @param  array<string, string>  $files
 */
function gitFixture(array $files): string
{
    $root = sys_get_temp_dir().'/filament-app-version-git-'.uniqid();

    foreach ($files as $relative => $contents) {
        $path = $root.'/'.$relative;

        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    test()->beforeApplicationDestroyed(static function () use ($root): void {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($root);
    });

    return $root;
}

it('reads the SHA through a loose ref', function () {
    $git = gitFixture([
        '.git/HEAD' => "ref: refs/heads/main\n",
        '.git/refs/heads/main' => GIT_SHA."\n",
    ]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBe('a1b2c3d');
});

it('reads the SHA from packed-refs when there is no loose ref', function () {
    // A fresh clone, and the state after git gc: no loose files, everything
    // sits in packed-refs.
    $git = gitFixture([
        '.git/HEAD' => "ref: refs/heads/main\n",
        '.git/packed-refs' => "# pack-refs with: peeled fully-peeled sorted\n"
            ."0000000000000000000000000000000000000000 refs/heads/other\n"
            .GIT_SHA." refs/heads/main\n",
    ]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBe('a1b2c3d');
});

it('skips annotated tag dereference lines in packed-refs', function () {
    $git = gitFixture([
        '.git/HEAD' => "ref: refs/heads/main\n",
        '.git/packed-refs' => "1111111111111111111111111111111111111111 refs/tags/v1.0.0\n"
            ."^2222222222222222222222222222222222222222\n"
            .GIT_SHA." refs/heads/main\n",
    ]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBe('a1b2c3d');
});

it('reads the SHA directly on a detached HEAD', function () {
    $git = gitFixture(['.git/HEAD' => GIT_SHA."\n"]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBe('a1b2c3d');
});

it('follows gitdir when .git is a file', function () {
    // The git worktree and submodule layout: .git is a file holding a pointer.
    $real = gitFixture([
        'real/HEAD' => "ref: refs/heads/main\n",
        'real/refs/heads/main' => GIT_SHA,
    ]);

    $work = gitFixture(['.git' => 'gitdir: '.$real.'/real'."\n"]);

    expect(GitVersionResolver::make($work.'/.git')->resolve())->toBe('a1b2c3d');
});

it('returns null when there is no .git', function () {
    expect(GitVersionResolver::make('/nope/.git')->resolve())->toBeNull();
});

it('returns null when the ref is found nowhere', function () {
    $git = gitFixture(['.git/HEAD' => "ref: refs/heads/missing\n"]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBeNull();
});

it('refuses to follow a ref with directory traversal', function (string $ref) {
    $git = gitFixture([
        '.git/HEAD' => "ref: {$ref}\n",
        // refs/ has to exist: a real repository always has it, and without it
        // the traversal was turned away by the missing directory, so deleting
        // the str_contains($ref, '..') guard left the suite green. The ref
        // regex does NOT catch traversal on its own: both the dot and the
        // slash are in its character class.
        '.git/refs/heads/main' => GIT_SHA,
        'secret' => GIT_SHA,
    ]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBeNull();
})->with([
    'traversal upwards' => ['refs/../../secret'],
    'absolute path' => ['/etc/passwd'],
    'not under refs/' => ['heads/main'],
]);

it('returns null when there is garbage instead of a SHA', function () {
    $git = gitFixture([
        '.git/HEAD' => "ref: refs/heads/main\n",
        '.git/refs/heads/main' => "not a sha at all\n",
    ]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBeNull();
});

it('returns null for an empty HEAD', function () {
    $git = gitFixture(['.git/HEAD' => "\n"]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBeNull();
});

// A submodule writes a RELATIVE path, a worktree an absolute one. Verified on
// live repositories: `git submodule add` gives "gitdir: ../.git/modules/sub",
// `git worktree add` gives a full path. A relative one is counted from the
// directory holding the .git file, not from the process CWD, and submodule
// support, claimed in the docblock and in both READMEs, had never worked.
it('follows a RELATIVE gitdir: the submodule layout', function () {
    $root = gitFixture([
        'modules/app/HEAD' => "ref: refs/heads/main\n",
        'modules/app/refs/heads/main' => GIT_SHA,
        'app/.git' => "gitdir: ../modules/app\n",
    ]);

    // The resolver must not depend on the process working directory: under
    // PHP-FPM that is the application's public/, not the repository root.
    $cwd = getcwd();
    chdir(sys_get_temp_dir());

    try {
        expect(GitVersionResolver::make($root.'/app/.git')->resolve())->toBe('a1b2c3d');
    } finally {
        chdir((string) $cwd);
    }
});

it('returns null when .git is a file with no gitdir line', function () {
    $work = gitFixture(['.git' => "anything but a gitdir line\n"]);

    expect(GitVersionResolver::make($work.'/.git')->resolve())->toBeNull();
});

it('returns null when gitdir points nowhere', function () {
    // A real case: a worktree outlived the move of its main repository.
    $work = gitFixture(['.git' => "gitdir: /nope/really/not/here\n"]);

    expect(GitVersionResolver::make($work.'/.git')->resolve())->toBeNull();
});

// The guard against comment and dereference lines in packed-refs was already
// there, but the old fixture never touched it: those lines did not match the
// ref by name and were rejected by the next check instead. Here they match.
it('does not mistake a dereference line for the ref itself', function () {
    $git = gitFixture([
        '.git/HEAD' => "ref: refs/heads/main\n",
        '.git/packed-refs' => "# 1111111111111111111111111111111111111111 refs/heads/main\n"
            ."^3333333333333333333333333333333333333333 refs/heads/main\n"
            .GIT_SHA." refs/heads/main\n",
    ]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBe('a1b2c3d');
});

it('reads a repository with object-format=sha256', function () {
    $sha256 = str_repeat('ab', 32);

    $git = gitFixture([
        '.git/HEAD' => "ref: refs/heads/main\n",
        '.git/refs/heads/main' => $sha256,
    ]);

    expect(GitVersionResolver::make($git.'/.git')->resolve())->toBe('abababa');
});

// The no-argument constructor is a documented way to call this, and nothing
// covered it: every test passed the path explicitly, so replacing
// base_path('.git') with a path that does not exist left the suite green.
it('looks at the application .git by default', function () {
    $git = $this->app->basePath('.git');

    @mkdir($git.'/refs/heads', 0777, true);
    file_put_contents($git.'/HEAD', "ref: refs/heads/main\n");
    file_put_contents($git.'/refs/heads/main', GIT_SHA);

    $this->beforeApplicationDestroyed(static function () use ($git): void {
        @unlink($git.'/refs/heads/main');
        @unlink($git.'/HEAD');
        @rmdir($git.'/refs/heads');
        @rmdir($git.'/refs');
        @rmdir($git);
    });

    expect(GitVersionResolver::make()->resolve())->toBe('a1b2c3d');
});
