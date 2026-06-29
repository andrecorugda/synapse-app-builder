<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set a new password &middot; {{ config('app.name', 'Synapse') }}</title>
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
        label { display: block; font-size: 0.82rem; font-weight: 600; color: #cbd5e1; margin: 0 0 0.35rem; }
        .field { margin-bottom: 1.05rem; }
        input[type=email], input[type=password] {
            width: 100%; padding: 0.7rem 0.85rem; border-radius: 11px; font-size: 0.95rem;
            background: rgba(2,6,23,0.6); border: 1px solid rgba(148,163,184,0.22); color: #f1f5f9;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.25); }
        input[readonly] { opacity: 0.7; cursor: not-allowed; }
        button {
            width: 100%; padding: 0.75rem 1rem; border: 0; border-radius: 11px; cursor: pointer;
            font-size: 0.95rem; font-weight: 700; color: white; margin-top: 0.25rem;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            transition: transform .08s, filter .15s;
        }
        button:hover { filter: brightness(1.08); }
        button:active { transform: translateY(1px); }
        .error {
            background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fca5a5;
            padding: 0.6rem 0.8rem; border-radius: 10px; font-size: 0.85rem; margin-bottom: 1.2rem;
        }
        .status {
            background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.35); color: #6ee7b7;
            padding: 0.6rem 0.8rem; border-radius: 10px; font-size: 0.85rem; margin-bottom: 1.2rem;
        }
        .links { text-align: center; margin-top: 1.1rem; }
        .links a { color: #a5b4fc; text-decoration: none; font-size: 0.85rem; font-weight: 600; }
        .links a:hover { color: #c7d2fe; text-decoration: underline; }
        .foot { margin-top: 1.4rem; text-align: center; font-size: 0.78rem; color: #64748b; }
    </style>
</head>
<body>
    <div class="card">
        @php($loginPath = trim((string) ($loginPath ?? config('ai-page-builder.auth.login_path', 'login')), '/'))

        <div class="brand">
            <div class="brand-mark">◆</div>
            <div class="brand-name">{{ config('app.name', 'Synapse') }}</div>
        </div>

        <h1>Set a new password</h1>
        <p class="sub">Choose a new password for your account.</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ url('/'.$loginPath.'/reset') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" readonly required>
            </div>
            <div class="field">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" autocomplete="new-password" autofocus required>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
            </div>
            <button type="submit">Reset password</button>
        </form>

        <div class="links">
            <a href="{{ url('/'.$loginPath) }}">&larr; Back to sign in</a>
        </div>

        <div class="foot">Powered by Synapse — App Builder</div>
    </div>
</body>
</html>
