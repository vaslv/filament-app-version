{{--
    The application version chip next to the panel logo.

    This view is deliberately dumb: everything is decided in AppVersionPlugin,
    here there is only output. The prefix is already resolved ($prefix is empty
    when the version starts with it itself), the styles and the title are
    already merged into $attributes.

    The tooltip is duplicated as hidden text, not left to the title attribute
    alone. The chip is a non-interactive <span>: it never takes focus, so the
    title cannot be reached from the keyboard, and screen readers usually
    suppress it on an element that has text of its own. Without this line
    assistive technology announces a bare "v1.19.16" with no hint at all that it
    is the application version. The markup is squeezed onto one line
    deliberately: the chip is inline-block with nowrap, and extra newlines would
    become visible whitespace.

    The view contract, $version, $prefix, $tooltip and $attributes, is covered
    by semver. When overriding it through ->view(), count on exactly those four.
--}}
<span {{ $attributes }}>@if (filled($tooltip))<span style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0">{{ $tooltip }}: </span>@endif{{ $prefix }}{{ $version }}</span>
