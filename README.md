# Filament App Version

[![Packagist Version](https://img.shields.io/packagist/v/vaslv/filament-app-version)](https://packagist.org/packages/vaslv/filament-app-version)
[![CI](https://github.com/vaslv/filament-app-version/actions/workflows/tests.yml/badge.svg)](https://github.com/vaslv/filament-app-version/actions/workflows/tests.yml)
[![Plumb score](https://plumbphp.dev/badges/vaslv/filament-app-version/composite.svg)](https://plumbphp.dev/vaslv/filament-app-version)

**English** · [Русский](README.ru.md)

A [Filament 5](https://filamentphp.com) plugin that shows your application's version as a small chip next to the panel logo — in the topbar on desktop and in the sidebar drawer header on mobile, so it is visible at any width.

- 🔗 **Works with no configuration** — if your app already exposes `config('app.version')` or carries a `version` field in `composer.json`, install and register the plugin and you are done
- 🧩 **Several sources, one priority chain** — the first non-empty one wins
- 🎨 **Takes its colour from your panel** — the primary colour by default, the neutral grey on request, in both light and dark themes, with no custom theme and no Tailwind rebuild
- 🔒 **Hidden from guests by default** — a build number on a public login page is a free hint to anyone looking up CVEs; showing it is one explicit method call

## Requirements

- PHP 8.4+
- Laravel ^13.0
- Filament ^5.0

## Installation

```bash
composer require vaslv/filament-app-version
```

Register the plugin in your panel provider:

```php
use Vaslv\FilamentAppVersion\AppVersionPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(AppVersionPlugin::make());
}
```

That is the whole setup. Publish the config only if you need to change the chain:

```bash
php artisan vendor:publish --tag=filament-app-version-config
```

## Where the version comes from

Sources are tried in order and the **first non-empty one wins**:

```
1. config('filament-app-version.version')   ← env('APP_VERSION'), the CI injection point
2. config('app.version')                    ← the Laravel convention
3. composer.json → "version"
→  fallback 'dev'
```

To keep the version in the repository instead, add a `version` field to your application's `composer.json` and bump it at release time — that is link 3, and nothing else is needed.

### Injecting the version from CI

Set `APP_VERSION` in your environment and the first link of the chain picks it up:

```
APP_VERSION=1.19.16
```

**Do not** reach for `EnvVersionResolver` for this. The package config file calls `env('APP_VERSION')` *inside a config file*, which is the only placement that survives `php artisan config:cache` — with a cached config Laravel never loads `.env`, so an `env()` call at runtime returns `null` in production while working fine on your laptop.

## Configuration

```php
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Vaslv\FilamentAppVersion\AppVersionPlugin;
use Vaslv\FilamentAppVersion\Resolvers\ConfigVersionResolver;
use Vaslv\FilamentAppVersion\Resolvers\FileVersionResolver;
use Vaslv\FilamentAppVersion\Resolvers\GitVersionResolver;

->plugin(
    AppVersionPlugin::make()
        ->resolvers([
            ConfigVersionResolver::make('app.release'),
            FileVersionResolver::make(base_path('VERSION')),
            GitVersionResolver::make(),
        ])
        ->fallback('dev')
        ->prefix('v')
        ->badge()
        ->neutral()
        ->renderHooks([
            PanelsRenderHook::TOPBAR_LOGO_AFTER,
            PanelsRenderHook::SIDEBAR_LOGO_AFTER,
        ])
        ->tooltip(fn (): string => __('Application version'))
        ->visible(fn (Panel $panel): bool => auth()->user()?->can('viewAppVersion') ?? false)
        ->view('filament.my-own-chip')
        ->extraAttributes(['data-testid' => 'app-version']),
)
```

| Method | Default | Notes |
|---|---|---|
| `resolvers(array)` | the global chain | Overrides the chain **for this panel's chip only** |
| `fallback(?string)` | `'dev'` | `null` renders no chip at all when nothing resolves |
| `prefix(string)` | `'v'` | Never doubled: a version that already starts with `v` is left alone |
| `badge(bool)` | `false` | Draws the version as a pill with a tint and a hairline ring instead of bare text |
| `neutral(bool)` | `false` | Uses the panel's neutral grey instead of its primary colour |
| `renderHooks(array)` | topbar + sidebar header | Any `PanelsRenderHook` — see [Hook order](#hook-order) |
| `tooltip(string\|Closure\|null)` | translated "Application version" | Becomes the `title` attribute. `null` removes it entirely rather than falling back to the default. Pass a **closure** if the text is translated — see below |
| `visible(bool\|Closure)` | authenticated only | Has the last word, over the default and over `showToGuests()` |
| `showToGuests(bool)` | `false` | See [Visibility](#visibility) |
| `view(string)` | package chip | See [Custom view](#custom-view) |
| `extraAttributes(array)` | `[]` | `style` is concatenated with the package's, everything else overrides — see below |

Closures passed to `visible()` and `tooltip()` receive the `Panel` as their first argument; zero-argument closures keep working.

Pass a closure to `tooltip()` whenever the text is translated. A panel provider runs once, before the locale middleware and once per Octane worker, so a bare `__()` freezes one language for every user.

### Appearance

By default the chip is bare text in the panel's **primary** colour — the same one Filament uses for the active menu item. Two switches change that:

```php
AppVersionPlugin::make()->badge()     // a pill: tinted background + hairline ring
AppVersionPlugin::make()->neutral()   // the grey of an ordinary menu item
```

Nothing is hard-coded. The colours come from the panel's own `--primary-*` and `--gray-*` variables, so the chip follows whatever palette you passed to `->colors()`. The light and dark shades are picked by CSS `light-dark()`, which works because Filament declares `color-scheme` on the dark root — so there is no class to register and no stylesheet to rebuild.

### `extraAttributes()` and escaping

Values and attribute names you pass here reach the HTML **unescaped** — Laravel's own `ComponentAttributeBag` behaviour, which this package does not diverge from. A value carrying a double quote closes the attribute, so escape anything that is not a literal you wrote yourself.

```php
// Fine — a literal.
->extraAttributes(['data-testid' => 'app-version'])

// Escape it — the value comes from outside your code.
->extraAttributes(['data-tenant' => e($tenant->name)])
```

Two exceptions: `style` is folded into the package's own merged default and Laravel escapes it for you, and `tooltip()` goes through the escaped path, so `->tooltip($somethingUntrusted)` is safe.

### Sources

| Resolver | Reads |
|---|---|
| `ConfigVersionResolver::make('app.version')` | A config value by dot path |
| `ComposerJsonVersionResolver::make()` | The `version` field of the application's `composer.json` |
| `FileVersionResolver::make(base_path('VERSION'))` | A plain-text file. A second argument — `make(base_path('build.json'), 'version')` — switches it to JSON with a dot path |
| `GitVersionResolver::make()` | The seven-character SHA of `HEAD`, read straight out of `.git` with no shell calls and no tag detection |
| `CallbackVersionResolver::make(fn () => …)` | Anything — Redis, Vault, a table |
| `EnvVersionResolver::make('APP_RELEASE')` | A **process** environment variable — read the warning below |

`EnvVersionResolver` only sees a variable that really is in the process environment: compose's `environment:`/`env_file:`, a Kubernetes Deployment, a systemd unit. A variable that lives only in a `.env` file is invisible to it once the config is cached, which is why the supported route for an environment variable is the package config file.

### Visibility

|  | guest | authenticated |
|---|---|---|
| default | hidden | shown |
| `showToGuests()` | shown | shown |
| `visible(fn)` | the closure's result | the closure's result |

Setting `visible()` together with `showToGuests()` logs a warning when `APP_DEBUG` is on, so the contradiction surfaces rather than being guessed at.

The default is conservative because hooks such as `AUTH_LOGIN_FORM_AFTER` render before login, where a precise build number tells an attacker which CVEs to try.

### Hook order

The two default hooks are complementary by **breakpoint**, not by menu state: above `64rem` Filament hides the sidebar header, below it the topbar. So the topbar chip is what you see on desktop, the sidebar one after opening the drawer on mobile, and on desktop the sidebar copy sits in the DOM without being painted.

Output on a shared hook follows **registration order**, so where `->plugin()` sits in your panel provider chain decides whether the chip lands before or after another plugin's output on the same hook:

```php
->plugin(AppVersionPlugin::make())      // logo → version → menu
->plugin(TopbarMenuPlugin::make())
```

Swap those two lines and you get logo → menu → version.

### Custom view

`->view()` replaces the chip entirely. Your view receives exactly four variables, and that set is covered by semver:

| Variable | Type | Notes |
|---|---|---|
| `$version` | `string` | Exactly what the source gave, trimmed |
| `$prefix` | `string` | Already resolved — empty when disabled or when `$version` starts with it |
| `$tooltip` | `?string` | The `title` that ended up in the markup, or `null` |
| `$attributes` | `ComponentAttributeBag` | Your `extraAttributes()` merged with the package's `style`, plus `title` when a tooltip is set |

```blade
<span {{ $attributes }}>{{ $prefix }}{{ $version }}</span>
```

**To restyle rather than replace, you do not need `->view()` at all** — `->extraAttributes(['style' => 'font-size:0.7rem;'])` overrides the package's own declarations. Inside a custom view, `{{ $attributes->except('style') }}` drops the package's styling, along with anything you passed through `extraAttributes(['style' => …])`.

The package view also emits a visually-hidden `"<tooltip>: "` before the version, because the chip is a non-focusable `<span>` whose `title` is unreachable by keyboard. A custom view that omits it announces a bare `v1.19.16` to a screen reader.

## Using the version outside the panel

```php
use Vaslv\FilamentAppVersion\Facades\AppVersion;

AppVersion::get(); // '1.19.16' or null
```

Handy as a theme cache-buster:

```php
->theme(asset('css/filament/admin/theme.css').'?v='.AppVersion::get())
```

The facade always reads the **global** chain from the config, never a panel's `->resolvers()` — in the example above `->theme()` runs before `->plugin()`, so at that moment the plugin does not exist yet.

## Testing & code quality

```bash
composer check       # lint:test + analyse + test
composer test        # Pest
composer analyse     # PHPStan, level max
composer lint        # Pint, autofix
```

`composer.lock` is not committed, so every run resolves its own dependency set. To reproduce CI without a local PHP runtime:

```bash
make test            # one PHP version, 8.4 by default
make test-matrix     # 8.4 and 8.5, highest and lowest dependencies, in Docker
```

CI runs the same four combinations, plus Pint and PHPStan.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

- [Andrei Vasilev](https://github.com/vaslv)

## License

MIT. See [LICENSE](LICENSE).
