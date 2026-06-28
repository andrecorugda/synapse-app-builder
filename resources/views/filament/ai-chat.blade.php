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
            gap: 8px;
            align-items: flex-end;
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
            var SECTIONS = ['collections', 'states', 'functions', 'flows', 'pages'];

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
                    + '<textarea class="pb-aichat-input" data-pb-input rows="1" placeholder="Describe the app to build or change…"></textarea>'
                    + '<button type="button" class="pb-aichat-send" data-pb-send aria-label="Send">↑</button>'
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

            function planCardHtml(msg, idx) {
                var counts = planCounts(msg.plan);
                if (! counts.length) { return ''; }
                var chips = counts.map(function (c) { return '<span class="pb-aichat-chip">' + esc(c.label) + '</span>'; }).join('');

                // Already applied → green summary, no button.
                if (msg.applied) {
                    var cls = msg.applied.warn ? ' pb-aichat-applied-warn' : '';
                    return '<div class="pb-aichat-plan">'
                        + '<div class="pb-aichat-plan-h">Proposed changes</div>'
                        + '<div class="pb-aichat-plan-counts">' + chips + '</div>'
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

                post('/ai-chat', { messages: payload })
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
