# filament-app-version

Guidance for AI coding agents (and humans) working in this repository.

## Commands

Install dependencies with `composer install`, then use the Composer scripts:

- typecheck: `composer analyse` (PHPStan / Larastan, level max)
- lint: `composer lint:test` (Pint, check-only) — use `composer lint` to autofix
- test: `composer test` (Pest)
- all of the above: `composer check`

## Requirements

Requires PHP 8.4+ (see `composer.json`). If you have no local PHP runtime, or
you want to reproduce CI exactly, everything runs in Docker:

```
make test                 # one PHP version, 8.4 by default
make test PHP=85          # pick the version
make test-matrix          # 8.4 and 8.5, highest and lowest dependencies
make shell                # a shell in the container
```

`composer.lock` is not committed, so every run resolves its own dependency set.
That is deliberate and it is why the matrix exists: `--prefer-lowest` is the
only thing that proves the constraints in `composer.json` are real rather than
whatever happened to install. Run `make test-matrix` before tagging.

The matrix is strictly sequential. All services bind-mount the same source
directory, so a parallel run would have several containers writing one
`composer.lock` and the matrix would go green while testing a single resolution.

Two extensions are not in the base image and are installed in `docker/Dockerfile`:
`intl` (required by `filament/support`) and `zip` (required by `openspout/openspout`,
which arrives transitively through `filament/actions`). The `unzip` binary does
not satisfy the latter.

## Releasing

Tags (`vX.Y.Z`) define versions; `main` is protected against force-push, so a
tag can never be corrected after it is pushed. Sequence every release as:

1. Rename the `## [Unreleased]` heading in `CHANGELOG.md` to the exact version
   being tagged (e.g. `## [1.1.0]`) — **the tag name must appear in
   CHANGELOG.md before anything is pushed**. Update the README if needed.
2. Commit the docs (or amend them into the release commit).
3. `git push`, then `git tag -a vX.Y.Z -m "vX.Y.Z"` and push the tag.

Never push a release whose changelog still says `Unreleased`, and never create
a new tag just to fix docs — a docs commit on `main` is enough.

## Conventions

- Comments and docblocks are English. Russian lives only in `README.ru.md` and
  `resources/lang/ru/`.
- Comments explain why, not what. A comment that restates the line below it is
  noise; a comment recording what was tried and rejected is the valuable kind.
- Translations live in `resources/lang/{locale}/filament-app-version.php`; keep
  every locale's key set identical to `en`.
- The resolver chain order, the plugin's fluent methods, the four view variables
  and `AppVersion::get()` are the semver-covered surface. It is enumerated in
  `CHANGELOG.md` under "Public API surface" — update that list when it changes.
- `config/filament-app-version.php` must stay declarative: strings and arrays
  only. A closure there is a fatal error at `php artisan config:cache`, not a
  degradation, and `tests/Feature/ConfigCacheTest.php` guards it.
- Follow the existing code style — Pint enforces it.
