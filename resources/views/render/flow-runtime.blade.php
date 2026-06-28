@php
    $flowBase = url(config('ai-page-builder.routes.flow_prefix', 'pb-flow'));
    $renderBase = url(config('ai-page-builder.routes.render_prefix', 'p'));
@endphp
<script>
(function () {
    if (window.__pbFlowRuntimeBound) { return; }
    window.__pbFlowRuntimeBound = true;

    var FLOW_BASE = '{{ $flowBase }}';
    var RENDER_BASE = '{{ $renderBase }}';

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

        if (type === 'redirect') {
            if (action.url) { window.location.href = action.url; }
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
            data[el.name] = el.value;
        }
        return data;
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

        var formInput = collectFormInput(triggerEl);
        var mergedInput = Object.assign({}, formInput, explicitInput);

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

    /** Bind every [data-pb-flow] element to its chosen event, and page links. */
    function bind() {
        var flowEls = document.querySelectorAll('[data-pb-flow]');
        for (var i = 0; i < flowEls.length; i++) {
            (function (el) {
                if (el.__pbFlowBound) { return; }
                if (!el.getAttribute('data-pb-flow')) { return; }
                el.__pbFlowBound = true;
                var ev = el.getAttribute('data-pb-flow-event') || 'click';
                el.addEventListener(ev, function (e) { runFlow(el, e); }, false);
            })(flowEls[i]);
        }

        var pageEls = document.querySelectorAll('[data-pb-page]');
        for (var j = 0; j < pageEls.length; j++) {
            (function (el) {
                if (el.__pbPageBound) { return; }
                var slug = el.getAttribute('data-pb-page');
                if (!slug) { return; }
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
