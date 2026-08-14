# Changelog

All notable changes to `filament-app-version` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `size()` — `Size::ExtraSmall` … `Size::ExtraLarge` (or `'xs'` … `'xl'`),
  Filament's own `Size`. Scales the chip's text and, with `badge()` on, the
  pill's padding and corner radius along with it. `Size::Medium` is the
  default and is exactly what the chip rendered before the method existed, so
  the upgrade changes nothing until it is called.
- `verticalAlignment()` — `VerticalAlignment::Start`, `Center` (the default) or
  `End`, or the same as strings. `Start` and `End` declare both `align-self`
  and `vertical-align`, because the hook decides which one is live: a flex row
  and a grid item ignore `vertical-align`, a chip rendered into running text
  ignores `align-self`. `Center` declares neither and renders exactly what the
  chip rendered before the method existed, so, as with `size()`, nothing moves
  until it is called.

  One caveat, and it is CSS's rather than the package's: a flex **column**
  container — `.fi-sidebar-nav` and `.fi-sidebar`, which carry
  `SIDEBAR_NAV_START`, `SIDEBAR_NAV_END`, `SIDEBAR_START` and `SIDEBAR_FOOTER`
  — reads `align-self` on the horizontal axis, so on those hooks `Start` and
  `End` move the chip sideways. That is also why `Center` writes nothing: it is
  the value every chip carries without asking.

### Changed

- An unknown string passed to `size()` or `verticalAlignment()` is ignored,
  keeping the previous value, and logs a warning when `APP_DEBUG` is on — the
  same treatment the `visible()` / `showToGuests()` contradiction gets.

### Public API surface

- The fluent methods are now thirteen: `size()` and `verticalAlignment()` join
  the eleven listed under 1.0.0, on the same terms.

## [1.0.1] - 2026-08-14

### Fixed

- `GitVersionResolver` in a linked `git worktree`. The branch refs and
  `packed-refs` live in the common `.git`, reached through the gitdir's
  `commondir` file — which the resolver never read, so every worktree with a
  checked-out branch silently resolved to `null` and the chip showed the
  fallback. Detached-HEAD worktrees worked by accident. Submodules are
  unaffected: their gitdir keeps its own refs.
- A relative `gitdir:` path is now anchored strictly to the directory holding
  the `.git` file. Probing the raw value with `is_dir()` consulted the process
  working directory first, and a directory that happened to be reachable from
  there was taken for the repository — a SHA from the wrong repository, not
  even a `null`.

## [1.0.0] - 2026-08-13

### Added

- `AppVersionPlugin`, rendering the version next to the panel logo on
  `TOPBAR_LOGO_AFTER` and `SIDEBAR_LOGO_AFTER`. Both hooks and their order are
  configurable.
- A priority chain of version sources: `config('filament-app-version.version')`,
  then `config('app.version')`, then `composer.json`'s `version`, then the
  fallback.
- Six resolvers: `ConfigVersionResolver`, `ComposerJsonVersionResolver`,
  `FileVersionResolver` (plain text or JSON by dot path), `GitVersionResolver`
  (short SHA read from `.git`, no shell calls), `CallbackVersionResolver` and
  `EnvVersionResolver`.
- Colours taken from the panel itself: the primary colour by default, the
  neutral grey via `neutral()`, both resolved from the panel's own CSS variables
  and switched between light and dark by `light-dark()`. No custom theme, no
  Tailwind rebuild.
- `badge()` draws the version as a pill instead of bare text. Off by default.
- Hidden from guests by default; `showToGuests()` and `visible()` override that.
- A visually-hidden label ahead of the version, so a screen reader announces
  what the number is.
- `AppVersion` facade for use outside a panel. It reads the global chain and can
  be faked with `AppVersion::shouldReceive()` / `spy()`.
- A four-variable view contract for `->view()`.
- If the application's config was already cached when the package was installed,
  the chain falls back to the one shipped in the package rather than resolving
  nothing.

### Public API surface

This is what semver covers. Anything not listed here may change in a minor
release.

- The default resolver chain **and its order**.
- `AppVersionPlugin::make()` and `::get()`, `getId()` (returns
  `filament-app-version`), and the eleven fluent methods: `resolvers()`,
  `fallback()`, `prefix()`, `badge()`, `neutral()`, `renderHooks()`,
  `tooltip()`, `visible()`, `showToGuests()`, `view()`, `extraAttributes()`.
- Closures passed to `visible()` and `tooltip()` receive the `Panel` as their
  first argument; zero-argument closures keep working.
- The four view variables `$version`, `$prefix`, `$tooltip`, `$attributes`. A
  variable may be added in a minor release; renaming or removing one takes a
  major.
- The default view name `filament-app-version::chip` and the translation key
  `filament-app-version::filament-app-version.tooltip`.
- The `VersionResolver` interface and the six shipped implementations.
- `AppVersion::get()`. The other public methods on `AppVersionManager` are not
  covered.
- The config file's shape: `version`, `fallback`, `resolvers`, where an entry is
  a class-string or `[class-string, ...constructor args]`.
- The visibility precedence table.

### Notes

- A resolver that throws rethrows when `APP_DEBUG` is on and logs a warning
  otherwise. A version chip has no business taking an admin panel down in
  production. Exceptions from *your* `visible()` closure or your custom view are
  deliberately not caught — that is your code.

- Everything the chip needs is resolved once per request, keyed by panel and by
  plugin instance, so two chips on one panel do not share a version or a
  visibility answer.

- New configuration will always arrive as a **new top-level key**, never as a
  sub-key of an existing array: `mergeConfigFrom` is a shallow `array_merge`, so
  a published `resolvers` array replaces the package default wholesale — which
  also means a published config pins the chain against future additions to it.

[Unreleased]: https://github.com/vaslv/filament-app-version/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/vaslv/filament-app-version/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/vaslv/filament-app-version/releases/tag/v1.0.0
