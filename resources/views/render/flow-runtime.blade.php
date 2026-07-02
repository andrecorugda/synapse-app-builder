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
@endphp
<script>
(function () {
    if (window.__pbFlowRuntimeBound) { return; }
    window.__pbFlowRuntimeBound = true;

    var FLOW_BASE = '{{ $flowBase }}';
    var RENDER_BASE = '{{ $renderBase }}';
    var API_BASE = '{{ $apiBase }}';
    var UPLOAD_BASE = '/pb-upload';   // gated public image upload (origin-relative)

    /** Show a lightweight toast notification. */
    function showToast(message) {
        var toast = document.createElement('div');
        toast.style.cssText = [
            'position:fixed',
            'bottom:1.5rem',
            'right:1.5rem',
            'z-index:99999',
            'background:#1e293b',
            'color:#f8fafc',
            'padding:.75rem 1.25rem',
            'border-radius:.5rem',
            'font-size:.875rem',
            'box-shadow:0 4px 12px rgba(0,0,0,.25)',
            'transition:opacity .3s',
            'opacity:1',
        ].join(';');
        toast.textContent = String(message);
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
            showToast(action.message || '');
            return;
        }

        if (type === 'alert') {
            showAlert(action.title || '', action.message || '');
            return;
        }

        if (type === 'modal') {
            var modals = document.querySelectorAll(action.target);
            modals.forEach(function (el) {
                if (action.action === 'close') {
                    el.classList.remove('pb-modal--open');
                    el.style.display = 'none';
                    return;
                }
                // Default / 'open': optionally swap inner content, then reveal.
                if (typeof action.html === 'string' && action.html !== '') {
                    el.innerHTML = action.html;
                }
                el.classList.add('pb-modal--open');
                el.style.display = '';
            });
            return;
        }

        if (type === 'redirect') {
            if (action.url) { window.location.href = action.url; }
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
            if (store && action.key != null) { store[action.key] = action.value; }
            return;
        }

        if (type === 'setStates' && action.values && typeof action.values === 'object') {
            var s = pbStore();
            if (s) { Object.keys(action.values).forEach(function (k) { s[k] = action.values[k]; }); }
        }
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind, false);
    } else {
        bind();
    }
})();
</script>
