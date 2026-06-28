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
        {!! $css !!}
        {{-- Per-page custom CSS overrides (authored in the builder's Advanced section). --}}
        {!! $customCss !!}
    </style>
</head>
<body>
    {!! $html !!}
</body>
</html>
