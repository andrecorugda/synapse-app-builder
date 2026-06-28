<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $meta['title'] ?? $title }}</title>
    @if (! empty($meta['description']))
        <meta name="description" content="{{ $meta['description'] }}">
    @endif
    @if (! empty($meta['canonical']))
        <link rel="canonical" href="{{ $meta['canonical'] }}">
    @endif
    @if (! empty($meta['noindex']))
        <meta name="robots" content="noindex">
    @endif
    @if (! empty($meta['og_image']))
        <meta property="og:image" content="{{ $meta['og_image'] }}">
    @endif
    <meta property="og:title" content="{{ $meta['title'] ?? $title }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        [x-cloak] { display: none !important; }

        /* Section colour overlays (the builder's --pb-overlay style property). */
        [data-pb-block] { position: relative; }
        [data-pb-block]::after { content: ""; position: absolute; inset: 0; background: var(--pb-overlay, transparent); pointer-events: none; z-index: 0; }
        [data-pb-block] > * { position: relative; z-index: 1; }

        /* Entrance animations (the data-pb-anim trait). Hidden only once JS is
           ready, so content stays visible with JS disabled. */
        html.pb-anim-ready [data-pb-anim]:not([data-pb-anim=""]) { opacity: 0; }
        html.pb-anim-ready [data-pb-anim].pb-anim-in { opacity: 1; animation-duration: .7s; animation-fill-mode: both; }
        [data-pb-anim="fade"].pb-anim-in { animation-name: pbFade; }
        [data-pb-anim="fade-up"].pb-anim-in { animation-name: pbFadeUp; }
        [data-pb-anim="fade-down"].pb-anim-in { animation-name: pbFadeDown; }
        [data-pb-anim="fade-left"].pb-anim-in { animation-name: pbFadeLeft; }
        [data-pb-anim="fade-right"].pb-anim-in { animation-name: pbFadeRight; }
        [data-pb-anim="zoom-in"].pb-anim-in { animation-name: pbZoomIn; }
        @keyframes pbFade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes pbFadeUp { from { opacity: 0; transform: translateY(28px); } to { opacity: 1; transform: none; } }
        @keyframes pbFadeDown { from { opacity: 0; transform: translateY(-28px); } to { opacity: 1; transform: none; } }
        @keyframes pbFadeLeft { from { opacity: 0; transform: translateX(28px); } to { opacity: 1; transform: none; } }
        @keyframes pbFadeRight { from { opacity: 0; transform: translateX(-28px); } to { opacity: 1; transform: none; } }
        @keyframes pbZoomIn { from { opacity: 0; transform: scale(.92); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { html.pb-anim-ready [data-pb-anim] { opacity: 1 !important; animation: none !important; } }

        {!! $css !!}
        {{-- Per-page custom CSS overrides (authored in the builder's Advanced section). --}}
        {!! $customCss !!}
    </style>

    {{-- Reactive Store: seed Alpine's $store.app from this page's persistent
         States before Alpine boots. Components bind with x-text / x-show /
         x-model; flows push updates via the setState result action. --}}
    <script>
        window.__pbState = @js($state ?? []);
        window.__pbApiBase = '{{ url(config('ai-page-builder.data.api_prefix', 'api/pb')) }}';
        document.addEventListener('alpine:init', function () {
            window.Alpine.store('app', Object.assign({}, window.__pbState));

            // pbTable — the Data Table block's x-data. Fetches a collection's rows
            // from the auto REST API (GET {api}/{collection} → {data:[…]}) and
            // exposes rows/loading/error for x-for / x-show bindings. The block's
            // static sample rows carry x-show="false" so only real rows render.
            var API_BASE = window.__pbApiBase;
            window.Alpine.data('pbTable', function (collection) {
                return {
                    rows: [],
                    loading: true,
                    error: false,
                    init: function () {
                        var self = this;
                        fetch(API_BASE + '/' + collection, { headers: { Accept: 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                self.rows = (d && d.data) || [];
                                self.loading = false;
                            })
                            .catch(function () { self.loading = false; self.error = true; });
                    },
                };
            });
        });
    </script>
</head>
<body x-data>
    {!! $html !!}

    <script>
        (function () {
            var els = document.querySelectorAll('[data-pb-anim]:not([data-pb-anim=""])');
            if (! els.length || ! ('IntersectionObserver' in window)) { return; }
            document.documentElement.classList.add('pb-anim-ready');
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (! e.isIntersecting) { return; }
                    var el = e.target;
                    var delay = el.getAttribute('data-pb-anim-delay');
                    if (delay) { el.style.animationDelay = parseInt(delay, 10) + 'ms'; }
                    el.classList.add('pb-anim-in');
                    io.unobserve(el);
                });
            }, { threshold: 0.15 });
            els.forEach(function (el) { io.observe(el); });
        })();
    </script>

    {{-- Flow trigger runtime: components with data-pb-flow run a flow on click. --}}
    @include('ai-page-builder::render.flow-runtime')

    {{-- Alpine powers the reactive Store + data bindings. Loaded before custom
         JS so the store is initialised and custom JS can read/write it. --}}
    <script src="{{ config('ai-page-builder.assets.alpine_js') }}"></script>

    {{-- Per-page custom JS (authored in the builder's Advanced section) — an
         escape hatch for scenarios the builder doesn't cover. Runs last, after
         the page DOM, the flow runtime, and Alpine (so it can use
         window.Alpine.store('app')). --}}
    @if (! empty($customJs))
        <script>{!! $customJs !!}</script>
    @endif
</body>
</html>
