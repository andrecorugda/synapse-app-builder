<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Recovery codes &middot; {{ config('app.name', 'Synapse') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background:
                radial-gradient(1200px 600px at 100% -10%, rgba(99,102,241,0.18), transparent 60%),
                radial-gradient(900px 500px at -10% 110%, rgba(16,185,129,0.16), transparent 60%),
                #0b1020;
            color: #e5e7eb; padding: 1.5rem;
        }
        .card {
            width: 100%; max-width: 400px; background: rgba(17,24,39,0.72);
            border: 1px solid rgba(148,163,184,0.16); border-radius: 18px;
            padding: 2.25rem 2rem; backdrop-filter: blur(14px);
            box-shadow: 0 30px 80px -20px rgba(0,0,0,0.6);
        }
        .brand { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1.6rem; }
        .brand-mark {
            width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center;
            background: linear-gradient(135deg, #6366f1, #22d3ee); color: white; font-weight: 800; font-size: 1.1rem;
        }
        .brand-name { font-weight: 700; font-size: 1.05rem; letter-spacing: -0.01em; }
        h1 { font-size: 1.4rem; margin: 0 0 0.3rem; letter-spacing: -0.02em; }
        .sub { margin: 0 0 1.5rem; color: #94a3b8; font-size: 0.92rem; }
        .warn {
            background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.35); color: #fcd34d;
            padding: 0.6rem 0.8rem; border-radius: 10px; font-size: 0.85rem; margin-bottom: 1.2rem;
        }
        .codes {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            background: rgba(2,6,23,0.6); border: 1px solid rgba(148,163,184,0.22); color: #f1f5f9;
            padding: 1rem 1.1rem; border-radius: 11px; font-size: 0.95rem; letter-spacing: 0.06em;
            line-height: 1.9; margin-bottom: 0.5rem;
        }
        .codes div { user-select: all; }
        a.btn {
            display: block; width: 100%; padding: 0.75rem 1rem; border: 0; border-radius: 11px;
            font-size: 0.95rem; font-weight: 700; color: white; margin-top: 1rem; text-align: center;
            text-decoration: none; background: linear-gradient(135deg, #6366f1, #4f46e5);
            transition: transform .08s, filter .15s;
        }
        a.btn:hover { filter: brightness(1.08); }
        a.btn:active { transform: translateY(1px); }
        .foot { margin-top: 1.4rem; text-align: center; font-size: 0.78rem; color: #64748b; }
    </style>
</head>
<body>
    <div class="card">
        @php($loginPath = trim((string) ($loginPath ?? config('ai-page-builder.auth.login_path', 'login')), '/'))
        @php($codes = $codes ?? [])

        <div class="brand">
            <div class="brand-mark">◆</div>
            <div class="brand-name">{{ config('app.name', 'Synapse') }}</div>
        </div>

        <h1>Save your recovery codes</h1>
        <p class="sub">Use one of these if you lose access to your device.</p>

        <div class="warn">Store these somewhere safe — each can be used once if you lose access to your device.</div>

        <div class="codes">
            @foreach ($codes as $code)
                <div>{{ $code }}</div>
            @endforeach
        </div>

        <a class="btn" href="{{ url('/') }}">Done — continue</a>

        <div class="foot">Powered by Synapse — App Builder</div>
    </div>
</body>
</html>
