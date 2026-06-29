<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Page not found</title>
    <style>
        :root { --ink:#0a0e1a; --haze:#cdd6ee; --mist:#9aa6c4; --indigo:#6366f1; --cyan:#22d3ee; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:2rem; text-align:center;
            background: radial-gradient(900px 500px at 50% -10%, rgba(99,102,241,.18), transparent 60%), var(--ink);
            color: var(--haze); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .c { max-width: 34rem; }
        .code { margin:0; font-size: clamp(4rem, 13vw, 7.5rem); font-weight:800; line-height:1;
            background: linear-gradient(100deg, var(--indigo), var(--cyan)); -webkit-background-clip:text; background-clip:text; color:transparent; }
        h1 { font-size:1.6rem; margin:.4rem 0 .6rem; color:#f4f7ff; }
        p { color: var(--mist); margin:0 0 1.6rem; line-height:1.6; }
        a { display:inline-block; padding:.75rem 1.4rem; border-radius:.7rem; font-weight:700; text-decoration:none; color:#060912;
            background: linear-gradient(100deg, var(--indigo), var(--cyan)); box-shadow: 0 8px 26px -8px rgba(99,102,241,.7); }
    </style>
</head>
<body>
    <div class="c">
        <p class="code">404</p>
        <h1>Page not found</h1>
        <p>The page you’re looking for doesn’t exist or may have moved.</p>
        <a href="{{ url('/') }}">Back to home</a>
    </div>
</body>
</html>
