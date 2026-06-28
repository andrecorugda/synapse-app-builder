@php
    $flowBase = url(config('ai-page-builder.routes.flow_prefix', 'pb-flow'));
@endphp
<script>
(function () {
    if (window.__pbFlowRuntimeBound) { return; }
    window.__pbFlowRuntimeBound = true;

    var FLOW_BASE = '{{ $flowBase }}';

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
        }
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

    /** Handle a click on a [data-pb-flow] element. */
    function handleFlowClick(event) {
        var triggerEl = event.target.closest('[data-pb-flow]');
        if (!triggerEl) { return; }

        event.preventDefault();

        var slug = triggerEl.getAttribute('data-pb-flow');
        if (!slug) { return; }

        var explicitInput = {};
        var rawInput = triggerEl.getAttribute('data-pb-flow-input');
        if (rawInput) {
            try { explicitInput = JSON.parse(rawInput); } catch (e) { /* ignore malformed JSON */ }
        }

        var formInput = collectFormInput(triggerEl);
        var mergedInput = Object.assign({}, formInput, explicitInput);

        fetch(FLOW_BASE + '/' + slug, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ input: mergedInput }),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data || !Array.isArray(data.actions)) { return; }
            data.actions.forEach(applyAction);
        })
        .catch(function (err) {
            console.error('[pb-flow] request error', err);
        });
    }

    document.addEventListener('click', handleFlowClick, false);
})();
</script>
