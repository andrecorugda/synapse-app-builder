@php
    // Origin-RELATIVE bases (path only, no scheme+host): navigation and fetches
    // then always stay on the exact scheme+host+port the visitor is on. Baking an
    // absolute url() here breaks behind a port map / TLS-terminating proxy, where
    // the server thinks it is https://host but the browser is on http://host:8088
    // (or vice-versa) — clicking a nav link would jump to the wrong origin.
    $pbPath = static fn (string $key, string $default): string => '/'.ltrim((string) (parse_url(url(config($key, $default)), PHP_URL_PATH) ?: $default), '/');
    $flowBase = $pbPath('ai-page-builder.routes.flow_prefix', 'pb-flow');
    $renderBase = $pbPath('ai-page-builder.routes.render_prefix', 'p');
    $apiBase = $pbPath('ai-page-builder.data.api_prefix', 'api/pb');

    // Browser-side state watchers: fire a flow when the page's live $store.app
    // changes (server-side watchers cover persisted writes — see
    // WatcherDispatcher). Condition keys are included only when set, so the JS
    // can distinguish "no from/to filter" from "must equal null". Rendered pages
    // are cached, so watcher saves flush the render cache (see provider).
    $pbStateWatchers = [];
    try {
        $watcherClass = config('ai-page-builder.models.watcher', \Andre\AiPageBuilder\Models\Watcher::class);
        $pbStateWatchers = $watcherClass::query()
            ->where('is_active', true)
            ->where('source_type', 'state')
            ->get()
            ->filter(fn ($w): bool => (($w->config['side'] ?? 'server') === 'client') && $w->target_type === 'flow')
            ->map(function ($w): array {
                $cfg = (array) ($w->config ?? []);
                $out = ['key' => (string) $w->source_key, 'flow' => (string) $w->target_key];
                foreach (['path', 'from', 'to', 'op', 'value'] as $k) {
                    if (array_key_exists($k, $cfg)) {
                        $out[$k] = $cfg[$k];
                    }
                }

                return $out;
            })
            ->values()
            ->all();
    } catch (\Throwable) {
        // Table absent (pre-migration) — render without watchers.
    }
