<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\View\ComponentAttributeBag;
use Stringable;
use Vaslv\FilamentAppVersion\Contracts\VersionResolver;

class AppVersionPlugin implements Plugin
{
    // Inline styles, not Tailwind classes.
    //
    // A neighbouring package in the same niche requires a configured custom
    // Filament theme and a "@ source" directive for its views to reach the
    // build. For a single <span> that inverts the cost: the setup is more
    // expensive than the feature.
    //
    // The colours come from the panel's own variables instead of being set
    // here. Filament injects --primary-* and --gray-* at runtime from the
    // panel's ->colors(), so the chip matches its palette automatically,
    // whatever that palette is.
    //
    // Theme switching is done by light-dark(), not by a media query: Filament
    // switches the theme with a .dark CLASS on <html>, which an inline style
    // cannot reach, but it also declares :root.dark { color-scheme: dark },
    // and light-dark() looks at exactly that color-scheme. In the light theme
    // color-scheme is not set, and per the spec that is the light branch too.
    // The shades are the ones Filament itself uses: the active menu item is
    // primary-700/primary-400, an ordinary one is gray-700/gray-200.
    //
    // The currentColor fallback in var()'s second argument is there for when
    // the chip is rendered outside a panel: the variables are absent then, and
    // it simply inherits the text colour. A browser without light-dark()
    // (older than Chrome 123 / Safari 17.5 / Firefox 120) drops the color
    // declaration entirely, which comes out the same.
    //
    // max-width + overflow: without them nowrap made the chip a non-shrinkable
    // flex item with min-width:auto, and a long version out of CI
    // ("v1.2.3-rc4+build.77") pushed the topbar layout around instead of being
    // clipped. For the ellipsis to work the chip is inline-block, not
    // inline-flex: text-overflow does not apply to a flex container.
    //
    // unicode-bidi + direction: a version identifier is a machine token, and
    // in an RTL panel it must not be reordered or have its brackets mirrored.
    protected const string LAYOUT = 'display:inline-block;vertical-align:middle;margin-inline-start:0.5rem;'
        .'font-size:0.75rem;line-height:1rem;font-weight:600;letter-spacing:0.02em;'
        .'font-variant-numeric:tabular-nums;'
        .'white-space:nowrap;max-width:100%;min-width:0;overflow:hidden;text-overflow:ellipsis;'
        .'unicode-bidi:isolate;direction:ltr;';

    /** @var list<VersionResolver>|null */
    protected ?array $resolvers = null;

    protected ?string $fallback = null;

    protected bool $fallbackWasSet = false;

    protected string $prefix = 'v';

    /** @var list<string> */
    protected array $renderHooks = [
        PanelsRenderHook::TOPBAR_LOGO_AFTER,
        PanelsRenderHook::SIDEBAR_LOGO_AFTER,
    ];

    protected string|Closure|null $tooltip = null;

    protected bool $tooltipWasSet = false;

    protected bool|Closure|null $visible = null;

    protected bool $showToGuests = false;

    protected bool $badge = false;

    protected bool $neutral = false;

    protected ?string $view = null;

    /** @var array<string, mixed> */
    protected array $extraAttributes = [];

