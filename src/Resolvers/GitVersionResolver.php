<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion\Resolvers;

use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

/**
 * The short SHA of the current commit, read straight out of the .git directory.
 *
 *   .git exists?
 *     ├── no                       → null
 *     ├── file "gitdir: /path/.."  → follow gitdir   [worktree, submodule]
 *     │     └── gitdir/commondir → shared refs live THERE   [worktree]
 *     └── directory
 *           └── HEAD
 *                 ├── "ref: refs/heads/X"
 *                 │     ├── <gitdir>/<ref>, else <common>/<ref> → SHA
 *                 │     └── else <common>/packed-refs           → SHA
 *                 │           └── not found                     → null
 *                 └── raw SHA (detached HEAD)                   → SHA
 *           → the first 7 characters
 *
 * No shell_exec and no relatives: zero injection surface, checked by an arch
 * test. The contents of .git/HEAD turn into a path, so the ref is validated
 * and "../" is forbidden, because otherwise a tampered HEAD would read an
 * arbitrary file. The attack needs write access to .git, meaning the attacker
 * already has the repository, but the check costs one line.
 *
 * Tag detection is deliberately NOT here. The reverse lookup SHA → tag means
 * walking every tag, and a live repository can have hundreds of them, plus
 * dereferencing annotated tags in packed-refs. The tag is available anyway
 * through composer.json or a CI variable; git is needed exactly where neither
 * is, in local development, which is where the SHA is what you want. A
 * production image usually has no .git directory at all, so there the resolver
 * costs one file_exists and returns null.
 */
final class GitVersionResolver implements VersionResolver
{
    // 40 characters is SHA-1, 64 is a repository created with
    // --object-format=sha256. The second one is rare, but such a repository
    // used to be rejected outright.
    private const SHA = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/';

    private const REF = '/^refs\/[A-Za-z0-9._\-\/]+$/';

    private const LENGTH = 7;

    public function __construct(
        private readonly ?string $path = null,
    ) {}

    public static function make(?string $path = null): self
    {
        return new self($path);
    }

    public function resolve(): ?string
    {
        $git = $this->gitDirectory();

        if ($git === null) {
            return null;
        }

        $head = $this->readFile($git.'/HEAD');

        if ($head === null) {
            return null;
        }

        $sha = str_starts_with($head, 'ref:')
            ? $this->resolveRef($git, $this->commonDirectory($git), trim(substr($head, 4)))
            : $head;

        if ($sha === null || preg_match(self::SHA, $sha) !== 1) {
            return null;
        }

        return substr($sha, 0, self::LENGTH);
    }

    /**
     * Returns the real .git directory.
     *
     * In a git worktree and in a submodule, .git is a file holding the line
     * "gitdir: <path>", not a directory. A naive is_dir() check would report
     * "no repository" in every worktree.
     */
    private function gitDirectory(): ?string
    {
        $path = $this->path ?? base_path('.git');

        if (is_dir($path)) {
            return $path;
        }

        if (! is_file($path)) {
            return null;
        }

        $contents = $this->readFile($path);

        if ($contents === null || ! str_starts_with($contents, 'gitdir:')) {
            return null;
        }

        $gitdir = trim(substr($contents, 7));

        // A worktree writes an ABSOLUTE path, a submodule a RELATIVE one
        // ("gitdir: ../.git/modules/app"). git counts a relative one from the
        // directory holding the .git file itself, NEVER from the process CWD,
        // which under PHP-FPM is public/. Testing the raw value with is_dir
        // first would consult the CWD: a directory that happens to answer to
        // "../.git/modules/app" from wherever PHP is running would be taken
        // for the repository, and the chip would show a SHA from a different
        // repository entirely. Same rule as in commonDirectory().
        $resolved = str_starts_with($gitdir, '/') ? $gitdir : dirname($path).'/'.$gitdir;

        return is_dir($resolved) ? $resolved : null;
    }

    /**
     * The directory holding the SHARED refs.
     *
     * A linked worktree's gitdir (.git/worktrees/<name>/) contains only the
     * worktree's own HEAD, index and a "commondir" file with the path to the
     * main .git — refs/heads/* and packed-refs live there. The path is written
     * relative to the gitdir itself (usually "../.."), never to the process
     * CWD. A submodule's gitdir (.git/modules/<name>) has no commondir file
     * and keeps its own refs, so there the gitdir doubles as the common
     * directory. Verified on a live worktree: without this, every worktree
     * with a checked-out branch resolved to null, because the gitdir has no
     * refs/ at all.
     */
    private function commonDirectory(string $git): string
    {
        $common = $this->readFile($git.'/commondir');

        if ($common === null) {
            return $git;
        }

        // The path is relative to the gitdir unless absolute. Testing the raw
        // value with is_dir first would be a trap: "../.." is nearly always a
        // real directory relative to the process CWD, which under PHP-FPM is
        // the application's public/, and the refs would be looked up two
        // levels above THAT.
        $resolved = str_starts_with($common, '/') ? $common : $git.'/'.$common;

        return is_dir($resolved) ? $resolved : $git;
    }

    /**
     * Resolves "refs/heads/main" into a SHA: first as a separate file, then
     * through packed-refs.
     *
     * The loose file disappears after `git gc` or `git pack-refs --all`.
     * Contrary to popular belief, a fresh clone does NOT remove it: the
     * checked-out branch stays a separate file, and only the remaining refs
     * move into packed-refs. Verified on a live repository in both states.
     */
    private function resolveRef(string $git, string $common, string $ref): ?string
    {
        if (preg_match(self::REF, $ref) !== 1 || str_contains($ref, '..')) {
            return null;
        }

        // The worktree's own directory first: refs/bisect and refs/worktree/*
        // are per-worktree and live in the gitdir, not the common one.
        $loose = $this->readFile($git.'/'.$ref);

        if ($loose === null && $common !== $git) {
            $loose = $this->readFile($common.'/'.$ref);
        }

        if ($loose !== null) {
            return $loose;
        }

        return $this->readPackedRef($common, $ref);
    }

    private function readPackedRef(string $common, string $ref): ?string
    {
        $contents = $this->readFile($common.'/packed-refs');

        if ($contents === null) {
            return null;
        }

        foreach (explode("\n", $contents) as $line) {
            // Comments start with #, an annotated tag's dereference with ^.
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            [$sha, $name] = array_pad(explode(' ', trim($line), 2), 2, null);

            if ($name === $ref) {
                return $sha;
            }
        }

        return null;
    }

    private function readFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $contents = trim($contents);

        return $contents === '' ? null : $contents;
    }
}