@endphp
<script>
(function () {
    if (window.__pbFlowRuntimeBound) { return; }
    window.__pbFlowRuntimeBound = true;

    var FLOW_BASE = '{{ $flowBase }}';
    var RENDER_BASE = '{{ $renderBase }}';
    var API_BASE = '{{ $apiBase }}';
    var UPLOAD_BASE = '/pb-upload';   // gated public image upload (origin-relative)

    /** Per-level toast palette — background, foreground, accent bar, icon glyph. */
    var TOAST_LEVELS = {
        success: { bg: '#064e3b', fg: '#ecfdf5', bar: '#10b981', icon: '✓' },
        error:   { bg: '#7f1d1d', fg: '#fef2f2', bar: '#f87171', icon: '✕' },
        warning: { bg: '#78350f', fg: '#fffbeb', bar: '#f59e0b', icon: '!' },
        info:    { bg: '#1e293b', fg: '#f8fafc', bar: '#38bdf8', icon: 'i' },
    };

    /** Show a lightweight toast notification, styled by level (default: info). */
    function showToast(message, level) {
        var p = TOAST_LEVELS[level] || TOAST_LEVELS.info;
        var toast = document.createElement('div');
        toast.style.cssText = [
            'position:fixed',
            'bottom:1.5rem',
            'right:1.5rem',
            'z-index:99999',
            'display:flex',
            'align-items:center',
            'gap:.6rem',
            'max-width:22rem',
            'background:' + p.bg,
            'color:' + p.fg,
            'padding:.75rem 1.25rem',
            'border-radius:.5rem',
            'border-left:4px solid ' + p.bar,
            'font-size:.875rem',
            'box-shadow:0 4px 12px rgba(0,0,0,.25)',
            'transition:opacity .3s',
            'opacity:1',
        ].join(';');

        var badge = document.createElement('span');
        badge.textContent = p.icon;
        badge.style.cssText = [
            'flex:0 0 auto',
            'width:1.15rem',
            'height:1.15rem',
            'border-radius:9999px',
            'display:inline-flex',
            'align-items:center',
            'justify-content:center',
            'font-size:.72rem',
            'font-weight:700',
            'background:' + p.bar,
            'color:' + p.bg,
        ].join(';');

        var text = document.createElement('span');
        text.textContent = String(message);

        toast.appendChild(badge);
        toast.appendChild(text);
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () { toast.parentNode && toast.parentNode.removeChild(toast); }, 350);
        }, 4000);
    }

    /**
     * Show a blocking, styled alert dialog (title + message + OK). Falls back
     * to window.alert if the DOM isn't ready. Mirrors showToast()'s dark look.
     */
    function showAlert(title, message) {
        if (! document.body) {
            window.alert((title ? title + '\n' : '') + (message || ''));
            return;
        }
        var overlay = document.createElement('div');
        overlay.style.cssText = [
            'position:fixed',
            'inset:0',
            'z-index:100000',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'background:rgba(2,6,23,.55)',
            'padding:1rem',
        ].join(';');

        var box = document.createElement('div');
        box.style.cssText = [
            'background:#1e293b',
            'color:#f8fafc',
            'max-width:24rem',
            'width:100%',
            'border-radius:.75rem',
            'box-shadow:0 12px 40px rgba(0,0,0,.45)',
            'padding:1.25rem 1.25rem 1rem',
            'font-size:.9rem',
        ].join(';');

        if (title) {
            var h = document.createElement('div');
            h.style.cssText = 'font-weight:600;font-size:1rem;margin-bottom:.4rem;';
            h.textContent = String(title);
            box.appendChild(h);
        }
        if (message) {
            var p = document.createElement('div');
            p.style.cssText = 'color:#cbd5e1;line-height:1.45;white-space:pre-wrap;';
            p.textContent = String(message);
            box.appendChild(p);
        }

        var actions = document.createElement('div');
        actions.style.cssText = 'display:flex;justify-content:flex-end;margin-top:1rem;';
        var ok = document.createElement('button');
        ok.type = 'button';
        ok.textContent = 'OK';
        ok.style.cssText = [
            'background:#4f46e5',
            'color:#eef2ff',
            'border:0',
            'border-radius:.5rem',
            'padding:.4rem 1.1rem',
            'font-size:.85rem',
            'font-weight:600',
            'cursor:pointer',
        ].join(';');
        actions.appendChild(ok);
        box.appendChild(actions);
        overlay.appendChild(box);

        function close() {
            document.removeEventListener('keydown', onKey, true);
            overlay.parentNode && overlay.parentNode.removeChild(overlay);
        }
        function onKey(e) {
            if (e.key === 'Escape' || e.key === 'Enter') { e.preventDefault(); close(); }
        }
        ok.addEventListener('click', close);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
        document.addEventListener('keydown', onKey, true);

        document.body.appendChild(overlay);
        try { ok.focus(); } catch (e) {}
    }

    /** Apply a single result action to the page. */
    function applyAction(action) {
        var type = action.type;

        if (type === 'setHtml') {
            var targets = document.querySelectorAll(action.target);
            targets.forEach(function (el) { el.innerHTML = action.html || ''; });
            return;
        }

        if (type === 'setText') {
            var targets = document.querySelectorAll(action.target);
            targets.forEach(function (el) { el.textContent = action.text || ''; });
            return;
        }

        if (type === 'notify') {
            showToast(action.message || '', action.level);
            return;
        }

        if (type === 'alert') {
            showAlert(action.title || '', action.message || '');
            return;
        }

        if (type === 'modal') {
            if (! action.target) { return; }
            var open = action.action !== 'close';
            document.querySelectorAll(action.target).forEach(function (el) {
                // Resolve the modal ROOT (the designed Modal block carries the
                // Alpine x-data). The target may be the block, its overlay, or a
                // child — walk up to the block wrapper.
                var root = (el.closest && el.closest('[data-pb-block="modal"]')) || el;

                // Optionally swap the dialog CONTENT — into the body/panel only,
                // NEVER the Alpine root (that would destroy its x-data scope).
                if (open && typeof action.html === 'string' && action.html !== '') {
                    var slot = root.querySelector('.pb-modal__body') || root.querySelector('.pb-modal__panel');
                    if (slot) { slot.innerHTML = action.html; }
                }

                // A Modal block designed in the editor is Alpine-controlled
                // (x-data="{open}" + x-show). Flip that reactive state so it
                // opens/closes exactly as a user click would.
                var data = (window.Alpine && typeof window.Alpine.$data === 'function') ? window.Alpine.$data(root) : null;
                if (data && typeof data === 'object' && 'open' in data) {
                    data.open = open;
                    return;
                }
                // Fallback for custom (non-Alpine) modal markup.
                if (open) { root.classList.add('pb-modal--open'); root.style.display = ''; }
                else { root.classList.remove('pb-modal--open'); root.style.display = 'none'; }
            });
            return;
        }

        if (type === 'redirect') {
            if (action.url) {
                // newTab is truthy ('1') when the author picked "New tab".
                if (action.newTab) {
                    window.open(action.url, '_blank', 'noopener');
                } else {
                    window.location.href = action.url;
                }
            }
            return;
        }

        if (type === 'logout') {
            // End the pb session, then return to login (or action.url). CSRF from
            // the per-session XSRF-TOKEN cookie — never baked into cached HTML.
            var m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
            var dest = action.url || '{{ url('/'.trim((string) config('ai-page-builder.auth.login_path', 'login'), '/')) }}';
            fetch('{{ url('pb-logout') }}', {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '', 'Accept': 'application/json' },
                credentials: 'same-origin',
            }).then(function () { window.location.href = dest; }).catch(function () { window.location.href = dest; });
            return;
        }

        if (type === 'addClass') {
            var targets = document.querySelectorAll(action.target);
            targets.forEach(function (el) {
                (action.class || '').split(/\s+/).filter(Boolean).forEach(function (c) { el.classList.add(c); });
            });
            return;
        }

        if (type === 'removeClass') {
            var targets = document.querySelectorAll(action.target);
            targets.forEach(function (el) {
                (action.class || '').split(/\s+/).filter(Boolean).forEach(function (c) { el.classList.remove(c); });
            });
            return;
        }

        // Reactive Store updates — bound components (x-text/x-show/x-model/x-for
        // on $store.app.*) re-render automatically.
        if (type === 'setState') {
            var store = pbStore();
            if (store && action.key != null) { suppressWatch(action.key, action.value); store[action.key] = action.value; }
            return;
        }

        if (type === 'setStates' && action.values && typeof action.values === 'object') {
            var s = pbStore();
            if (s) { Object.keys(action.values).forEach(function (k) { suppressWatch(k, action.values[k]); s[k] = action.values[k]; }); }
        }
    }

    /** Mark a store write as flow-made so state watchers don't re-fire on it
     *  (a watcher's flow that setStates its own watched key would loop). */
    var __pbSuppress = {};
    function suppressWatch(key, value) {
        var snap;
        try { snap = JSON.stringify(value === undefined ? null : value); } catch (e) { return; }
        __pbSuppress[key] = { v: snap, t: Date.now() };
    }

    /** The page's reactive Store (Alpine), or null if Alpine hasn't booted. */
    function pbStore() {
        return (window.Alpine && typeof window.Alpine.store === 'function') ? window.Alpine.store('app') : null;
    }

    /** Collect form fields nearest to the trigger element as a flat object. */
    function collectFormInput(triggerEl) {
        var form = triggerEl.closest('form');
        if (!form) { return {}; }
        var data = {};
        var elements = form.elements;
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            if (!el.name) { continue; }
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) { continue; }
            // A file input submits the UPLOADED URL (stashed after posting to
            // /pb-upload), never the raw file value.
            if (el.type === 'file') { data[el.name] = el.dataset.pbUrl || ''; continue; }
            data[el.name] = el.value;
        }
        return data;
    }

    /** Upgrade a form's file inputs to upload-on-select: POST the chosen image to
     *  /pb-upload and stash the returned URL on the input so it submits with the
     *  record (image fields on a public page; the endpoint is gated + validated). */
    function bindUploads(form) {
        var files = form.querySelectorAll('input[type="file"]');
        for (var i = 0; i < files.length; i++) {
            (function (input) {
                if (input.__pbUploadBound) { return; }
                input.__pbUploadBound = true;
                var status = document.createElement('span');
                status.style.cssText = 'display:block;font-size:.75rem;color:#64748b;margin:.15rem 0;';
                input.parentNode && input.parentNode.insertBefore(status, input.nextSibling);
                input.addEventListener('change', function () {
                    var file = input.files && input.files[0];
                    if (!file) { return; }
                    status.textContent = 'Uploading…';
                    var fd = new FormData();
                    fd.append('file', file);
                    var m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                    fetch(UPLOAD_BASE, {
                        method: 'POST',
                        headers: { 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '', 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        body: fd,
                    })
                    .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { status: r.status, body: b }; }); })
                    .then(function (res) {
                        if (res.status >= 200 && res.status < 300 && res.body.url) {
                            input.dataset.pbUrl = res.body.url;
                            status.innerHTML = '✓ uploaded — <a href="' + res.body.url + '" target="_blank" style="color:#4f46e5;">view</a>';
                        } else {
                            input.value = '';
                            status.textContent = (res.body && res.body.message) || (res.status === 403 ? 'Sign in to upload.' : 'Upload failed.');
                        }
                    })
                    .catch(function () { status.textContent = 'Upload failed.'; });
                });
            })(files[i]);
        }
    }

    /** Run the flow bound to a trigger element, merging form + explicit input. */
    function runFlow(triggerEl, event) {
        var slug = triggerEl.getAttribute('data-pb-flow');
        if (!slug) { return; }

        // Only suppress the default for activation-style events; never block
        // typing/focus on inputs (keydown/input/change/focus/blur).
        if (event && (event.type === 'click' || event.type === 'submit')) {
            event.preventDefault();
        }

        var explicitInput = {};
        var rawInput = triggerEl.getAttribute('data-pb-flow-input');
        if (rawInput) {
            try { explicitInput = JSON.parse(rawInput); } catch (e) { /* ignore malformed JSON */ }
        }

        // Include the page's reactive state ($store.app — carts, selections, etc.)
        // so a flow can act on it (e.g. checkout reads input.cart_items). Form
        // fields and an explicit data-pb-flow-input override it.
        var storeState = {};
        try {
            if (window.Alpine && typeof window.Alpine.store === 'function') {
                var s = window.Alpine.store('app');
                if (s && typeof s === 'object') { storeState = JSON.parse(JSON.stringify(s)); }
            }
        } catch (e) { /* ignore non-serialisable state */ }

        var formInput = collectFormInput(triggerEl);
        var mergedInput = Object.assign({}, storeState, formInput, explicitInput);

        fetch(FLOW_BASE + '/' + slug, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ input: mergedInput }),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data || !Array.isArray(data.actions)) { return; }
            data.actions.forEach(applyAction);
        })
        .catch(function (err) { console.error('[pb-flow] request error', err); });
    }

    /** Submit a [data-pb-record] form: create a record via the auto REST API. */
    function submitRecord(form, event) {
        var collection = form.getAttribute('data-pb-record');
        if (!collection) { return; }
        if (event) { event.preventDefault(); }

        var fields = collectFormInput(form);

        // Edit mode: when the form carries a record id (set by a table row's Edit
        // action, see page.blade.php), UPDATE that row (PUT) instead of creating one.
        var editId = form.getAttribute('data-pb-record-id') || '';
        var url = API_BASE + '/' + collection + (editId ? '/' + encodeURIComponent(editId) : '');
        if (editId) { delete fields.id; }

        fetch(url, {
            method: editId ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(fields),
        })
        .then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (body) {
                return { status: res.status, body: body };
            });
        })
        .then(function (result) {
            if (result.status >= 200 && result.status < 300) {
                showToast(editId ? 'Updated' : 'Saved');
                form.reset();
                form.removeAttribute('data-pb-record-id');           // back to create mode
                form.dispatchEvent(new CustomEvent('pb:record-created', { bubbles: true, detail: result.body }));
                return;
            }
            if (result.status === 422) {
                var errors = result.body && result.body.errors;
                var first = errors && Object.keys(errors)[0];
                var msg = (first && errors[first] && errors[first][0]) || (result.body && result.body.message) || 'Please check the form.';
                showToast(msg);
                return;
            }
            showToast('Something went wrong. Please try again.');
        })
        .catch(function (err) {
            console.error('[pb-record] request error', err);
            showToast('Something went wrong. Please try again.');
        });
    }

    /** Bind every [data-pb-flow] element to its chosen event, and page links. */
    function bind() {
        // Record-create forms. A form bound to a flow (data-pb-flow) is handled
        // by the flow runtime instead — flow takes precedence, skip it here.
        var recordForms = document.querySelectorAll('form[data-pb-record]');
        for (var r = 0; r < recordForms.length; r++) {
            (function (form) {
                if (form.__pbRecordBound) { return; }
                if (!form.getAttribute('data-pb-record')) { return; }
                if (form.hasAttribute('data-pb-flow')) { return; }
                form.__pbRecordBound = true;
                form.addEventListener('submit', function (e) { submitRecord(form, e); }, false);
                bindUploads(form);
            })(recordForms[r]);
        }

        var flowEls = document.querySelectorAll('[data-pb-flow]');
        for (var i = 0; i < flowEls.length; i++) {
            (function (el) {
                if (el.__pbFlowBound) { return; }
                if (!el.getAttribute('data-pb-flow')) { return; }
                el.__pbFlowBound = true;
                var ev = el.getAttribute('data-pb-flow-event') || 'click';
                el.addEventListener(ev, function (e) {
                    // A click-triggered flow bound to a CONTAINER (e.g. a section or
                    // the page body wrapping a form) must not fire when the user
                    // clicks a field to focus/type in it — only when they activate a
                    // real control (button/link/non-field area). If the click bubbled
                    // up from a form control inside the trigger, ignore it. (When the
                    // trigger IS the clicked element, or a nested element carries its
                    // own data-pb-flow, this doesn't apply.)
                    if (ev === 'click' && e.target !== el && e.target.closest) {
                        // If the click is on/inside a nested element with its OWN
                        // data-pb-flow, that inner trigger handles it — don't
                        // double-fire from the container.
                        var inner = e.target.closest('[data-pb-flow]');
                        if (inner && inner !== el && el.contains(inner)) { return; }
                        // Focusing a form field inside the container is not activation.
                        var field = e.target.closest('input, textarea, select, option, label, [contenteditable=""], [contenteditable="true"]');
                        if (field && el.contains(field)) { return; }
                    }
                    runFlow(el, e);
                }, false);
            })(flowEls[i]);
        }

        var pageEls = document.querySelectorAll('[data-pb-page]');
        var currentSlug = window.__pbCurrentSlug || '';
        for (var j = 0; j < pageEls.length; j++) {
            (function (el) {
                var slug = el.getAttribute('data-pb-page');
                if (!slug) { return; }
                // Auto-mark the current page's nav link so a shared nav partial
                // highlights the active page without hardcoding it. Idempotent.
                if (slug === currentSlug) {
                    el.classList.add('is-active');
                    el.setAttribute('aria-current', 'page');
                }
                if (el.__pbPageBound) { return; }
                el.__pbPageBound = true;
                el.style.cursor = 'pointer';
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.location.href = RENDER_BASE + '/' + slug;
                }, false);
            })(pageEls[j]);
        }
    }

    // ------------------------------------------------------------------
    // Browser-side state watchers — fire a flow when live $store.app state
    // changes (like a JS framework watcher). Injected server-side; each entry:
    // { key, flow, path?, from?, to?, op?, value? }.
    // ------------------------------------------------------------------
    var STATE_WATCHERS = @js($pbStateWatchers);

    /** data_get for the watched sub-path ('customer.city'). */
    function pbPathGet(value, path) {
        if (!path) { return value; }
        var parts = String(path).split('.');
        var cur = value;
        for (var i = 0; i < parts.length; i++) {
            if (cur == null || typeof cur !== 'object') { return undefined; }
            cur = cur[parts[i]];
        }
        return cur;
    }

    /** Mirror of the server dispatcher's operator semantics (loose compares). */
    function pbOpMatches(op, actual, expected) {
        var list = function () {
            return Array.isArray(expected)
                ? expected.map(String)
                : String(expected == null ? '' : expected).split(',').map(function (s) { return s.trim(); });
        };
        switch (op) {
            case 'eq': return actual == expected;
            case 'neq': return actual != expected;
            case 'gt': return Number(actual) > Number(expected);
            case 'gte': return Number(actual) >= Number(expected);
            case 'lt': return Number(actual) < Number(expected);
            case 'lte': return Number(actual) <= Number(expected);
            case 'like': return expected != null && String(actual).indexOf(String(expected)) !== -1;
            case 'in': return list().indexOf(String(actual)) !== -1;
            case 'nin': return list().indexOf(String(actual)) === -1;
            default: return false;
        }
    }

    /** POST the watcher's flow with the change payload (mirrors the server
     *  dispatcher input shape) + the live store, and apply returned actions. */
    function fireStateWatcher(w, oldVal, newVal) {
        var storeState = {};
        try {
            var s = pbStore();
            if (s && typeof s === 'object') { storeState = JSON.parse(JSON.stringify(s)); }
        } catch (e) { /* ignore non-serialisable state */ }

        var input = Object.assign({}, storeState, {
            event: 'changed',
            key: w.key,
            path: w.path || null,
            old: oldVal,
            'new': newVal,
        });

        fetch(FLOW_BASE + '/' + w.flow, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ input: input }),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data || !Array.isArray(data.actions)) { return; }
            data.actions.forEach(applyAction);
        })
        .catch(function (err) { console.error('[pb-watch] request error', err); });
    }

    function installStateWatchers() {
        if (window.__pbStateWatchersBound) { return; }
        var store = pbStore();
        if (!STATE_WATCHERS.length || !store || !window.Alpine || typeof window.Alpine.effect !== 'function') { return; }
        window.__pbStateWatchersBound = true;

        var lastSeen = {};   // idx -> JSON snapshot of the WATCHED value
        var pending = {};    // idx -> { t: timer, old: burst-start value } (debounce)

        STATE_WATCHERS.forEach(function (w, idx) {
            window.Alpine.effect(function () {
                var keyVal = store[w.key];   // reactive read — re-runs on change
                var watched = pbPathGet(keyVal, w.path);
                var snap;
                try { snap = JSON.stringify(watched === undefined ? null : watched); } catch (e) { return; }

                // First run only records the baseline — page load is not a change.
                if (!(idx in lastSeen)) { lastSeen[idx] = snap; return; }
                if (lastSeen[idx] === snap) { return; }

                var oldSnap = lastSeen[idx];
                lastSeen[idx] = snap;

                // Skip changes the flow itself just made (loop guard).
                var sup = __pbSuppress[w.key];
                if (sup && (Date.now() - sup.t) < 500) {
                    var keySnap;
                    try { keySnap = JSON.stringify(store[w.key] === undefined ? null : store[w.key]); } catch (e) { keySnap = null; }
                    if (sup.v === keySnap) { return; }
                }

                // Debounce bursts (typing in an x-model input): keep the value
                // from BEFORE the burst as `old`, fire once it settles.
                if (pending[idx]) { clearTimeout(pending[idx].t); } else { pending[idx] = { old: oldSnap }; }
                pending[idx].t = setTimeout(function () {
                    var entry = pending[idx];
                    delete pending[idx];
                    var oldVal = null, newVal = null;
                    try { oldVal = JSON.parse(entry.old); } catch (e) { /* keep null */ }
                    try { newVal = JSON.parse(lastSeen[idx]); } catch (e) { /* keep null */ }

                    // Conditions evaluate on the settled change (server semantics).
                    if ('from' in w && oldVal != w.from) { return; }
                    if ('to' in w && newVal != w.to) { return; }
                    if (w.op && !pbOpMatches(w.op, newVal, 'value' in w ? w.value : null)) { return; }

                    fireStateWatcher(w, oldVal, newVal);
                }, 300);
            });
        });
    }

    // Alpine seeds $store.app on alpine:init; effects need the started store.
    document.addEventListener('alpine:initialized', installStateWatchers, false);
    if (window.Alpine && typeof window.Alpine.store === 'function') { installStateWatchers(); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind, false);
    } else {
        bind();
    }
})();
</script>