    public static function make(): static
    {
        /** @var static $plugin */
        $plugin = app(static::class);

        return $plugin;
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(static::make()->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'filament-app-version';
    }

    /**
     * The hooks are registered here rather than in boot(), because output on
     * a shared hook follows registration order. Where ->plugin(...) sits in
     * the panel provider chain decides whether the chip lands before or after
     * another package's output on the same hook. If another plugin writes to
     * TOPBAR_LOGO_AFTER too, a menu for instance, then the ->plugin(...) order
     * is what decides between logo → version → menu and logo → menu → version.
     */
    public function register(Panel $panel): void
    {
        foreach ($this->renderHooks as $hook) {
            $panel->renderHook($hook, fn (): string => $this->render($panel));
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * Override the chain of sources for THIS panel's chip.
     *
     * Does not affect AppVersion::get(): the facade always reads the global
     * chain from the config. Otherwise a call like
     * ->theme(asset(...).'?v='.AppVersion::get()) would be ambiguous, since it
     * runs before ->plugin(...) in the same chain, when the plugin does not
     * exist yet.
     *
     * @param  array<int, VersionResolver>  $resolvers
     */
    public function resolvers(array $resolvers): static
    {
        $this->resolvers = array_values($resolvers);

        return $this;
    }

    /**
     * What to show when no source produced a version.
     *
     * null means "render no chip at all": in production that is more honest
     * than showing "vdev" and pretending it is a version.
     */
    public function fallback(?string $fallback): static
    {
        $this->fallback = $fallback;
        $this->fallbackWasSet = true;

        return $this;
    }

    /**
     * The prefix in front of the version. An empty string turns it off.
     *
     * Never doubled: if the source already returned "v1.2.3", the prefix is
     * not added. Stripping the "v" in the chain would be wrong, because that
     * would silently rewrite the release identifier, and the package displays
     * the version, it does not edit it.
     */
    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Draw the chip as a pill, with a tinted background and a hairline ring.
     *
     * Off by default: bare text next to the logo is quieter and does not argue
     * with the panel's styling. The pill rings itself the same way Filament's
     * own .fi-badge does, and takes the same colour as the text.
     */
    public function badge(bool $condition = true): static
    {
        $this->badge = $condition;

        return $this;
    }

    /**
     * Colour the chip neutrally instead of with the panel's primary colour.
     *
     * The primary colour is used by default, the same one Filament highlights
     * the active menu item with. The neutral variant repeats the colour of an
     * ordinary, inactive item.
     */
    public function neutral(bool $condition = true): static
    {
        $this->neutral = $condition;

        return $this;
    }

    // Where to render the chip. Topbar and sidebar header by default.
    //
    // These two hooks are complementary by BREAKPOINT, not by menu state. With
    // the topbar on (Filament's default) the sidebar header is hidden by the
    // rule ".fi-body-has-topbar .fi-sidebar-header { display:none }" inside a
    // media query (min-width: 64rem), while ".fi-topbar-start" is hidden below
    // that threshold instead. So on desktop it is the topbar chip you see, on
    // mobile the one in the sidebar drawer. Both are needed for the version to
    // be visible at any width; on desktop the second chip lands in the DOM but
    // is not painted.
    /**
     * @param  array<int, string>  $renderHooks
     */
    public function renderHooks(array $renderHooks): static
    {
        $this->renderHooks = array_values($renderHooks);

        return $this;
    }

    /**
     * The tooltip text. null removes the title attribute entirely.
     *
     * If the text is translated, pass a CLOSURE: a panel provider runs once,
     * before the locale middleware and once per Octane worker, so a bare
     * __('...') would freeze one language for every user.
     */
    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;
        $this->tooltipWasSet = true;

        return $this;
    }

    /**
     * The chip's visibility rule. It has the last word: it overrides both the
     * default and showToGuests().
     */
    public function visible(bool|Closure $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Show the version to unauthenticated visitors.
     *
     * The chip is hidden from guests by default. Hooks such as
     * AUTH_LOGIN_FORM_AFTER render before login, and a version on a public
     * page is a free hint to anyone looking up CVEs for a particular release.
     * Showing it is allowed, but it should be a decision, not a side effect of
     * copying an example.
     */
    public function showToGuests(bool $condition = true): static
    {
        $this->showToGuests = $condition;

        return $this;
    }

    /**
     * Replace the chip's view entirely.
     *
     * The view receives exactly four variables: $version (string), $prefix
     * (string), $tooltip (?string) and $attributes (ComponentAttributeBag).
     * That set is covered by semver: a variable may be added in a minor
     * release, removing or renaming one takes a major.
     */
    public function view(string $view): static
    {
        $this->view = $view;

        return $this;
    }

    /**
     * Extra HTML attributes for the chip.
     *
     * style is concatenated with the mandatory one rather than overwriting it.
     * Every other attribute, title included, overrides the package's value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function extraAttributes(array $attributes): static
    {
        $this->extraAttributes = $attributes;

        return $this;
    }

    protected function render(Panel $panel): string
    {
        $manager = app(AppVersionManager::class);

        // The key includes the plugin instance itself, not just the panel.
        // Filament runs register() for every ->plugin(), even when the ids
        // match, so one panel may well end up with two chips, say an
        // application version and an API version. With a key built from the
        // panel alone the second one would show the first one's version and
        // ignore its own visible(): four hooks, four identical chips. Verified
        // by rendering.
        $key = 'panel:'.$panel->getId().':'.spl_object_id($this);

        return $manager->html($key, fn (): string => $this->build($manager, $panel, $key));
    }

    /**
     * Builds the chip's markup. Called at most once per request per plugin
     * instance; both hooks share the result.
     */
    protected function build(AppVersionManager $manager, Panel $panel, string $key): string
    {
        if (! $manager->visible($key, fn (): bool => $this->isVisible($panel))) {
            return '';
        }

        $version = $this->versionFor($manager, $key);

        if ($version === null) {
            return '';
        }

        $attributes = $this->attributes($this->tooltipText($panel));

        // The view gets the title that ACTUALLY landed in the markup: the user
        // may have overridden the package's tooltip through
        // extraAttributes(['title' => ...]), and without this the hidden screen
        // reader label would drift apart from the visible one.
        $tooltip = $attributes->get('title');

        /** @var view-string $view */
        $view = $this->view ?? 'filament-app-version::chip';

        return view($view, [
            'version' => $version,
            'prefix' => $this->prefixFor($version),
            'tooltip' => is_string($tooltip) ? $tooltip : null,
            'attributes' => $attributes,
        ])->render();
    }

    /**
     * A panel with its own chain is resolved and memoised separately; a panel
     * with no settings of its own shares the global result with all the rest.
     */
    protected function versionFor(AppVersionManager $manager, string $key): ?string
    {
        if ($this->resolvers === null && ! $this->fallbackWasSet) {
            return $manager->get();
        }

        return $manager->version(
            $key,
            $this->resolvers ?? $manager->resolvers(),
            $this->fallbackWasSet ? $this->fallback : $manager->fallback(),
        );
    }

    protected function isVisible(Panel $panel): bool
    {
        if ($this->visible !== null) {
            if ($this->showToGuests) {
                // Both are set, which is a contradiction in the panel's
                // configuration. The explicit visible() wins, but the person
                // finds that out instead of guessing.
                $this->warnAboutVisibilityConflict();
            }

            return (bool) $this->evaluate($this->visible, $panel);
        }

        if ($this->showToGuests) {
            return true;
        }

        return $panel->auth()->check();
    }

    protected function warnAboutVisibilityConflict(): void
    {
        $message = '[filament-app-version] visible() and showToGuests() are both set on plugin '
            .static::class.'; visible() wins and showToGuests() is ignored.';

        if (App::hasDebugModeEnabled()) {
            Log::warning($message);
        }
    }

    protected function tooltipText(Panel $panel): ?string
    {
        // tooltipWasSet, not a null check: null is a meaningful input ("show
        // no tooltip"), and without the flag it was indistinguishable from
        // "the method was never called", so tooltip(null) silently drew the
        // default. Exactly the same fork as fallback(null).
        if ($this->tooltipWasSet) {
            $tooltip = $this->evaluate($this->tooltip, $panel);

            return is_string($tooltip) ? $tooltip : null;
        }

        $translated = trans('filament-app-version::filament-app-version.tooltip');

        // trans() is declared as array|string. Without this check, a consumer
        // who overrode the key with a nested array would get a TypeError out
        // of a method typed ?string, that is a 500 in the panel exactly where
        // the package promises to degrade quietly.
        return is_string($translated) ? $translated : null;
    }

    protected function prefixFor(string $version): string
    {
        if ($this->prefix === '') {
            return '';
        }

        return str_starts_with(strtolower($version), strtolower($this->prefix))
            ? ''
            : $this->prefix;
    }

    /**
     * The chip's inline style: layout, colour and, if asked for, the pill.
     */
    protected function style(): string
    {
        $shade = $this->neutral ? 'gray' : 'primary';
        $dark = $this->neutral ? '200' : '400';

        $style = static::LAYOUT
            ."color:light-dark(var(--{$shade}-700, currentColor), var(--{$shade}-{$dark}, currentColor));";

        if ($this->badge) {
            $style .= 'padding:0.125rem 0.5rem;border-radius:0.375rem;'
                ."background-color:color-mix(in srgb, var(--{$shade}-500, currentColor) 12%, transparent);"
                ."box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--{$shade}-500, currentColor) 24%, transparent);";
        }

        return $style;
    }

    protected function attributes(?string $tooltip): ComponentAttributeBag
    {
        $extra = $this->extraAttributes;
        $style = $this->style();

        // Cast, rather than test with is_string. Str::of(...) and HtmlString
        // are Stringable, not string, and the previous check let them through:
        // they stayed in the bag, where Laravel concatenates style as an
        // appendable attribute without escaping. A value carrying a quote
        // closed the attribute and hung a stray handler on the chip, on the
        // one attribute the package undertook to control itself.
        if (isset($extra['style']) && ($extra['style'] instanceof Stringable || is_string($extra['style']))) {
            $style .= (string) $extra['style'];
            unset($extra['style']);
        }

        $defaults = ['style' => $style];

        if ($tooltip !== null && $tooltip !== '') {
            $defaults['title'] = $tooltip;
        }

        return (new ComponentAttributeBag($extra))->merge($defaults);
    }

    /**
     * The closure receives the panel as its first argument.
     *
     * This is the universal Filament idiom,
     * ->visible(fn (Panel $panel) => ...), and until now it was fatal here:
     * evaluate() called the closure with no arguments, and the
     * ArgumentCountError turned into a 500 on every page of the admin panel.
     * Zero-parameter closures keep working all the same: PHP silently drops
     * extra arguments to userland functions.
     */
    protected function evaluate(mixed $value, Panel $panel): mixed
    {
        return $value instanceof Closure ? $value($panel) : $value;
    }
}
