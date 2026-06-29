{{-- Dockable, ChatGPT/Claude-style AI build chat injected on EVERY admin panel
     page via the panels::body.end render hook (registered in the service
     provider). It must survive Livewire's wire:navigate SPA navigations, which
     re-inject this block and morph the panel body on every page change. Mirrors
     the idempotent boot pattern of codeeditor-assets.blade.php /
     flow-assets.blade.php: one-time boot guarded by a window flag, all
     literal-brace JS wrapped in a verbatim block, re-attached on livewire:navigated.

     Persistence strategy (so a build conversation is retained as the user moves
     between Pages / Flows / Functions): the chat root is appended to
     document.body — OUTSIDE the Livewire-morphed root — so it is never wiped by
     a navigation; the whole thread lives in localStorage under `pb_ai_chat` and
     is re-rendered from there. On boot AND on every livewire:navigated we just
     ensure the (single) root still exists and re-render. --}}
@once
    <script>
        // Blade echoes live OUTSIDE the verbatim block (the only PHP allowed in this file).
        window.__pbChatBase = @js(url(config('ai-page-builder.routes.panel_prefix', 'ai-page-builder')));
        window.__pbChatCsrf = @js(csrf_token());
    </script>
    <style>
        /* All styles scoped under .pb-aichat so nothing leaks into the panel and
           we never depend on the host's Tailwind build. */
        .pb-aichat, .pb-aichat * { box-sizing: border-box; }

        /* ── Floating launcher (bottom-right gradient orb) ── */
        .pb-aichat-fab {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 99999;
            width: 56px;
            height: 56px;
            border: 0;
            border-radius: 9999px;
            cursor: pointer;
            color: #fff;
            font-size: 24px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6366f1 0%, #06b6d4 100%);
            box-shadow: 0 10px 30px rgba(79, 70, 229, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.08) inset;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .pb-aichat-fab:hover { transform: translateY(-2px) scale(1.04); box-shadow: 0 14px 38px rgba(79, 70, 229, 0.55); }
        .pb-aichat-fab:active { transform: translateY(0) scale(0.98); }
        .pb-aichat-fab.pb-aichat-fab-hidden { display: none; }

        /* ── Drawer (right-docked, full height, dark glassy) ── */
        .pb-aichat-drawer {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 400px;
            max-width: 100vw;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            color: #e2e8f0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background:
                radial-gradient(900px 500px at 90% -10%, rgba(99, 102, 241, 0.20), transparent 60%),
                radial-gradient(700px 500px at -20% 110%, rgba(16, 185, 129, 0.16), transparent 60%),
                #0b1020;
            border-left: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: -24px 0 60px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(14px);
            transform: translateX(100%);
            transition: transform 0.26s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .pb-aichat-drawer.pb-aichat-open { transform: translateX(0); }
        @media (max-width: 480px) { .pb-aichat-drawer { width: 100vw; } }
        @media (prefers-reduced-motion: reduce) {
            .pb-aichat-drawer { transition: none; }
            .pb-aichat-fab { transition: none; }
        }

        /* ── Header ── */
        .pb-aichat-head {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .pb-aichat-head .pb-aichat-spark {
            width: 26px; height: 26px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6366f1, #06b6d4);
            color: #fff; font-size: 14px; flex: 0 0 auto;
        }
        .pb-aichat-title { font-weight: 650; font-size: 14px; letter-spacing: 0.01em; flex: 1 1 auto; }
        .pb-aichat-iconbtn {
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.04);
            color: #cbd5e1;
            border-radius: 8px;
            padding: 4px 9px;
            font-size: 12px;
            cursor: pointer;
            line-height: 1.5;
        }
        .pb-aichat-iconbtn:hover { background: rgba(255, 255, 255, 0.10); color: #fff; }

        /* ── Message list ── */
        .pb-aichat-msgs {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.4) transparent;
        }
        .pb-aichat-msgs::-webkit-scrollbar { width: 8px; }
        .pb-aichat-msgs::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.35); border-radius: 8px; }

        .pb-aichat-row { display: flex; }
        .pb-aichat-row.pb-aichat-user { justify-content: flex-end; }
        .pb-aichat-row.pb-aichat-assistant { justify-content: flex-start; }

        .pb-aichat-bubble {
            max-width: 86%;
            padding: 9px 12px;
            border-radius: 14px;
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }
        .pb-aichat-user .pb-aichat-bubble {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        .pb-aichat-assistant .pb-aichat-bubble {
            background: rgba(30, 41, 59, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.07);
            color: #e2e8f0;
            border-bottom-left-radius: 4px;
        }
        .pb-aichat-assistant.pb-aichat-notice .pb-aichat-bubble {
            background: rgba(120, 53, 15, 0.25);
            border-color: rgba(245, 158, 11, 0.4);
            color: #fcd9a6;
        }

        .pb-aichat-empty {
            margin: auto;
            text-align: center;
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
            padding: 20px;
            max-width: 280px;
        }
        .pb-aichat-empty .pb-aichat-empty-spark { font-size: 28px; display: block; margin-bottom: 8px; }

        /* ── Proposed-changes plan card ── */
        .pb-aichat-plan {
            margin-top: 8px;
            border: 1px solid rgba(99, 102, 241, 0.35);
            background: rgba(15, 23, 42, 0.6);
            border-radius: 12px;
            padding: 11px 12px;
            font-size: 12px;
        }
        .pb-aichat-plan-h {
            font-weight: 650;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #a5b4fc;
            margin-bottom: 8px;
        }
        .pb-aichat-plan-counts { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
        .pb-aichat-chip {
            background: rgba(99, 102, 241, 0.16);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #c7d2fe;
            border-radius: 9999px;
            padding: 2px 9px;
            font-size: 11px;
            font-weight: 550;
        }
        .pb-aichat-plan-errors {
            margin-top: 8px;
            color: #fca5a5;
            font-size: 11.5px;
            line-height: 1.5;
        }
        .pb-aichat-plan-errors div { margin-top: 2px; }

        /* ── Detailed plan breakdown (scrollable, monospace keys) ── */
        .pb-aichat-plan-detail {
            margin-top: 8px;
            max-height: 260px;
            overflow-y: auto;
            border-top: 1px solid rgba(255, 255, 255, 0.07);
            padding-top: 8px;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.4) transparent;
        }
        .pb-aichat-plan-detail::-webkit-scrollbar { width: 6px; }
        .pb-aichat-plan-detail::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.35); border-radius: 8px; }
        .pb-aichat-sec { margin-bottom: 9px; }
        .pb-aichat-sec:last-child { margin-bottom: 0; }
        .pb-aichat-sec-h {
            font-size: 10px;
            font-weight: 650;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #818cf8;
            margin-bottom: 4px;
        }
        .pb-aichat-sec-list { list-style: none; margin: 0; padding: 0; }
        .pb-aichat-sec-list li {
            font-size: 11.5px;
            line-height: 1.5;
            color: #cbd5e1;
            padding: 2px 0;
        }
        .pb-aichat-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 11px;
            color: #a5b4fc;
        }
        .pb-aichat-fields { display: flex; flex-wrap: wrap; gap: 4px; margin: 3px 0 2px; }
        .pb-aichat-fchip {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 10px;
            background: rgba(45, 212, 191, 0.12);
            border: 1px solid rgba(45, 212, 191, 0.28);
            color: #5eead4;
            border-radius: 6px;
            padding: 1px 6px;
        }

        .pb-aichat-apply {
            margin-top: 10px;
            width: 100%;
            border: 0;
            border-radius: 9px;
            padding: 8px 12px;
            font-size: 12.5px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(135deg, #6366f1, #06b6d4);
        }
        .pb-aichat-apply:hover { filter: brightness(1.08); }
        .pb-aichat-apply:disabled { opacity: 0.5; cursor: not-allowed; }
        .pb-aichat-applied {
            margin-top: 10px;
            border-radius: 9px;
            padding: 8px 11px;
            font-size: 12px;
            font-weight: 550;
            background: rgba(16, 185, 129, 0.14);
            border: 1px solid rgba(16, 185, 129, 0.4);
            color: #86efac;
        }
        .pb-aichat-applied.pb-aichat-applied-warn {
            background: rgba(120, 53, 15, 0.25);
            border-color: rgba(245, 158, 11, 0.4);
            color: #fcd9a6;
        }

        /* ── Thinking indicator ── */
        .pb-aichat-thinking { display: flex; gap: 4px; align-items: center; padding: 4px 2px; }
        .pb-aichat-thinking span {
            width: 7px; height: 7px; border-radius: 9999px;
            background: #818cf8;
            animation: pb-aichat-bounce 1.2s infinite ease-in-out both;
        }
        .pb-aichat-thinking span:nth-child(2) { animation-delay: 0.15s; }
        .pb-aichat-thinking span:nth-child(3) { animation-delay: 0.3s; }
        @keyframes pb-aichat-bounce {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) {
            .pb-aichat-thinking span { animation: none; opacity: 0.8; }
        }

        /* ── Composer ── */
        .pb-aichat-composer {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .pb-aichat-composer-row {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        /* ── Mode selector (segmented pills) ── */
        .pb-aichat-modes {
            display: flex;
            gap: 4px;
            background: rgba(2, 6, 23, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 9px;
            padding: 3px;
            width: fit-content;
        }
        .pb-aichat-mode {
            border: 0;
            background: transparent;
            color: #94a3b8;
            font-size: 11.5px;
            font-weight: 550;
            font-family: inherit;
            padding: 4px 11px;
            border-radius: 7px;
            cursor: pointer;
            line-height: 1.3;
        }
        .pb-aichat-mode:hover { color: #e2e8f0; }
        .pb-aichat-mode.pb-aichat-mode-active {
            background: linear-gradient(135deg, #6366f1, #06b6d4);
            color: #fff;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.4);
        }
        .pb-aichat-input {
            flex: 1 1 auto;
            resize: none;
            max-height: 140px;
            min-height: 42px;
            border-radius: 11px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(2, 6, 23, 0.6);
            color: #e2e8f0;
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.45;
            font-family: inherit;
            outline: none;
        }
        .pb-aichat-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
        .pb-aichat-input::placeholder { color: #64748b; }
        .pb-aichat-input:disabled { opacity: 0.55; }
        .pb-aichat-send {
            flex: 0 0 auto;
            border: 0;
            border-radius: 11px;
            width: 42px;
            height: 42px;
            cursor: pointer;
            color: #fff;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6366f1, #06b6d4);
        }
        .pb-aichat-send:hover { filter: brightness(1.08); }
        .pb-aichat-send:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
    @verbatim
    <script>
        (function () {
            // Idempotent across full loads AND wire:navigate SPA navigations: the
            // render hook re-injects this block on every page, so guard the wiring
            // on a window flag — listeners + state are created exactly once per
            // browser page lifetime. ensureMounted() (called on boot AND on every
            // livewire:navigated) re-creates the DOM if a stray morph removed it.
            if (window.__pbChatBooted) { window.__pbChat && window.__pbChat.ensureMounted(); return; }
            window.__pbChatBooted = true;

            var BASE = (window.__pbChatBase || '').replace(/\/+$/, '');
            var CSRF = window.__pbChatCsrf || '';
            var STORE_KEY = 'pb_ai_chat';
            var STORE_MODE_KEY = 'pb_ai_chat_mode';
            var SECTIONS = ['collections', 'states', 'functions', 'flows', 'pages'];
            var MODES = [
                { id: 'auto',  label: 'Auto',  hint: 'AI decides' },
                { id: 'ask',   label: 'Ask',   hint: 'Answer only' },
                { id: 'plan',  label: 'Plan',  hint: 'Plan it with me' },
                { id: 'build', label: 'Build', hint: 'Build it' },
            ];
            function loadMode() {
                try {
                    var m = localStorage.getItem(STORE_MODE_KEY);
                    return MODES.some(function (x) { return x.id === m; }) ? m : 'auto';
                } catch (e) { return 'auto'; }
            }
            var mode = loadMode();

            // ── State ──────────────────────────────────────────────────────────
            // thread: [{ role, content (raw — sent to model), display (shown),
            //            plan, errors, notice?, applied? }]
            var thread = loadThread();
            var open = false;
            var busy = false;

            // DOM handles (re-resolved by ensureMounted).
            var fab = null, drawer = null, msgsEl = null, inputEl = null, sendBtn = null;

            function loadThread() {
                try {
                    var raw = localStorage.getItem(STORE_KEY);
                    var arr = raw ? JSON.parse(raw) : [];
                    return Array.isArray(arr) ? arr : [];
                } catch (e) { return []; }
            }
            function saveThread() {
                try { localStorage.setItem(STORE_KEY, JSON.stringify(thread)); } catch (e) {}
            }

            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            // ── Mode selector (Auto / Ask / Plan / Build) ───────────────────────
            function modesHtml() {
                return MODES.map(function (m) {
                    var active = m.id === mode ? ' pb-aichat-mode-active' : '';
                    return '<button type="button" class="pb-aichat-mode' + active + '" '
                        + 'data-pb-mode="' + m.id + '" role="radio" '
                        + 'aria-checked="' + (m.id === mode ? 'true' : 'false') + '" '
                        + 'title="' + esc(m.hint) + '" aria-label="' + esc(m.label + ': ' + m.hint) + '">'
                        + esc(m.label) + '</button>';
                }).join('');
            }
            function setMode(id) {
                if (! MODES.some(function (m) { return m.id === id; })) { return; }
                mode = id;
                try { localStorage.setItem(STORE_MODE_KEY, mode); } catch (e) {}
                var modesEl = drawer && drawer.querySelector('[data-pb-modes]');
                if (modesEl) { modesEl.innerHTML = modesHtml(); }
            }

            // ── DOM construction (built once, lives on document.body) ───────────
            function buildDom() {
                fab = document.createElement('button');
                fab.type = 'button';
                fab.className = 'pb-aichat pb-aichat-fab';
                fab.setAttribute('aria-label', 'Build with AI');
                fab.title = 'Build with AI';
                fab.innerHTML = '✦';
                fab.addEventListener('click', toggle);

                drawer = document.createElement('div');
                drawer.className = 'pb-aichat pb-aichat-drawer';
                drawer.setAttribute('role', 'dialog');
                drawer.setAttribute('aria-label', 'Build with AI');
                drawer.innerHTML =
                    '<div class="pb-aichat-head">'
                    + '<div class="pb-aichat-spark">✦</div>'
                    + '<div class="pb-aichat-title">Build with AI</div>'
                    + '<button type="button" class="pb-aichat-iconbtn" data-pb-clear>Clear</button>'
                    + '<button type="button" class="pb-aichat-iconbtn" data-pb-close aria-label="Close">✕</button>'
                    + '</div>'
                    + '<div class="pb-aichat-msgs" data-pb-msgs></div>'
                    + '<div class="pb-aichat-composer">'
                    + '<div class="pb-aichat-modes" data-pb-modes role="radiogroup" aria-label="Response mode">' + modesHtml() + '</div>'
                    + '<div class="pb-aichat-composer-row">'
                    + '<textarea class="pb-aichat-input" data-pb-input rows="1" placeholder="Describe the app to build or change…"></textarea>'
                    + '<button type="button" class="pb-aichat-send" data-pb-send aria-label="Send">↑</button>'
                    + '</div>'
                    + '</div>';

                document.body.appendChild(fab);
                document.body.appendChild(drawer);

                msgsEl = drawer.querySelector('[data-pb-msgs]');
                inputEl = drawer.querySelector('[data-pb-input]');
                sendBtn = drawer.querySelector('[data-pb-send]');

                drawer.querySelector('[data-pb-close]').addEventListener('click', close);
                drawer.querySelector('[data-pb-clear]').addEventListener('click', clearThread);
                sendBtn.addEventListener('click', send);

                inputEl.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && ! e.shiftKey) { e.preventDefault(); send(); }
                });
                inputEl.addEventListener('input', autoGrow);

                // Mode selector (segmented control).
                var modesEl = drawer.querySelector('[data-pb-modes]');
                if (modesEl) {
                    modesEl.addEventListener('click', function (e) {
                        var pill = e.target.closest ? e.target.closest('[data-pb-mode]') : null;
                        if (pill) { setMode(pill.getAttribute('data-pb-mode')); }
                    });
                }

                // Event delegation for Apply buttons (messages are re-rendered).
                msgsEl.addEventListener('click', function (e) {
                    var btn = e.target.closest ? e.target.closest('[data-pb-apply]') : null;
                    if (btn) { applyPlan(parseInt(btn.getAttribute('data-pb-apply'), 10)); }
                });
            }

            // Re-create the root if a navigation/morph removed it. Because the
            // root is appended to document.body (outside the Livewire-morphed
            // root) it normally survives — this is a belt-and-suspenders check.
            function ensureMounted() {
                if (! fab || ! fab.isConnected) {
                    if (fab && fab.parentNode) { fab.parentNode.removeChild(fab); }
                    if (drawer && drawer.parentNode) { drawer.parentNode.removeChild(drawer); }
                    buildDom();
                    render();
                    applyOpenState();
                }
            }

            // ── Open / close ────────────────────────────────────────────────────
            function applyOpenState() {
                if (! drawer || ! fab) { return; }
                drawer.classList.toggle('pb-aichat-open', open);
                fab.classList.toggle('pb-aichat-fab-hidden', open);
            }
            function toggle() { open ? close() : openDrawer(); }
            function openDrawer() {
                open = true; applyOpenState();
                scrollToBottom();
                setTimeout(function () { if (inputEl) { inputEl.focus(); } }, 60);
            }
            function close() { open = false; applyOpenState(); }

            function autoGrow() {
                if (! inputEl) { return; }
                inputEl.style.height = 'auto';
                inputEl.style.height = Math.min(inputEl.scrollHeight, 140) + 'px';
            }

            function scrollToBottom() {
                if (msgsEl) { requestAnimationFrame(function () { msgsEl.scrollTop = msgsEl.scrollHeight; }); }
            }

            // ── Rendering ────────────────────────────────────────────────────────
            function planCounts(plan) {
                var out = [];
                if (! plan || typeof plan !== 'object') { return out; }
                SECTIONS.forEach(function (k) {
                    var v = plan[k];
                    var n = Array.isArray(v) ? v.length : 0;
                    if (n > 0) { out.push({ label: n + ' ' + (n === 1 ? singular(k) : k), key: k }); }
                });
                var settings = plan.settings;
                if (settings && typeof settings === 'object' && Object.keys(settings).length) {
                    out.push({ label: 'settings', key: 'settings' });
                }
                return out;
            }
            function singular(k) { return k.replace(/s$/, ''); }

            function hasPlan(plan) { return planCounts(plan).length > 0; }

            // ── Detailed plan breakdown ─────────────────────────────────────────
            // Renders WHAT the plan contains (not just counts), grouped under
            // section headers. Every field is read defensively — the model may
            // omit any of them.
            function arr(v) { return Array.isArray(v) ? v : []; }
            function mono(s) { return '<span class="pb-aichat-mono">' + esc(s) + '</span>'; }

            function fmtVal(v) {
                if (v === true) { return 'true'; }
                if (v === false) { return 'false'; }
                if (v === null || v === undefined) { return ''; }
                if (typeof v === 'object') {
                    try { return JSON.stringify(v); } catch (e) { return String(v); }
                }
                return String(v);
            }

            function detailSection(title, itemsHtml) {
                if (! itemsHtml) { return ''; }
                return '<div class="pb-aichat-sec">'
                    + '<div class="pb-aichat-sec-h">' + esc(title) + '</div>'
                    + '<ul class="pb-aichat-sec-list">' + itemsHtml + '</ul>'
                    + '</div>';
            }

            function planDetailHtml(plan) {
                if (! plan || typeof plan !== 'object') { return ''; }
                var out = '';

                // Collections: "name (key)" + field chips key·type.
                out += detailSection('Collections', arr(plan.collections).map(function (c) {
                    c = c || {};
                    var head = esc(c.name || c.key || '(unnamed)');
                    if (c.key) { head += ' ' + mono('(' + c.key + ')'); }
                    var fields = arr(c.fields).map(function (f) {
                        f = f || {};
                        var t = f.type ? ('·' + f.type) : '';
                        return '<span class="pb-aichat-fchip">' + esc((f.key || f.label || '?') + t) + '</span>';
                    }).join('');
                    return '<li>' + head + (fields ? '<div class="pb-aichat-fields">' + fields + '</div>' : '') + '</li>';
                }).join(''));

                // States: key = value (with type hint).
                out += detailSection('States', arr(plan.states).map(function (s) {
                    s = s || {};
                    var v = fmtVal(s.value);
                    var t = s.type ? (' ' + mono(':' + s.type)) : '';
                    return '<li>' + mono(s.key || '?') + t + (v !== '' ? ' = ' + mono(v) : '') + '</li>';
                }).join(''));

                // Functions: slug (+ runtime).
                out += detailSection('Functions', arr(plan.functions).map(function (f) {
                    f = f || {};
                    var name = f.name ? (esc(f.name) + ' ') : '';
                    var rt = f.runtime ? ' ' + mono('[' + f.runtime + ']') : '';
                    return '<li>' + name + mono(f.slug || '?') + rt + '</li>';
                }).join(''));

                // Flows: slug — trigger_type, N nodes.
                out += detailSection('Flows', arr(plan.flows).map(function (fl) {
                    fl = fl || {};
                    var name = fl.name ? (esc(fl.name) + ' ') : '';
                    var trig = fl.trigger_type ? (' — ' + esc(fl.trigger_type)) : '';
                    var nodes = (fl.definition && fl.definition.nodes && typeof fl.definition.nodes === 'object')
                        ? Object.keys(fl.definition.nodes).length : 0;
                    var ncount = nodes ? (', ' + nodes + ' node' + (nodes === 1 ? '' : 's')) : '';
                    return '<li>' + name + mono(fl.slug || '?') + trig + ncount + '</li>';
                }).join(''));

                // Pages: slug · kind · status.
                out += detailSection('Pages', arr(plan.pages).map(function (p) {
                    p = p || {};
                    var title = p.title ? (esc(p.title) + ' ') : '';
                    var bits = [p.slug, p.kind, p.status].filter(Boolean).map(function (x) { return esc(x); }).join(' · ');
                    return '<li>' + title + (bits ? mono(bits) : '') + '</li>';
                }).join(''));

                // Settings: e.g. home_page = <slug>.
                var settings = plan.settings;
                if (settings && typeof settings === 'object') {
                    var setItems = Object.keys(settings).map(function (k) {
                        return '<li>' + mono(k) + ' = ' + mono(fmtVal(settings[k])) + '</li>';
                    }).join('');
                    out += detailSection('Settings', setItems);
                }

                return out ? '<div class="pb-aichat-plan-detail">' + out + '</div>' : '';
            }

            function planCardHtml(msg, idx) {
                var counts = planCounts(msg.plan);
                if (! counts.length) { return ''; }
                var chips = counts.map(function (c) { return '<span class="pb-aichat-chip">' + esc(c.label) + '</span>'; }).join('');
                var detail = planDetailHtml(msg.plan);

                // Already applied → green summary, no button.
                if (msg.applied) {
                    var cls = msg.applied.warn ? ' pb-aichat-applied-warn' : '';
                    return '<div class="pb-aichat-plan">'
                        + '<div class="pb-aichat-plan-h">Proposed changes</div>'
                        + '<div class="pb-aichat-plan-counts">' + chips + '</div>'
                        + detail
                        + '<div class="pb-aichat-applied' + cls + '">' + esc(msg.applied.text) + '</div>'
                        + '</div>';
                }

                var errs = (msg.errors && msg.errors.length)
                    ? '<div class="pb-aichat-plan-errors">' + msg.errors.map(function (e) { return '<div>⚠ ' + esc(e) + '</div>'; }).join('') + '</div>'
                    : '';
                var disabled = (msg.errors && msg.errors.length) ? ' disabled' : '';
                var btn = '<button type="button" class="pb-aichat-apply" data-pb-apply="' + idx + '"' + disabled + '>Apply changes</button>';

                return '<div class="pb-aichat-plan">'
                    + '<div class="pb-aichat-plan-h">Proposed changes</div>'
                    + '<div class="pb-aichat-plan-counts">' + chips + '</div>'
                    + detail
                    + errs
                    + btn
                    + '</div>';
            }

            function render() {
                if (! msgsEl) { return; }
                if (! thread.length && ! busy) {
                    msgsEl.innerHTML =
                        '<div class="pb-aichat-empty">'
                        + '<span class="pb-aichat-empty-spark">✦</span>'
                        + 'Describe the app you want and I’ll propose collections, pages, flows and more. '
                        + 'Review the plan, then Apply.'
                        + '</div>';
                    return;
                }

                var html = thread.map(function (msg, idx) {
                    var roleClass = msg.role === 'user' ? 'pb-aichat-user' : 'pb-aichat-assistant';
                    var noticeClass = msg.notice ? ' pb-aichat-notice' : '';
                    var text = msg.display != null ? msg.display : msg.content;
                    var plan = (msg.role === 'assistant') ? planCardHtml(msg, idx) : '';
                    // When a plan card is attached, the bubble's max-width is lifted
                    // (the card wants the wider column) and applied to the wrapper.
                    var bubble = '<div class="pb-aichat-bubble"' + (plan ? ' style="max-width:none;"' : '') + '>' + esc(text) + '</div>';
                    var inner = plan ? ('<div style="max-width:86%;">' + bubble + plan + '</div>') : bubble;
                    return '<div class="pb-aichat-row ' + roleClass + noticeClass + '">' + inner + '</div>';
                }).join('');

                if (busy) {
                    html += '<div class="pb-aichat-row pb-aichat-assistant">'
                        + '<div class="pb-aichat-bubble"><div class="pb-aichat-thinking"><span></span><span></span><span></span></div></div>'
                        + '</div>';
                }

                msgsEl.innerHTML = html;
                scrollToBottom();
            }

            // ── Composer disabled state ───────────────────────────────────────────
            function setBusy(v) {
                busy = v;
                if (inputEl) { inputEl.disabled = v || composerLocked; }
                if (sendBtn) { sendBtn.disabled = v || composerLocked; }
            }
            var composerLocked = false; // set true when the gateway is unavailable

            // ── Networking ────────────────────────────────────────────────────────
            function post(path, body) {
                return fetch(BASE + path, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(body || {}),
                });
            }

            function send() {
                if (busy || composerLocked || ! inputEl) { return; }
                var text = inputEl.value.trim();
                if (! text) { return; }

                thread.push({ role: 'user', content: text, display: text });
                inputEl.value = '';
                autoGrow();
                saveThread();
                setBusy(true);
                render();

                // Send the FULL thread each turn, using stored `content` (raw for
                // assistant turns) so the model keeps continuity.
                var payload = thread.map(function (m) {
                    return { role: m.role === 'assistant' ? 'assistant' : 'user', content: String(m.content || '') };
                });

                post('/ai-chat', { messages: payload, mode: mode })
                    .then(function (r) { return r.json().catch(function () { return {}; }); })
                    .then(function (d) {
                        d = d || {};
                        if (d.available === false) {
                            composerLocked = true;
                            thread.push({ role: 'assistant', content: d.reply || 'AI is unavailable.', display: d.reply || 'AI is unavailable.', notice: true });
                            saveThread();
                            setBusy(false);
                            render();
                            return;
                        }
                        var reply = d.reply != null ? String(d.reply) : '';
                        var raw = d.raw != null ? String(d.raw) : '';
                        var plan = (d.plan && typeof d.plan === 'object') ? d.plan : {};
                        var errors = Array.isArray(d.errors) ? d.errors : [];
                        thread.push({
                            role: 'assistant',
                            content: raw || reply, // raw kept for model continuity
                            display: reply || raw,
                            plan: hasPlan(plan) ? plan : null,
                            errors: errors,
                        });
                        saveThread();
                        setBusy(false);
                        render();
                    })
                    .catch(function () {
                        thread.push({ role: 'assistant', content: 'Network error — please try again.', display: 'Network error — please try again.', notice: true });
                        saveThread();
                        setBusy(false);
                        render();
                    });
            }

            function applyPlan(idx) {
                var msg = thread[idx];
                if (! msg || ! msg.plan || msg.applied || busy) { return; }

                // Disable the button immediately (re-render with a transient lock).
                var btn = msgsEl && msgsEl.querySelector('[data-pb-apply="' + idx + '"]');
                if (btn) { btn.disabled = true; btn.textContent = 'Applying…'; }

                post('/ai-chat/apply', { plan: msg.plan })
                    .then(function (r) {
                        return r.json().catch(function () { return {}; }).then(function (d) {
                            return { ok: r.ok, body: d || {} };
                        });
                    })
                    .then(function (res) {
                        var d = res.body;
                        if (! res.ok) {
                            // Controller returns { message } on 422/500.
                            if (btn) { btn.disabled = false; btn.textContent = 'Apply changes'; }
                            thread.push({ role: 'assistant', content: 'Apply failed: ' + (d.message || 'unknown error'), display: 'Apply failed: ' + (d.message || 'unknown error'), notice: true });
                            saveThread();
                            render();
                            return;
                        }
                        var created = (d.created && typeof d.created === 'object') ? d.created : {};
                        var parts = [];
                        SECTIONS.concat(['settings']).forEach(function (k) {
                            var v = created[k];
                            var n = Array.isArray(v) ? v.length : 0;
                            if (n > 0) { parts.push(n + ' ' + (n === 1 ? singular(k) : k)); }
                        });
                        var errs = Array.isArray(d.errors) ? d.errors.filter(Boolean) : [];
                        var summary = parts.length ? ('✓ Applied: ' + parts.join(', ')) : '✓ Applied.';
                        if (errs.length) { summary += '  ⚠ ' + errs.join('; '); }
                        msg.applied = { text: summary, warn: errs.length > 0 };
                        saveThread();
                        render();
                    })
                    .catch(function () {
                        if (btn) { btn.disabled = false; btn.textContent = 'Apply changes'; }
                        thread.push({ role: 'assistant', content: 'Apply failed: network error.', display: 'Apply failed: network error.', notice: true });
                        saveThread();
                        render();
                    });
            }

            function clearThread() {
                thread = [];
                composerLocked = false;
                try { localStorage.removeItem(STORE_KEY); } catch (e) {}
                setBusy(false);
                render();
                if (inputEl) { inputEl.focus(); }
            }

            // ── Global key handling (Escape closes) ──────────────────────────────
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && open) { close(); }
            });

            // ── Boot + survive SPA navigation ─────────────────────────────────────
            buildDom();
            render();
            applyOpenState();

            document.addEventListener('livewire:navigated', function () { ensureMounted(); });

            window.__pbChat = { ensureMounted: ensureMounted, open: openDrawer, close: close };
        })();
    </script>
    @endverbatim
@endonce
