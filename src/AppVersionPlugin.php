<?php

declare(strict_types=1);

namespace Vaslv\FilamentAppVersion;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\VerticalAlignment;
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
    //
    // Font size, line height and vertical alignment are NOT here: they depend
    // on size() and verticalAlignment(), and this constant is the part that
    // does not.
    protected const string LAYOUT = 'display:inline-block;margin-inline-start:0.5rem;'
        .'font-weight:600;letter-spacing:0.02em;'
        .'font-variant-numeric:tabular-nums;'
        .'white-space:nowrap;max-width:100%;min-width:0;overflow:hidden;text-overflow:ellipsis;'
        .'unicode-bidi:isolate;direction:ltr;';

    // The chip's own hook for CSS: the sidebar rule below needs to address it,
    // and the class attribute is left free for whoever renders the panel.
    protected const string MARKER = 'data-filament-app-version';

    // The one rule the chip cannot express as an inline style of its own,
    // because it is about its NEIGHBOUR.
    //
    // Filament gives the sidebar header's logo container "flex: 1 1 0%", so it
    // eats the whole width of the header and anything rendered after it —
    // SIDEBAR_LOGO_AFTER is the last child of .fi-sidebar-header — is pushed
    // against the far edge. The chip promises to sit next to the logo, and in
    // the mobile drawer it sat across the header from it instead.
    //
    // The whole shorthand, not flex-grow alone: the declaration Filament writes
    // also carries flex-basis:0%, and zeroing only the grow would leave the
    // logo a container of width zero.
    //
    // The second rule is what keeps the logo where it was. The header is a
    // "justify-content: center" flex row, which never showed because the logo
    // filled it; take the grow away and the pair drifts into the middle of the
    // header while the menu below stays flush left. An auto margin on the chip
    // eats the free space before justify-content ever gets to distribute it, so
    // the row sits left again — and it does that whatever justify-content a
    // custom theme sets, which is why it is not a justify-content override.
    //
    // Emitted with the chip and only on that hook, so a panel that renders the
    // chip elsewhere, or hides it, gets no stray CSS. The header's collapse
    // buttons are rendered BEFORE the logo, so nothing else in the row moves.
    protected const string SIDEBAR_HEADER_STYLE = '<style>'
        .'.fi-sidebar-header .fi-sidebar-header-logo-ctn{flex:0 1 auto}'
        .'.fi-sidebar-header ['.self::MARKER.']{margin-inline-end:auto}'
        .'</style>';

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

    protected Size $size = Size::Medium;

    protected VerticalAlignment $verticalAlignment = VerticalAlignment::Center;

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
            $panel->renderHook($hook, fn (): string => $this->render($panel, $hook));
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

    /**
     * How big the chip is: Size::ExtraSmall … Size::ExtraLarge, or 'xs' … 'xl'.
     *
     * Scales the text always, and the pill's padding and corner radius when
     * badge() is on. Medium is what the chip looked like before this method
     * existed, so an upgrade changes nothing until it is called.
     *
     * An unknown string is ignored with a warning under APP_DEBUG rather than
     * throwing: a mistyped size is a cosmetic detail, and this package does
     * not take an admin panel down over one.
     */
    public function size(Size|string $size): static
    {
        if (is_string($size)) {
            $resolved = Size::tryFrom($size);

            if ($resolved === null) {
                $this->warn("size('{$size}') is not one of xs, sm, md, lg, xl; it was ignored.");

                return $this;
            }

            $size = $resolved;
        }

        $this->size = $size;

        return $this;
    }

    /**
     * Where the chip sits vertically: Start (top), Center, End (bottom), or
     * the strings 'start', 'center' and 'end'.
     *
     * The reference is the row the chip is rendered into, not the whole bar.
     * On the topbar that row is as tall as the logo, so Start puts the chip
     * level with the top of the logo; the sidebar drawer header is a fixed
     * 4rem, so there Start and End hug the header's own edges.
     *
     * Center is the default and renders exactly what the chip rendered before
     * this method existed. Only Start and End add anything — see
     * alignmentStyle() for why that asymmetry is deliberate.
     *
     * An unknown string is ignored with a warning under APP_DEBUG, exactly as
     * in size().
     */
    public function verticalAlignment(VerticalAlignment|string $alignment): static
    {
        if (is_string($alignment)) {
            $resolved = VerticalAlignment::tryFrom($alignment);

            if ($resolved === null) {
                $this->warn("verticalAlignment('{$alignment}') is not one of start, center, end; it was ignored.");

                return $this;
            }

            $alignment = $resolved;
        }

        $this->verticalAlignment = $alignment;

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

    protected function render(Panel $panel, string $hook = ''): string
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

        $html = $manager->html($key, fn (): string => $this->build($manager, $panel, $key));

        if ($html === '' || $hook !== PanelsRenderHook::SIDEBAR_LOGO_AFTER) {
            return $html;
        }

        // Once per request, however many chips the panel carries.
        return $manager->once('sidebar-header-style')
            ? static::SIDEBAR_HEADER_STYLE.$html
            : $html;
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
        $this->warn('visible() and showToGuests() are both set on plugin '
            .static::class.'; visible() wins and showToGuests() is ignored.');
    }

    /**
     * A misconfiguration the package works around rather than fails on.
     *
     * Silent in production on purpose: the person who can act on the message
     * is the one running the panel with APP_DEBUG on, and a log line per
     * request in production would be noise about a decision already made.
     */
    protected function warn(string $message): void
    {
        if (App::hasDebugModeEnabled()) {
            Log::warning('[filament-app-version] '.$message);
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
     * The chip's inline style: layout, type, alignment, colour and, if asked
     * for, the pill.
     */
    protected function style(): string
    {
        $shade = $this->neutral ? 'gray' : 'primary';
        $dark = $this->neutral ? '200' : '400';

        $style = static::LAYOUT
            .$this->typeStyle()
            .$this->alignmentStyle()
            ."color:light-dark(var(--{$shade}-700, currentColor), var(--{$shade}-{$dark}, currentColor));";

        if ($this->badge) {
            $style .= $this->pillStyle()
                ."background-color:color-mix(in srgb, var(--{$shade}-500, currentColor) 12%, transparent);"
                ."box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--{$shade}-500, currentColor) 24%, transparent);";
        }

        return $style;
    }

    /**
     * Font size and line height for the chosen size.
     *
     * Medium is Tailwind's text-xs, which is where the chip started and what
     * Filament's own .fi-badge uses; Large is text-sm and ExtraLarge is
     * text-base, each with the line height Tailwind pairs it with.
     *
     * The two steps below Medium have no Tailwind pair to take: the type scale
     * bottoms out at text-xs, and "smaller than the badge" is the whole point
     * of asking for ExtraSmall next to a logo. Their line heights are set by
     * hand to the same ~1.4 ratio the scale runs at, because a line height
     * here is the chip's height, and with badge() the pill's.
     */
    protected function typeStyle(): string
    {
        return match ($this->size) {
            Size::ExtraSmall => 'font-size:0.625rem;line-height:0.875rem;',
            Size::Small => 'font-size:0.6875rem;line-height:1rem;',
            Size::Medium => 'font-size:0.75rem;line-height:1rem;',
            Size::Large => 'font-size:0.875rem;line-height:1.25rem;',
            Size::ExtraLarge => 'font-size:1rem;line-height:1.5rem;',
        };
    }

    /**
     * The pill's padding and corner radius. Only reached with badge() on.
     *
     * Every value is a Tailwind spacing token (py-0, py-px, py-0.5 … py-1.5
     * and px-1 … px-3). Two of the radii are Filament's own — rounded-md on
     * the badge-sized steps and the button's rounded-lg at the top — and the
     * bottom two are Tailwind's rounded-sm, one step under the badge, because
     * 0.375rem on a 0.625rem line reads as a lozenge.
     */
    protected function pillStyle(): string
    {
        return match ($this->size) {
            Size::ExtraSmall => 'padding:0 0.25rem;border-radius:0.25rem;',
            Size::Small => 'padding:0.0625rem 0.375rem;border-radius:0.25rem;',
            Size::Medium => 'padding:0.125rem 0.5rem;border-radius:0.375rem;',
            Size::Large => 'padding:0.25rem 0.625rem;border-radius:0.375rem;',
            Size::ExtraLarge => 'padding:0.375rem 0.75rem;border-radius:0.5rem;',
        };
    }

    /**
     * Vertical placement. Two declarations, because the hook decides which of
     * them is live, and none at all for Center.
     *
     * A render hook drops the chip into one of three kinds of container, and
     * Filament ships all three:
     *
     *   - a flex ROW: .fi-topbar-start and .fi-sidebar-header, the two default
     *     hooks, both "flex items-center". align-self is the vertical axis
     *     here and vertical-align is inert.
     *   - a GRID: every schema hook, AUTH_LOGIN_FORM_AFTER among them, since
     *     Schema renders its container with Filament's own grid() macro and a
     *     RenderHook is liberatedFromContainerGrid(), i.e. a direct child of
     *     it. A grid item is blockified, so again align-self moves it and
     *     vertical-align does not.
     *   - a BLOCK: a hook of your own that renders into running text. There
     *     the chip is an inline-block in a line box, which is the one place
     *     vertical-align is the whole story and align-self means nothing.
     *
     * Hence both declarations: each is a no-op where the other one works.
     * Filament resolves its own VerticalAlignment the same way — items-* in a
     * flex container, align-top/middle/bottom in a table cell.
     *
     * There is a fourth case, and it is why Center emits no align-self at all.
     * A flex COLUMN container — .fi-sidebar-nav and .fi-sidebar, which carry
     * SIDEBAR_NAV_START, SIDEBAR_NAV_END, SIDEBAR_START and SIDEBAR_FOOTER —
     * puts align-self on the HORIZONTAL axis. An align-self:center emitted by
     * default would silently pull a chip on those hooks out of the sidebar's
     * full width and into its middle, and Center is what every chip gets
     * without asking. Left out, the default is byte for byte what 1.0.x
     * rendered, and the container keeps placing the chip exactly as it did.
     * Start and End have to carry it: nothing else can move a flex item off
     * its container's align-items. On a column hook they move the chip
     * sideways instead — that is the axis CSS gives them, not a decision this
     * package can make.
     */
    protected function alignmentStyle(): string
    {
        return match ($this->verticalAlignment) {
            VerticalAlignment::Start => 'vertical-align:top;align-self:flex-start;',
            VerticalAlignment::Center => 'vertical-align:middle;',
            VerticalAlignment::End => 'vertical-align:bottom;align-self:flex-end;',
        };
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

        $defaults = ['style' => $style, static::MARKER => 'true'];

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
