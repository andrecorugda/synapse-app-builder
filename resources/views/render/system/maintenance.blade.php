<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>We’ll be right back</title>
    <style>
        :root { --ink:#0a0e1a; --haze:#cdd6ee; --mist:#9aa6c4; --indigo:#6366f1; --cyan:#22d3ee; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:2rem; text-align:center;
            background: radial-gradient(900px 500px at 50% -10%, rgba(34,211,238,.16), transparent 60%), var(--ink);
            color: var(--haze); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .c { max-width: 34rem; }
        .glyph { width:54px; height:54px; margin:0 auto 1.25rem; display:block; }
        h1 { font-size: clamp(1.7rem, 5vw, 2.4rem); margin:0 0 .6rem; color:#f4f7ff; }
        p { color: var(--mist); margin:0; line-height:1.6; }
    </style>
</head>
<body>
    <div class="c">
        <svg class="glyph" viewBox="0 0 24 24" fill="none" stroke="url(#g)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <defs><linearGradient id="g" x1="0" y1="0" x2="24" y2="24"><stop offset="0" stop-color="#818cf8"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs>
            <path d="M14.7 6.3a4 4 0 0 0-5.4 5.4l-6 6 2 2 6-6a4 4 0 0 0 5.4-5.4l-2.3 2.3-2-2 2.3-2.3z"/>
        </svg>
        <h1>We’ll be right back</h1>
        <p>The site is down for scheduled maintenance. Please check back shortly.</p>
    </div>
</body>
</html>
