{{-- Loads Drawflow once per panel page AND registers the aiPbFlow Alpine
     component. This is injected into the panel LAYOUT via a render hook
     (panels::body.end) — not the field's component view — because Livewire does
     not reliably compile/run scripts inside a sub-component view. The field view
     only carries the markup + x-data config call. --}}
@once
    <link rel="stylesheet" href="{{ config('ai-page-builder.flow.drawflow_css', 'https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.css') }}">
    <script src="{{ config('ai-page-builder.flow.drawflow_js', 'https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.js') }}"></script>
    @php
        // Inject the available functions + collections so the node editors can
        // offer dropdowns instead of free-text slugs/keys. Guarded — the tables
        // may not exist yet during early boot / migration.
        try {
            $fnClass = config('ai-page-builder.models.flow_function', \Andre\AiPageBuilder\Models\FlowFunction::class);
            $pbFlowFunctions = $fnClass::query()->orderBy('name')->get()
                ->map(fn ($f) => ['slug' => $f->slug, 'name' => $f->name])->values()->all();
        } catch (\Throwable $e) {
            $pbFlowFunctions = [];
        }
        try {
            $modelClass = config('ai-page-builder.models.model', \Andre\AiPageBuilder\Models\PbModel::class);
            $pbCollections = $modelClass::query()->orderBy('name')->get()
                ->map(fn ($m) => ['key' => $m->key, 'name' => $m->name])->values()->all();
        } catch (\Throwable $e) {
            $pbCollections = [];
        }
        try {
            // Saved flows feed the "Run Flow" step picker in transaction/loop bodies
            // (a flow can compose other flows). The current flow is excluded in the
            // editor to prevent direct self-reference.
            $flowClass = config('ai-page-builder.models.flow', \Andre\AiPageBuilder\Models\Flow::class);
            $pbFlows = $flowClass::query()->orderBy('name')->get()
                ->map(fn ($f) => ['slug' => $f->slug, 'name' => $f->name])->values()->all();
        } catch (\Throwable $e) {
            $pbFlows = [];
        }
        try {
            $varClass = config('ai-page-builder.models.variable', \Andre\AiPageBuilder\Models\Variable::class);
            $pbVariables = $varClass::query()->orderBy('key')->get()
                ->map(fn ($v) => ['key' => $v->key, 'name' => $v->key, 'type' => $v->type])->values()->all();
        } catch (\Throwable $e) {
            $pbVariables = [];
        }
        try {
            // Email-template pages (kind=email) feed the Send Email node's
            // template dropdown — its html becomes the email body.
            $pageClass = config('ai-page-builder.models.page', \Andre\AiPageBuilder\Models\Page::class);
            $pbEmailTemplates = $pageClass::query()->where('kind', 'email')->orderBy('title')->get()
                ->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->title])->values()->all();
        } catch (\Throwable $e) {
            $pbEmailTemplates = [];
        }
        try {
            // Reusable (encrypted) credentials the HTTP Request node can apply
            // by key — so flows authenticate without inlining tokens.
            $credClass = config('ai-page-builder.models.credential', \Andre\AiPageBuilder\Models\Credential::class);
            $pbCredentials = $credClass::query()->orderBy('name')->get()
                ->map(fn ($c) => ['key' => $c->key, 'name' => $c->name])->values()->all();
        } catch (\Throwable $e) {
            $pbCredentials = [];
        }
        try {
            // AI Gateway integrations feed the AI Invoke node's slug dropdown.
            // The gateway package is OPTIONAL — only query when it's installed.
            if (class_exists(\Andre\AiGateway\Models\AiIntegration::class)) {
                $pbIntegrations = \Andre\AiGateway\Models\AiIntegration::query()
                    ->where('is_active', true)->orderBy('name')->get()
                    ->map(fn ($i) => ['slug' => $i->slug, 'name' => $i->name])->values()->all();
            } else {
                $pbIntegrations = [];
            }
        } catch (\Throwable $e) {
            $pbIntegrations = [];
        }
    @endphp
    <script>
        window.__pbFlowFunctions = @js($pbFlowFunctions);
        window.__pbFlows = @js($pbFlows);
        window.__pbCollections = @js($pbCollections);
        window.__pbVariables = @js($pbVariables);
        window.__pbEmailTemplates = @js($pbEmailTemplates);
        window.__pbCredentials = @js($pbCredentials);
        window.__pbIntegrations = @js($pbIntegrations);
    </script>
    <style>
        /* ── Drawflow canvas wrapper (dark) ── */
        .ai-pb-flow-wrap {
            position: relative;
            background: #0f172a;
            border: 1px solid rgb(255 255 255 / 0.1);
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .ai-pb-flow-wrap .drawflow {
            width: 100%;
            height: 100%;
            background-color: #0f172a;
            background-image: radial-gradient(#1e293b 1px, transparent 1px);
            background-size: 20px 20px;
        }
        /* ── Fullscreen ── */
        .ai-pb-flow-wrap.ai-pb-flow-fullscreen {
            position: fixed;
            inset: 0;
            z-index: 9999;
            border-radius: 0;
            border: 0;
        }
        /* ── Node card styles (dark) ── */
        .ai-pb-node {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            min-width: 210px;
            font-size: 0.75rem;
            font-family: ui-sans-serif, system-ui, sans-serif;
            color: #e2e8f0;
            box-shadow: 0 4px 12px rgb(0 0 0 / 0.35);
        }
        .ai-pb-node-title {
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .ai-pb-node input,
        .ai-pb-node select {
            width: 100%;
            border: 1px solid #334155;
            border-radius: 0.25rem;
            padding: 0.2rem 0.35rem;
            font-size: 0.72rem;
            margin-bottom: 0.25rem;
            outline: none;
            background: #0f172a;
            color: #e2e8f0;
        }
        .ai-pb-node input:focus,
        .ai-pb-node select:focus {
            border-color: #6366f1;
            background: #1e293b;
        }
        .ai-pb-node textarea {
            width: 100%;
            border: 1px solid #334155;
            border-radius: 0.25rem;
            padding: 0.2rem 0.35rem;
            font-size: 0.7rem;
            font-family: ui-monospace, monospace;
            resize: vertical;
            min-height: 3rem;
            margin-bottom: 0.25rem;
            outline: none;
            background: #0f172a;
            color: #e2e8f0;
        }
        .ai-pb-node textarea:focus {
            border-color: #6366f1;
            background: #1e293b;
        }
        .ai-pb-node label.ai-pb-node-label {
            display: block;
            font-size: 0.67rem;
            color: #64748b;
            margin-bottom: 0.1rem;
        }
        /* ── Result actions low-code builder ── */
        .ai-pb-action-row {
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 0.5rem;
            padding: 0.45rem 0.5rem 0.55rem;
            margin: 0.35rem 0;
            background: rgba(30, 41, 59, 0.35);
        }
        .ai-pb-action-head {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-bottom: 0.2rem;
        }
        .ai-pb-action-head .ai-pb-action-type { flex: 1 1 auto; margin: 0; }
        .ai-pb-action-del {
            flex: 0 0 auto;
            border: 0;
            background: rgba(248, 113, 113, 0.15);
            color: #fca5a5;
            border-radius: 0.35rem;
            width: 1.4rem;
            height: 1.4rem;
            line-height: 1;
            font-size: 1rem;
            cursor: pointer;
        }
        .ai-pb-action-del:hover { background: rgba(248, 113, 113, 0.3); }
        .ai-pb-action-add {
            display: block;
            width: 100%;
            margin-top: 0.3rem;
            border: 1px dashed rgba(129, 140, 248, 0.6);
            background: rgba(99, 102, 241, 0.12);
            color: #c7d2fe;
            border-radius: 0.4rem;
            padding: 0.35rem;
            font-size: 0.75rem;
            cursor: pointer;
        }
        .ai-pb-action-add:hover { background: rgba(99, 102, 241, 0.22); }
        /* ── Transaction / loop step list ── */
        .ai-pb-step {
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-left: 3px solid #6366f1;
            border-radius: 0.45rem;
            padding: 0.35rem 0.45rem 0.5rem;
            margin: 0.3rem 0;
            background: rgba(30, 41, 59, 0.4);
        }
        .ai-pb-step-head { display: flex; align-items: center; gap: 0.3rem; margin-bottom: 0.25rem; }
        .ai-pb-step-num {
            flex: 0 0 auto; width: 1.15rem; height: 1.15rem; line-height: 1.15rem;
            text-align: center; border-radius: 999px; background: #6366f1; color: #eef2ff;
            font-size: 0.62rem; font-weight: 700;
        }
        .ai-pb-step-kind { flex: 0 0 auto; width: auto; margin: 0 !important; }
        .ai-pb-step-btn {
            flex: 0 0 auto; border: 0; background: rgba(148, 163, 184, 0.18); color: #cbd5e1;
            border-radius: 0.3rem; width: 1.3rem; height: 1.3rem; line-height: 1; cursor: pointer; font-size: 0.8rem;
        }
        .ai-pb-step-btn:hover { background: rgba(148, 163, 184, 0.34); }
        .ai-pb-step-del { background: rgba(248, 113, 113, 0.15); color: #fca5a5; }
        .ai-pb-step-nested { margin: 0.25rem 0 0.25rem 0.5rem; padding-left: 0.4rem; border-left: 1px dashed rgba(148,163,184,0.3); }
        .ai-pb-step-raw { font-size: 0.68rem; color: #94a3b8; font-style: italic; padding: 0.15rem 0; }
        /* ── Entry (START) node badge ── */
        .drawflow .drawflow-node.pb-entry { box-shadow: 0 0 0 2px #22c55e, 0 6px 20px rgba(0,0,0,0.35); }
        .drawflow .drawflow-node.pb-entry::before {
            content: 'START';
            position: absolute;
            top: -10px;
            left: 12px;
            background: #22c55e;
            color: #052e16;
            font-size: 0.6rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            padding: 2px 7px;
            border-radius: 999px;
            z-index: 6;
            pointer-events: none;
        }
        /* Disabled palette tile (e.g. a second Trigger) */
        .ai-pb-tile--disabled { opacity: 0.4; cursor: not-allowed; }
        /* ── Toolbar / palette bar (dark) ── */
        .ai-pb-palette {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
            align-items: center;
            padding: 0.5rem 0.75rem;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            border-radius: 0.75rem 0.75rem 0 0;
        }
        .ai-pb-flow-fullscreen .ai-pb-palette {
            border-radius: 0;
        }
        .ai-pb-palette button {
            padding: 0.2rem 0.6rem;
            border-radius: 0.3rem;
            font-size: 0.72rem;
            font-weight: 500;
            border: 1px solid #334155;
            background: #0f172a;
            color: #cbd5e1;
            cursor: pointer;
            line-height: 1.4;
        }
        .ai-pb-palette button:hover {
            background: #3730a3;
            border-color: #6366f1;
            color: #e0e7ff;
        }
        .ai-pb-palette .ai-pb-palette-spacer { flex: 1 1 auto; }
        .ai-pb-palette select.ai-pb-state-picker {
            padding: 0.2rem 0.6rem; border-radius: 0.3rem; font-size: 0.72rem; font-weight: 500;
            border: 1px solid #2dd4bf66; background: #0f172a; color: #5eead4; cursor: pointer; line-height: 1.4; max-width: 200px;
        }
        .ai-pb-palette button.ai-pb-fullscreen-btn {
            background: transparent;
            border-color: #475569;
        }
        .ai-pb-palette button.ai-pb-add-node-btn {
            background: #4f46e5;
            border-color: #6366f1;
            color: #eef2ff;
            font-weight: 600;
        }
        .ai-pb-palette button.ai-pb-add-node-btn:hover {
            background: #4338ca;
            border-color: #818cf8;
        }
        /* ── Canvas area (positioning context for the slide-over drawer) ── */
        .ai-pb-canvas-area {
            position: relative;
            width: 100%;
            overflow: hidden;
        }
        /* ── Node drawer (GrapesJS block-manager style) ── */
        .ai-pb-drawer-backdrop {
            position: absolute;
            inset: 0;
            background: rgb(2 6 23 / 0.45);
            z-index: 20;
        }
        .ai-pb-drawer {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            width: 320px;
            max-width: 90%;
            background: #0f172a;
            border-right: 1px solid #334155;
            box-shadow: 8px 0 24px rgb(0 0 0 / 0.4);
            transform: translateX(-100%);
            transition: transform 0.18s ease;
            z-index: 21;
            display: flex;
            flex-direction: column;
        }
        .ai-pb-drawer.ai-pb-drawer--open {
            transform: translateX(0);
        }
        .ai-pb-drawer-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.65rem 0.85rem;
            border-bottom: 1px solid #1e293b;
        }
        .ai-pb-drawer-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .ai-pb-drawer-close {
            background: transparent;
            border: 0;
            color: #94a3b8;
            font-size: 0.95rem;
            cursor: pointer;
            line-height: 1;
            padding: 0.15rem 0.35rem;
            border-radius: 0.3rem;
        }
        .ai-pb-drawer-close:hover { color: #e2e8f0; background: #1e293b; }
        .ai-pb-drawer-search { padding: 0.65rem 0.85rem; border-bottom: 1px solid #1e293b; }
        .ai-pb-drawer-search input {
            width: 100%;
            box-sizing: border-box;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 0.4rem;
            padding: 0.4rem 0.6rem;
            font-size: 0.78rem;
            color: #e2e8f0;
            outline: none;
        }
        .ai-pb-drawer-search input:focus { border-color: #6366f1; }
        .ai-pb-drawer-search input::placeholder { color: #64748b; }
        .ai-pb-drawer-body { flex: 1 1 auto; overflow-y: auto; padding: 0.5rem 0.85rem 1rem; }
        .ai-pb-drawer-group { margin-top: 0.75rem; }
        .ai-pb-drawer-group:first-child { margin-top: 0.25rem; }
        .ai-pb-drawer-group-title {
            font-size: 0.66rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            margin-bottom: 0.4rem;
        }
        .ai-pb-drawer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.45rem;
        }
        .ai-pb-tile {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.2rem;
            text-align: left;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 0.5rem;
            padding: 0.5rem 0.55rem;
            cursor: pointer;
            color: #e2e8f0;
            line-height: 1.25;
        }
        .ai-pb-tile:hover {
            background: #312e81;
            border-color: #6366f1;
        }
        .ai-pb-tile-icon { font-size: 1.05rem; }
        .ai-pb-tile-label { font-size: 0.74rem; font-weight: 600; }
        .ai-pb-tile-desc {
            font-size: 0.64rem;
            color: #94a3b8;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .ai-pb-tile:hover .ai-pb-tile-desc { color: #c7d2fe; }
        .ai-pb-drawer-empty {
            padding: 1.5rem 0.5rem;
            text-align: center;
            font-size: 0.74rem;
            color: #64748b;
        }
        /* node type accent colours via title bar */
        .ai-pb-node[data-node-type="trigger"] .ai-pb-node-title { color: #22c55e; }
        .ai-pb-node[data-node-type="ai_invoke"] .ai-pb-node-title { color: #a78bfa; }
        .ai-pb-node[data-node-type="http_request"] .ai-pb-node-title { color: #38bdf8; }
        .ai-pb-node[data-node-type="function"] .ai-pb-node-title { color: #f59e0b; }
        .ai-pb-node[data-node-type="record"] .ai-pb-node-title { color: #2dd4bf; }
        .ai-pb-node[data-node-type="set_variable"] .ai-pb-node-title { color: #e879f9; }
        .ai-pb-node[data-node-type="send_email"] .ai-pb-node-title { color: #34d399; }
        .ai-pb-node[data-node-type="condition"] .ai-pb-node-title { color: #fbbf24; }
        .ai-pb-node[data-node-type="result"] .ai-pb-node-title { color: #f472b6; }
        /* ── Neutralise Drawflow's default node chrome so only our card shows ── */
        .ai-pb-flow-wrap .drawflow .drawflow-node {
            background: transparent;
            border: 0;
            padding: 0;
            box-shadow: none;
            width: auto;
            min-width: 230px;
            border-radius: 0.5rem;
        }
        .ai-pb-flow-wrap .drawflow .drawflow-node.selected {
            box-shadow: 0 0 0 2px #6366f1;
        }
        .ai-pb-flow-wrap .drawflow .drawflow-node .ai-pb-node {
            width: 100%;
            min-width: 230px;
            box-sizing: border-box;
        }
        /* keep the connection ports visible against the dark card */
        .ai-pb-flow-wrap .drawflow .drawflow-node .input,
        .ai-pb-flow-wrap .drawflow .drawflow-node .output {
            background: #1e293b;
            border: 2px solid #6366f1;
        }
        .ai-pb-flow-wrap .drawflow .connection .main-path {
            stroke: #64748b;
        }
    </style>
    {{-- The script below is wrapped so Blade does not parse literal braces in JS. --}}
    @verbatim
    <script>
        (function () {
            /** Options HTML from an injected [{slug|key, name}] list. */
            function optionList(items, valueKey, placeholder) {
                var list = items || [];
                var html = '<option value="">' + placeholder + '</option>';
                for (var i = 0; i < list.length; i++) {
                    var v = list[i][valueKey];
                    var label = list[i].name || v;
                    html += '<option value="' + v + '">' + label + ' (' + v + ')</option>';
                }
                return html;
            }

            /**
             * Show only the Collection-node fields relevant to the selected
             * operation. Each conditional group carries data-ops="op1,op2".
             * Called inline on change and re-applied after add / import.
             */
            /**
             * Auto-set a Set State node's data type from the selected State's
             * declared type (window.__pbVariables), so the value is cast
             * correctly and the author never picks a mismatched type.
             */
            window.__pbSetStateType = function (sel) {
                var node = sel && sel.closest ? sel.closest('.ai-pb-node') : null;
                if (! node) { return; }
                var key = sel.value;
                var list = window.__pbVariables || [];
                var type = '';
                for (var i = 0; i < list.length; i++) {
                    if (list[i].key === key) { type = list[i].type || 'string'; break; }
                }
                var field = node.querySelector('[df-type]');
                if (field) {
                    field.value = type;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                }
                var label = node.querySelector('.ai-pb-statetype');
                if (label) { label.textContent = type ? ('data type: ' + type + ' (auto)') : ''; }
            };

            window.__pbRecordOp = function (sel) {
                var node = sel && sel.closest ? sel.closest('.ai-pb-node') : null;
                if (! node) { return; }
                var op = sel.value;
                var groups = node.querySelectorAll('[data-ops]');
                for (var i = 0; i < groups.length; i++) {
                    var ops = (groups[i].getAttribute('data-ops') || '').split(',');
                    groups[i].style.display = (ops.indexOf(op) === -1) ? 'none' : '';
                }
            };

            // ── Result actions low-code builder ──────────────────────────────
            // Available result-action types + their fields. Prefer the catalog the
            // engine ships (window.__pbActionCatalog, from the field view / nodeDefs)
            // so it's the single source of truth; fall back to this inline copy —
            // both mirror applyAction() in render/flow-runtime.blade.php.
            var PB_ACTION_TYPES = (window.__pbActionCatalog) || {
                notify:      { label: 'Notify (toast)', fields: [ { key: 'message', label: 'Message', type: 'text' }, { key: 'level', label: 'Level', type: 'select', options: { success: 'success', error: 'error', info: 'info', warning: 'warning' } } ] },
                alert:       { label: 'Alert dialog',   fields: [ { key: 'title', label: 'Title', type: 'string' }, { key: 'message', label: 'Message', type: 'text' } ] },
                modal:       { label: 'Modal',          fields: [ { key: 'target', label: 'Target selector', type: 'string' }, { key: 'action', label: 'Action', type: 'select', options: { open: 'open', close: 'close' } }, { key: 'html', label: 'HTML (on open)', type: 'text', showIf: { action: ['open'] } } ] },
                redirect:    { label: 'Redirect',       fields: [ { key: 'url', label: 'URL', type: 'string' } ] },
                setState:    { label: 'Set state',      fields: [ { key: 'key', label: 'State key', type: 'string' }, { key: 'value', label: 'Value (expression)', type: 'string' } ] },
                setHtml:     { label: 'Set HTML',       fields: [ { key: 'target', label: 'Target selector', type: 'string' }, { key: 'html', label: 'HTML', type: 'text' } ] },
                setText:     { label: 'Set text',       fields: [ { key: 'target', label: 'Target selector', type: 'string' }, { key: 'text', label: 'Text', type: 'text' } ] },
                addClass:    { label: 'Add class',      fields: [ { key: 'target', label: 'Target selector', type: 'string' }, { key: 'class', label: 'Class', type: 'string' } ] },
                removeClass: { label: 'Remove class',   fields: [ { key: 'target', label: 'Target selector', type: 'string' }, { key: 'class', label: 'Class', type: 'string' } ] },
                logout:      { label: 'Log out',        fields: [ { key: 'url', label: 'Redirect URL (optional)', type: 'string' } ] }
            };

            // Turn a `result` node's hidden JSON <textarea df-actions> into a low-code
            // list of typed actions (a type dropdown → the fields for that type). The
            // hidden field stays the source of truth (load/save unchanged); the builder
            // just reads/writes it and dispatches input so Drawflow re-syncs.
            window.__pbResultActions = function (nodeEl) {
                if (! nodeEl || nodeEl.__pbActionsInit) { return; }
                var hidden = nodeEl.querySelector('textarea[df-actions]');
                var mount = nodeEl.querySelector('[data-pb-actions-mount]');
                if (! hidden || ! mount) { return; }
                nodeEl.__pbActionsInit = true;

                var actions;
                try { actions = JSON.parse(hidden.value || '[]'); } catch (e) { actions = []; }
                if (! Array.isArray(actions)) { actions = []; }

                function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
                function commit() {
                    hidden.value = JSON.stringify(actions);
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                }
                function optionsHtml(map) { return Object.keys(map || {}).map(function (k) { return '<option value="' + esc(k) + '">' + esc(map[k]) + '</option>'; }).join(''); }

                function render() {
                    mount.innerHTML = '';
                    actions.forEach(function (act, idx) {
                        var spec = PB_ACTION_TYPES[act.type] || { fields: [] };
                        var row = document.createElement('div');
                        row.className = 'ai-pb-action-row';
                        var typeSel = '<select class="ai-pb-action-type">' + Object.keys(PB_ACTION_TYPES).map(function (t) { return '<option value="' + t + '"' + (t === act.type ? ' selected' : '') + '>' + esc(PB_ACTION_TYPES[t].label) + '</option>'; }).join('') + '</select>';
                        var fieldsHtml = spec.fields.map(function (f) {
                            if (f.showIf && ! Object.keys(f.showIf).every(function (k) { return (f.showIf[k] || []).indexOf(act[k]) !== -1; })) { return ''; }
                            var val = act[f.key];
                            if (f.type === 'select') { return '<label class="ai-pb-node-label">' + esc(f.label) + '</label><select data-k="' + f.key + '">' + optionsHtml(f.options) + '</select>'; }
                            if (f.type === 'text') { return '<label class="ai-pb-node-label">' + esc(f.label) + '</label><textarea data-k="' + f.key + '">' + esc(val) + '</textarea>'; }
                            return '<label class="ai-pb-node-label">' + esc(f.label) + '</label><input type="text" data-k="' + f.key + '" value="' + esc(val) + '" />';
                        }).join('');
                        row.innerHTML = '<div class="ai-pb-action-head">' + typeSel + '<button type="button" class="ai-pb-action-del" title="Remove">&times;</button></div>' + fieldsHtml;
                        row.querySelector('.ai-pb-action-type').addEventListener('change', function (e) { actions[idx] = { type: e.target.value }; commit(); render(); });
                        row.querySelector('.ai-pb-action-del').addEventListener('click', function () { actions.splice(idx, 1); commit(); render(); });
                        spec.fields.forEach(function (f) {
                            var ctl = row.querySelector('[data-k="' + f.key + '"]');
                            if (! ctl) { return; }
                            if (f.type === 'select' && act[f.key] != null) { ctl.value = act[f.key]; }
                            ctl.addEventListener(f.type === 'select' ? 'change' : 'input', function () {
                                actions[idx][f.key] = ctl.value;
                                commit();
                                if (f.type === 'select') { render(); } // a select may gate a showIf field
                            });
                        });
                        mount.appendChild(row);
                    });
                    var add = document.createElement('button');
                    add.type = 'button';
                    add.className = 'ai-pb-action-add';
                    add.textContent = '+ Add action';
                    add.addEventListener('click', function () { actions.push({ type: 'notify', message: '', level: 'success' }); commit(); render(); });
                    mount.appendChild(add);
                }
                render();
            };

            // ── Step-list builder for transaction / loop bodies ──────────────
            // A body is a linear {start, nodes} sub-flow. Instead of raw JSON, edit it
            // as an ordered, sortable list of STEPS. A step is a Function (reusable
            // expression), a Flow (reusable sub-flow, current flow excluded), a Loop
            // (for-each with its own nested step list), or — to preserve anything the
            // list UI doesn't model yet — a raw node kept verbatim. The list compiles
            // straight back to {start, nodes} (linear next-chaining), so the runtime is
            // unchanged.
            function pbDecompileBody(body) {
                var steps = [];
                if (! body || ! body.nodes) { return steps; }
                var nodes = body.nodes, id = body.start, guard = 0;
                while (id && nodes[id] && guard++ < 500) {
                    var n = nodes[id], t = n.type || '', c = n.config || {};
                    if (t === 'function') { steps.push({ kind: 'function', ref: c.function || '', args: c.args || {}, output: c.output || '' }); }
                    else if (t === 'call_flow') { steps.push({ kind: 'flow', ref: c.flow || '', output: c.output || '' }); }
                    else if (t === 'loop') { steps.push({ kind: 'loop', over: c.over || '', item_var: c.item_var || 'item', index_var: c.index_var || '', steps: pbDecompileBody(c.body || {}) }); }
                    else { steps.push({ kind: 'node', type: t, config: c }); }
                    var nx = n.next;
                    id = Array.isArray(nx) ? nx[0] : nx;
                }
                return steps;
            }
            function pbCompileSteps(steps) {
                var nodes = {}, start = null, prev = null;
                (steps || []).forEach(function (s, i) {
                    var id = 's' + i, node;
                    if (s.kind === 'function') { node = { type: 'function', config: { function: s.ref || '', args: s.args || {}, output: s.output || '' } }; }
                    else if (s.kind === 'flow') { node = { type: 'call_flow', config: { flow: s.ref || '', output: s.output || '' } }; }
                    else if (s.kind === 'loop') { node = { type: 'loop', config: { over: s.over || '', item_var: s.item_var || 'item', index_var: s.index_var || '', body: pbCompileSteps(s.steps || []) } }; }
                    else { node = { type: s.type || 'record', config: s.config || {} }; }
                    if (start === null) { start = id; }
                    if (prev !== null) { nodes[prev].next = [id]; }
                    nodes[id] = node;
                    prev = id;
                });
                return { start: start, nodes: nodes };
            }
            function pbFlowOptions() {
                var flows = (window.__pbFlows || []).filter(function (f) { return f.slug !== window.__pbCurrentFlowSlug; });
                return flows.map(function (f) { return '<option value="' + f.slug + '">' + (f.name || f.slug) + '</option>'; }).join('');
            }
            function pbFnOptions() {
                return (window.__pbFlowFunctions || []).map(function (f) { return '<option value="' + f.slug + '">' + (f.name || f.slug) + '</option>'; }).join('');
            }

            // Render an editable step list into `mount`, persisting to onChange(steps).
            function pbRenderStepList(mount, steps, onChange) {
                function commit() { onChange(steps); render(); }
                function render() {
                    mount.innerHTML = '';
                    steps.forEach(function (step, idx) {
                        var card = document.createElement('div');
                        card.className = 'ai-pb-step';
                        var head = '<div class="ai-pb-step-head">'
                            + '<span class="ai-pb-step-num">' + (idx + 1) + '</span>'
                            + '<select class="ai-pb-step-kind">'
                            +   '<option value="function"' + (step.kind === 'function' ? ' selected' : '') + '>ƒ Function</option>'
                            +   '<option value="flow"' + (step.kind === 'flow' ? ' selected' : '') + '>⚙ Flow</option>'
                            +   '<option value="loop"' + (step.kind === 'loop' ? ' selected' : '') + '>🔁 Loop</option>'
                            +   (step.kind === 'node' ? '<option value="node" selected>▪ ' + (step.type || 'node') + '</option>' : '')
                            + '</select>'
                            + '<span style="flex:1 1 auto"></span>'
                            + '<button type="button" class="ai-pb-step-btn" data-up title="Move up">↑</button>'
                            + '<button type="button" class="ai-pb-step-btn" data-down title="Move down">↓</button>'
                            + '<button type="button" class="ai-pb-step-btn ai-pb-step-del" data-del title="Remove">×</button>'
                            + '</div>';
                        var bodyHtml = '';
                        if (step.kind === 'function') { bodyHtml = '<label class="ai-pb-node-label">Function</label><select data-ref>' + pbFnOptions() + '</select>'; }
                        else if (step.kind === 'flow') { bodyHtml = '<label class="ai-pb-node-label">Flow (runs with shared context)</label><select data-ref>' + pbFlowOptions() + '</select>'; }
                        else if (step.kind === 'loop') {
                            bodyHtml = '<label class="ai-pb-node-label">For each item in</label><input type="text" data-over placeholder="input.cart_items" value="' + (step.over || '') + '" />'
                                + '<label class="ai-pb-node-label">Item variable</label><input type="text" data-itemvar value="' + (step.item_var || 'item') + '" />'
                                + '<div class="ai-pb-step-nested" data-nested></div>'
                                + '<button type="button" class="ai-pb-action-add" data-addnested>+ Add step (per item)</button>';
                        } else { bodyHtml = '<div class="ai-pb-step-raw">Advanced node — kept as-is</div>'; }
                        card.innerHTML = head + bodyHtml;

                        card.querySelector('.ai-pb-step-kind').addEventListener('change', function (e) {
                            var k = e.target.value;
                            if (k === 'function') { steps[idx] = { kind: 'function', ref: '', args: {}, output: '' }; }
                            else if (k === 'flow') { steps[idx] = { kind: 'flow', ref: '', output: '' }; }
                            else if (k === 'loop') { steps[idx] = { kind: 'loop', over: '', item_var: 'item', index_var: '', steps: [] }; }
                            commit();
                        });
                        card.querySelector('[data-up]').addEventListener('click', function () { if (idx > 0) { var t = steps[idx - 1]; steps[idx - 1] = steps[idx]; steps[idx] = t; commit(); } });
                        card.querySelector('[data-down]').addEventListener('click', function () { if (idx < steps.length - 1) { var t = steps[idx + 1]; steps[idx + 1] = steps[idx]; steps[idx] = t; commit(); } });
                        card.querySelector('[data-del]').addEventListener('click', function () { steps.splice(idx, 1); commit(); });

                        var refSel = card.querySelector('[data-ref]');
                        if (refSel) { if (step.ref) { refSel.value = step.ref; } refSel.addEventListener('change', function () { step.ref = refSel.value; onChange(steps); }); }
                        var over = card.querySelector('[data-over]'); if (over) { over.addEventListener('input', function () { step.over = over.value; onChange(steps); }); }
                        var iv = card.querySelector('[data-itemvar]'); if (iv) { iv.addEventListener('input', function () { step.item_var = iv.value; onChange(steps); }); }
                        var nested = card.querySelector('[data-nested]');
                        if (nested) {
                            if (! Array.isArray(step.steps)) { step.steps = []; }
                            pbRenderStepList(nested, step.steps, function () { onChange(steps); });
                            card.querySelector('[data-addnested]').addEventListener('click', function () { step.steps.push({ kind: 'function', ref: '', args: {}, output: '' }); commit(); });
                        }
                        mount.appendChild(card);
                    });
                    var add = document.createElement('button');
                    add.type = 'button'; add.className = 'ai-pb-action-add'; add.textContent = '+ Add step';
                    add.addEventListener('click', function () { steps.push({ kind: 'function', ref: '', args: {}, output: '' }); commit(); });
                    mount.appendChild(add);
                }
                render();
            }

            // Wire a transaction/loop node's hidden df-body <textarea> to a step-list UI.
            window.__pbStepBody = function (nodeEl) {
                if (! nodeEl || nodeEl.__pbStepInit) { return; }
                var hidden = nodeEl.querySelector('textarea[df-body]');
                var mount = nodeEl.querySelector('[data-pb-steps-mount]');
                if (! hidden || ! mount) { return; }
                nodeEl.__pbStepInit = true;
                var body;
                try { body = JSON.parse(hidden.value || '{}'); } catch (e) { body = {}; }
                var steps = pbDecompileBody(body);
                pbRenderStepList(mount, steps, function (updated) {
                    hidden.value = JSON.stringify(pbCompileSteps(updated));
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                });
            };

            /** Build the inner HTML for a Drawflow node by type. */
            function nodeHtml(type) {
                switch (type) {
                    case 'trigger':
                        return '<div class="ai-pb-node" data-node-type="trigger">'
                            + '<div class="ai-pb-node-title">&#9654; Trigger</div>'
                            + '<span style="font-size:0.7rem;color:#94a3b8;">Flow entry point</span>'
                            + '</div>';

                    case 'ai_invoke':
                        return '<div class="ai-pb-node" data-node-type="ai_invoke">'
                            + '<div class="ai-pb-node-title">&#10024; AI Invoke</div>'
                            + '<label class="ai-pb-node-label">Integration slug</label>'
                            + '<select df-integration>' + optionList(window.__pbIntegrations, 'slug', '— select integration —') + '</select>'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. ai_result" />'
                            + '<label class="ai-pb-node-label">Args (JSON)</label>'
                            + '<textarea df-args placeholder=\'{"prompt":"Hello"}\'></textarea>'
                            + '</div>';

                    case 'http_request':
                        return '<div class="ai-pb-node" data-node-type="http_request">'
                            + '<div class="ai-pb-node-title">&#127760; HTTP Request</div>'
                            + '<label class="ai-pb-node-label">Method</label>'
                            + '<select df-method>'
                            + '<option value="GET">GET</option>'
                            + '<option value="POST">POST</option>'
                            + '<option value="PUT">PUT</option>'
                            + '<option value="PATCH">PATCH</option>'
                            + '<option value="DELETE">DELETE</option>'
                            + '</select>'
                            + '<label class="ai-pb-node-label">URL</label>'
                            + '<input type="text" df-url placeholder="https://api.example.com/v1/{{vars.id}}" />'
                            + '<label class="ai-pb-node-label">Credential (auth)</label>'
                            + '<select df-credential>' + optionList(window.__pbCredentials, 'key', '— none —') + '</select>'
                            + '<label class="ai-pb-node-label">Headers (JSON)</label>'
                            + '<textarea df-headers placeholder=\'{"Authorization":"Bearer {{vars.token}}"}\'></textarea>'
                            + '<label class="ai-pb-node-label">Params / body (JSON)</label>'
                            + '<textarea df-body placeholder=\'{"q":"{{input.query}}"}\'></textarea>'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. http_result" />'
                            + '</div>';

                    case 'function':
                        return '<div class="ai-pb-node" data-node-type="function">'
                            + '<div class="ai-pb-node-title">&#402; Function</div>'
                            + '<label class="ai-pb-node-label">Function</label>'
                            + '<select df-function>' + optionList(window.__pbFlowFunctions, 'slug', '— select function —') + '</select>'
                            + '<label class="ai-pb-node-label">Args (JSON)</label>'
                            + '<textarea df-args placeholder=\'{"price":"{{vars.amount}}"}\'></textarea>'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. result" />'
                            + '</div>';

                    case 'send_email':
                        return '<div class="ai-pb-node" data-node-type="send_email">'
                            + '<div class="ai-pb-node-title">&#9993; Send Email</div>'
                            + '<label class="ai-pb-node-label">To</label>'
                            + '<input type="text" df-to placeholder="{{input.email}}" />'
                            + '<label class="ai-pb-node-label">Subject</label>'
                            + '<input type="text" df-subject placeholder="Welcome {{input.name}}" />'
                            + '<label class="ai-pb-node-label">Template (email page)</label>'
                            + '<select df-template>' + optionList(window.__pbEmailTemplates, 'slug', '— inline HTML below —') + '</select>'
                            + '<label class="ai-pb-node-label">Inline HTML (used if no template)</label>'
                            + '<textarea df-body placeholder=\'<p>Hi {{input.name}}</p>\'></textarea>'
                            + '<label class="ai-pb-node-label">Cc / Bcc / Reply-To (optional)</label>'
                            + '<input type="text" df-cc placeholder="cc@example.com" />'
                            + '<input type="text" df-bcc placeholder="bcc@example.com" />'
                            + '<input type="text" df-reply_to placeholder="reply@example.com" />'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. email" />'
                            + '</div>';

                    case 'record':
                        // Fields wrapped in [data-ops] are shown only for the
                        // matching operation(s) — toggled by __pbRecordOp on
                        // change and re-applied after add / import.
                        return '<div class="ai-pb-node" data-node-type="record">'
                            + '<div class="ai-pb-node-title">&#128451; Collection</div>'
                            + '<label class="ai-pb-node-label">Collection</label>'
                            + '<select df-model>' + optionList(window.__pbCollections, 'key', '— select collection —') + '</select>'
                            + '<label class="ai-pb-node-label">Operation</label>'
                            + '<select df-operation onchange="window.__pbRecordOp && window.__pbRecordOp(this)">'
                            + '<option value="list">List / search</option>'
                            + '<option value="get">Get by id</option>'
                            + '<option value="create">Create</option>'
                            + '<option value="update">Update</option>'
                            + '<option value="delete">Delete</option>'
                            + '</select>'
                            + '<div data-ops="get,update,delete">'
                            + '<label class="ai-pb-node-label">Record id</label>'
                            + '<input type="text" df-recid placeholder="{{input.id}}" />'
                            + '</div>'
                            + '<div data-ops="list">'
                            + '<label class="ai-pb-node-label">Filter (JSON)</label>'
                            + '<textarea df-filter placeholder=\'{"status":{"eq":"open"}}\'></textarea>'
                            + '<label class="ai-pb-node-label">Search / sort</label>'
                            + '<input type="text" df-search placeholder="search term" />'
                            + '<input type="text" df-sort placeholder="-created_at" />'
                            + '</div>'
                            + '<div data-ops="create,update">'
                            + '<label class="ai-pb-node-label">Data (JSON)</label>'
                            + '<textarea df-data placeholder=\'{"name":"{{input.name}}"}\'></textarea>'
                            + '</div>'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. records" />'
                            + '</div>';

                    case 'set_variable':
                        // The data type is taken automatically from the selected
                        // State's definition (df-type is set by __pbSetStateType on
                        // change), so the value is always cast to the right type.
                        return '<div class="ai-pb-node" data-node-type="set_variable">'
                            + '<div class="ai-pb-node-title">&#128190; Set State</div>'
                            + '<label class="ai-pb-node-label">State</label>'
                            + '<select df-key onchange="window.__pbSetStateType && window.__pbSetStateType(this)">' + optionList(window.__pbVariables, 'key', '— select state —') + '</select>'
                            + '<div class="ai-pb-statetype" style="font-size:0.66rem;color:#5eead4;margin:0 0 0.3rem;min-height:0.8rem;"></div>'
                            + '<input type="hidden" df-type />'
                            + '<label class="ai-pb-node-label">Value</label>'
                            + '<input type="text" df-value placeholder="{{vars.result}}" />'
                            + '<label class="ai-pb-node-label">Output variable (optional)</label>'
                            + '<input type="text" df-output placeholder="e.g. saved" />'
                            + '</div>';

                    case 'condition':
                        return '<div class="ai-pb-node" data-node-type="condition">'
                            + '<div class="ai-pb-node-title">&#10067; Condition</div>'
                            + '<label class="ai-pb-node-label">Left operand</label>'
                            + '<input type="text" df-left placeholder="{{variable}}" />'
                            + '<label class="ai-pb-node-label">Operator</label>'
                            + '<select df-op>'
                            + '<option value="equals">equals</option>'
                            + '<option value="not_equals">not equals</option>'
                            + '<option value="contains">contains</option>'
                            + '<option value="gt">greater than</option>'
                            + '<option value="lt">less than</option>'
                            + '<option value="empty">is empty</option>'
                            + '<option value="not_empty">is not empty</option>'
                            + '</select>'
                            + '<label class="ai-pb-node-label">Right operand</label>'
                            + '<input type="text" df-right placeholder="value" />'
                            + '<div style="display:flex;gap:1rem;font-size:0.67rem;margin-top:0.2rem;">'
                            + '<span style="color:#22c55e;">&#9679; output_1 = true</span>'
                            + '<span style="color:#f87171;">&#9679; output_2 = false</span>'
                            + '</div>'
                            + '</div>';

                    case 'result':
                        return '<div class="ai-pb-node" data-node-type="result">'
                            + '<div class="ai-pb-node-title">&#9632; Result</div>'
                            + '<span style="font-size:0.7rem;color:#94a3b8;">What happens when the flow reaches here — add one or more actions.</span>'
                            + '<div class="ai-pb-actions" data-pb-actions-mount></div>'
                            + '<textarea df-actions style="display:none"></textarea>'
                            + '</div>';

                    case 'transaction':
                        return '<div class="ai-pb-node" data-node-type="transaction">'
                            + '<div class="ai-pb-node-title">&#128274; Transaction</div>'
                            + '<span style="font-size:0.7rem;color:#94a3b8;">Runs these steps atomically — all commit together, or all roll back on any error.</span>'
                            + '<label class="ai-pb-node-label">Steps (run in order)</label>'
                            + '<div class="ai-pb-steps" data-pb-steps-mount></div>'
                            + '<textarea df-body style="display:none"></textarea>'
                            + '<div style="display:flex;gap:1rem;font-size:0.67rem;margin-top:0.2rem;">'
                            + '<span style="color:#22c55e;">&#9679; output_1 = committed</span>'
                            + '<span style="color:#f87171;">&#9679; output_2 = rolled back</span>'
                            + '</div>'
                            + '</div>';

                    case 'loop':
                        return '<div class="ai-pb-node" data-node-type="loop">'
                            + '<div class="ai-pb-node-title">&#128260; Loop</div>'
                            + '<label class="ai-pb-node-label">For each item in</label>'
                            + '<input type="text" df-over placeholder="input.cart_items" />'
                            + '<label class="ai-pb-node-label">Item variable</label>'
                            + '<input type="text" df-item_var placeholder="item" />'
                            + '<label class="ai-pb-node-label">Index variable (optional)</label>'
                            + '<input type="text" df-index_var placeholder="index" />'
                            + '<label class="ai-pb-node-label">Steps (run once per item)</label>'
                            + '<div class="ai-pb-steps" data-pb-steps-mount></div>'
                            + '<textarea df-body style="display:none"></textarea>'
                            + '</div>';

                    default:
                        return '<div class="ai-pb-node"><span>' + type + '</span></div>';
                }
            }

            /**
             * Drawflow uses 1 output for most nodes, 2 outputs for condition.
             * Inputs: all nodes except trigger have 1 input.
             */
            function nodeOutputCount(type) {
                // condition → next_true / next_false; transaction → committed /
                // rolled_back. Everything else has a single "next" output.
                return (type === 'condition' || type === 'transaction') ? 2 : 1;
            }

            function nodeInputCount(type) {
                return type === 'trigger' ? 0 : 1;
            }

            function parseJson(text, fallback) {
                if (!text) { return fallback; }
                try { return JSON.parse(text); } catch (_) { return fallback; }
            }

            /** Stringify an object/array for a df-* field; '' when empty/blank. */
            function jsonStr(v) {
                if (v === undefined || v === null) { return ''; }
                if (typeof v === 'string') { return v; }
                try {
                    if (Array.isArray(v)) { return v.length ? JSON.stringify(v) : ''; }
                    if (typeof v === 'object') { return Object.keys(v).length ? JSON.stringify(v) : ''; }
                    return String(v);
                } catch (_) { return ''; }
            }

            /**
             * Inverse of toDefinition's per-type config block: map an engine
             * node config back to the df-* data Drawflow binds to the inputs.
             */
            function configToData(type, config) {
                config = config || {};
                switch (type) {
                    case 'ai_invoke':
                        return { integration: config.integration || '', output: config.output || '', args: jsonStr(config.args) };
                    case 'http_request':
                        return { method: config.method || 'GET', url: config.url || '', credential: config.credential || '', headers: jsonStr(config.headers), body: jsonStr(config.body), output: config.output || '' };
                    case 'function':
                        return { function: config.function || '', args: jsonStr(config.args), output: config.output || '' };
                    case 'send_email':
                        return { to: config.to || '', subject: config.subject || '', template: config.template || '', body: config.body || '', cc: config.cc || '', bcc: config.bcc || '', reply_to: config.reply_to || '', output: config.output || 'email' };
                    case 'record':
                        return { model: config.model || '', operation: config.operation || 'list', recid: config.id || '', filter: jsonStr(config.filter), data: jsonStr(config.data), search: config.search || '', sort: config.sort || '', output: config.output || 'records' };
                    case 'set_variable':
                        return { key: config.key || '', value: config.value || '', type: config.type || 'string', output: config.output || '' };
                    case 'condition':
                        return { left: config.left || '', op: config.op || 'equals', right: config.right || '' };
                    case 'result':
                        return { actions: jsonStr(config.actions) };
                    case 'transaction':
                        return { body: jsonStr(config.body) };
                    case 'loop':
                        return { over: config.over || '', item_var: config.item_var || 'item', index_var: config.index_var || '', body: jsonStr(config.body) };
                    default:
                        return {};
                }
            }

            /**
             * Build the canvas from an engine definition ({start, nodes}) when
             * there is no Drawflow _canvas snapshot — e.g. flows created by the
             * AI builder or written programmatically (they carry the node graph
             * but never the editor's positional export). Lays nodes out
             * left-to-right by BFS depth from the start node, then wires
             * next / next_true / next_false. Returns true if it built anything.
             */
            function reconstructFromDefinition(editor, def) {
                const nodes = (def && def.nodes) || {};
                const ids = Object.keys(nodes);
                if (! ids.length) { return false; }

                // BFS depth from the start so connected nodes fan left → right.
                const startId = (def.start && nodes[def.start]) ? def.start : ids[0];
                const depth = {};
                const queue = [[startId, 0]];
                depth[startId] = 0;
                while (queue.length) {
                    const item = queue.shift();
                    const n = nodes[item[0]] || {};
                    const outs = [].concat(n.next || [], n.next_true || [], n.next_false || [], n.committed || [], n.rolled_back || []);
                    outs.forEach((t) => {
                        t = String(t);
                        if (nodes[t] && depth[t] === undefined) { depth[t] = item[1] + 1; queue.push([t, item[1] + 1]); }
                    });
                }
                // Append any unreachable nodes after the deepest column.
                let maxD = 0;
                Object.keys(depth).forEach((k) => { if (depth[k] > maxD) { maxD = depth[k]; } });
                ids.forEach((id) => { if (depth[id] === undefined) { depth[id] = ++maxD; } });

                // Place each node (depth → column, order-within-depth → row).
                const rowOf = {};
                const idMap = {}; // definition id → drawflow id
                ids.forEach((defId) => {
                    const n = nodes[defId] || {};
                    const type = n.type || 'trigger';
                    const d = depth[defId] || 0;
                    const row = rowOf[d] || 0; rowOf[d] = row + 1;
                    // Generous spacing so tall nodes (result/record/transaction with
                    // JSON bodies run ~220-320px) never overlap. Columns are also
                    // offset vertically per-depth (stagger) so long edges stay readable.
                    const x = 80 + d * 360;
                    const y = 40 + row * 300 + (d % 2) * 40;
                    const data = configToData(type, n.config || {});
                    idMap[defId] = editor.addNode(type, nodeInputCount(type), nodeOutputCount(type), x, y, type, data, nodeHtml(type), false);
                });

                // Wire connections using the id map.
                ids.forEach((defId) => {
                    const n = nodes[defId] || {};
                    const from = idMap[defId];
                    const link = (targets, outClass) => {
                        // `next`/`next_true`/… may be a single string ("node") or an
                        // array (["a","b"]); [].concat normalises both so a bare
                        // string never throws on .forEach (which silently killed ALL
                        // wiring — an AI-generated flow renders its nodes with no
                        // connection lines at all).
                        [].concat(targets || []).forEach((t) => {
                            const to = idMap[String(t)];
                            if (from != null && to != null) {
                                try { editor.addConnection(from, to, outClass, 'input_1'); } catch (_) {}
                            }
                        });
                    };
                    const type = n.type || '';
                    if (type === 'condition') {
                        link(n.next_true, 'output_1');
                        link(n.next_false, 'output_2');
                    } else if (type === 'transaction') {
                        // committed → output_1, rolled_back → output_2; a plain `next`
                        // (transaction with no explicit branches) also uses output_1.
                        link(n.committed, 'output_1');
                        link(n.next, 'output_1');
                        link(n.rolled_back, 'output_2');
                    } else {
                        link(n.next, 'output_1');
                    }
                });

                // (Connection curves are redrawn by init() once the DOM settles,
                // covering both the reconstruct and the _canvas-import paths.)
                return true;
            }

            /**
             * Convert a Drawflow export into the engine definition format.
             *
             * For `condition` nodes, output_1 connections → next_true,
             * output_2 connections → next_false.  All other node types
             * collect output_1 connections → next[].
             *
             * The raw Drawflow export is stored as definition._canvas so the
             * canvas can be fully restored via editor.import() on re-open.
             */
            function toDefinition(drawflowExport, statePath) {
                const nodes = drawflowExport.drawflow && drawflowExport.drawflow.Home
                    ? drawflowExport.drawflow.Home.data
                    : {};

                let startId = null;
                const defNodes = {};

                Object.entries(nodes).forEach(([id, node]) => {
                    const type = node.name || 'trigger';
                    const data = node.data || {};

                    // Build config by type
                    let config = {};
                    switch (type) {
                        case 'trigger':
                            config = {};
                            break;
                        case 'ai_invoke':
                            config = {
                                integration: data.integration || '',
                                args: parseJson(data.args, {}),
                                output: data.output || '',
                            };
                            break;
                        case 'http_request':
                            config = {
                                method: data.method || 'GET',
                                url: data.url || '',
                                headers: parseJson(data.headers, {}),
                                body: parseJson(data.body, {}),
                                output: data.output || '',
                            };
                            if (data.credential) { config.credential = data.credential; }
                            break;
                        case 'function':
                            config = {
                                function: data.function || '',
                                args: parseJson(data.args, {}),
                                output: data.output || '',
                            };
                            break;
                        case 'send_email':
                            config = {
                                to: data.to || '',
                                subject: data.subject || '',
                                template: data.template || '',
                                body: data.body || '',
                                cc: data.cc || '',
                                bcc: data.bcc || '',
                                reply_to: data.reply_to || '',
                                output: data.output || 'email',
                            };
                            break;
                        case 'record':
                            config = {
                                model: data.model || '',
                                operation: data.operation || 'list',
                                id: data.recid || '',
                                filter: parseJson(data.filter, {}),
                                data: parseJson(data.data, {}),
                                search: data.search || '',
                                sort: data.sort || '',
                                output: data.output || 'records',
                            };
                            break;
                        case 'set_variable':
                            config = {
                                key: data.key || '',
                                value: data.value || '',
                                type: data.type || 'string',
                                output: data.output || '',
                            };
                            break;
                        case 'condition':
                            config = {
                                left: data.left || '',
                                op: data.op || 'equals',
                                right: data.right || '',
                            };
                            break;
                        case 'result':
                            config = { actions: parseJson(data.actions, []) };
                            break;
                        case 'transaction':
                            // Preserve the nested body sub-flow — without this the
                            // atomic body (the real work) is wiped on every save.
                            config = { body: parseJson(data.body, {}) };
                            break;
                        case 'loop':
                            config = {
                                over: data.over || '',
                                item_var: data.item_var || 'item',
                                body: parseJson(data.body, {}),
                            };
                            if (data.index_var) { config.index_var = data.index_var; }
                            break;
                        default:
                            config = {};
                    }

                    // Resolve output connections
                    const outputs = node.outputs || {};
                    const next = [];
                    const nextTrue = [];
                    const nextFalse = [];

                    const conns = (out) => (outputs[out] && outputs[out].connections ? outputs[out].connections : []);
                    if (type === 'condition' || type === 'transaction') {
                        conns('output_1').forEach((c) => { if (c.node) { nextTrue.push(String(c.node)); } });
                        conns('output_2').forEach((c) => { if (c.node) { nextFalse.push(String(c.node)); } });
                    } else {
                        conns('output_1').forEach((c) => { if (c.node) { next.push(String(c.node)); } });
                    }

                    const defNode = { type, config };

                    if (type === 'condition') {
                        defNode.next_true = nextTrue;
                        defNode.next_false = nextFalse;
                    } else if (type === 'transaction') {
                        // output_1 → committed, output_2 → rolled_back.
                        defNode.committed = nextTrue;
                        defNode.rolled_back = nextFalse;
                    } else {
                        defNode.next = next;
                    }

                    defNodes[String(id)] = defNode;

                    if (type === 'trigger' && startId === null) {
                        startId = String(id);
                    }
                });

                // Entry point: prefer an explicit trigger; otherwise the node with
                // no incoming connection (the graph root); otherwise the first node.
                // Without this a flow that starts at a condition/transaction (no
                // trigger node — the common AI shape) lost its `start` on save and
                // the whole flow became unrunnable ("entry point gone").
                if (startId === null) {
                    const targets = new Set();
                    Object.values(defNodes).forEach((dn) => {
                        [].concat(dn.next || [], dn.next_true || [], dn.next_false || [], dn.committed || [], dn.rolled_back || [])
                            .forEach((t) => targets.add(String(t)));
                    });
                    const roots = Object.keys(defNodes).filter((id) => ! targets.has(String(id)));
                    startId = roots[0] || Object.keys(defNodes)[0] || null;
                }

                return {
                    start: startId,
                    nodes: defNodes,
                    // Lossless Drawflow state for round-trip restore
                    _canvas: drawflowExport,
                };
            }

            const factory = (config) => ({
                editor: null,
                fullscreen: false,

                // ── Node drawer state ──
                drawerOpen: false,
                nodeSearch: '',
                // The serialised CapabilityDefinition[] injected by the field view.
                nodeDefs: (config && config.nodeDefs) || [],

                /**
                 * Map a heroicon-style icon name (the registry's icon())
                 * to a glyph. No icon-font is bundled here, so the drawer
                 * renders an emoji; unknown names fall back to a neutral dot.
                 */
                iconGlyph(name) {
                    const map = {
                        'play': '▶',
                        'sparkles': '✨',
                        'globe-alt': '🌐',
                        'wrench-screwdriver': '🔧',
                        'circle-stack': '🗃',
                        'variable': '💾',
                        'envelope': '✉',
                        'arrows-right-left': '❓',
                        'bell-alert': '🔔',
                        'arrow-path': '🔁',
                        'shield-check': '🛡',
                        'puzzle-piece': '🧩',
                        'lock-closed': '🔒',
                    };
                    return map[name] || '●';
                },

                /** Nodes matching the search box (label / description / category). */
                filtered() {
                    const q = (this.nodeSearch || '').trim().toLowerCase();
                    const defs = this.nodeDefs || [];
                    if (! q) { return defs; }
                    return defs.filter(function (d) {
                        return (
                            (d.label || '').toLowerCase().indexOf(q) !== -1 ||
                            (d.description || '').toLowerCase().indexOf(q) !== -1 ||
                            (d.category_label || '').toLowerCase().indexOf(q) !== -1 ||
                            (d.key || '').toLowerCase().indexOf(q) !== -1
                        );
                    });
                },

                /**
                 * The filtered nodes bucketed by category, each group ordered by
                 * category_order and the groups themselves in that same order —
                 * so the drawer mirrors the registry's category ordering.
                 * @returns {{category:string,label:string,order:number,nodes:Array}[]}
                 */
                grouped() {
                    const byCat = {};
                    this.filtered().forEach(function (d) {
                        const cat = d.category || 'other';
                        if (! byCat[cat]) {
                            byCat[cat] = {
                                category: cat,
                                label: d.category_label || cat,
                                order: (typeof d.category_order === 'number') ? d.category_order : 999,
                                nodes: [],
                            };
                        }
                        byCat[cat].nodes.push(d);
                    });
                    return Object.keys(byCat)
                        .map(function (k) { return byCat[k]; })
                        .sort(function (a, b) { return a.order - b.order || a.label.localeCompare(b.label); });
                },

                boot() {
                    // Expose the current flow's slug so the Run-Flow step picker can
                    // exclude it (no direct self-reference).
                    window.__pbCurrentFlowSlug = (config && config.currentFlowSlug) || null;
                    const start = () => {
                        if (! window.Drawflow) { return setTimeout(start, 50); }
                        this.init();
                    };
                    start();
                },

                toggleFullscreen() {
                    this.fullscreen = ! this.fullscreen;
                },

                /** Re-apply operation-based field visibility to every record node. */
                refreshRecordNodes() {
                    var root = this.$refs.canvas || document;
                    var sels = root.querySelectorAll('.ai-pb-node[data-node-type="record"] select[df-operation]');
                    for (var i = 0; i < sels.length; i++) {
                        if (window.__pbRecordOp) { window.__pbRecordOp(sels[i]); }
                    }
                    // Re-show the auto-detected data type on restored Set State nodes.
                    var keys = root.querySelectorAll('.ai-pb-node[data-node-type="set_variable"] select[df-key]');
                    for (var j = 0; j < keys.length; j++) {
                        if (window.__pbSetStateType) { window.__pbSetStateType(keys[j]); }
                    }
                    this.refreshResultNodes();
                },

                refreshResultNodes() {
                    var root = this.$refs.canvas || document;
                    var nodes = root.querySelectorAll('.ai-pb-node[data-node-type="result"]');
                    for (var i = 0; i < nodes.length; i++) {
                        if (window.__pbResultActions) { window.__pbResultActions(nodes[i]); }
                    }
                    // Transaction / loop bodies → step-list UI.
                    var bodies = root.querySelectorAll('.ai-pb-node[data-node-type="transaction"], .ai-pb-node[data-node-type="loop"]');
                    for (var k = 0; k < bodies.length; k++) {
                        if (window.__pbStepBody) { window.__pbStepBody(bodies[k]); }
                    }
                },

                // Badge the flow's entry node with "START". The entry is the node with
                // no incoming connection (a Trigger, or whatever the graph roots at);
                // recomputed on every wiring change so it always reflects the graph.
                markEntry() {
                    const editor = this.editor;
                    const data = (editor && editor.drawflow && editor.drawflow.drawflow && editor.drawflow.drawflow.Home)
                        ? editor.drawflow.drawflow.Home.data : null;
                    if (! data) { return; }
                    const targets = new Set();
                    Object.keys(data).forEach((id) => {
                        const outs = data[id].outputs || {};
                        Object.keys(outs).forEach((o) => {
                            ((outs[o] && outs[o].connections) || []).forEach((c) => { if (c.node) { targets.add(String(c.node)); } });
                        });
                    });
                    Object.keys(data).forEach((id) => {
                        const el = document.getElementById('node-' + id);
                        if (! el) { return; }
                        el.classList.toggle('pb-entry', ! targets.has(String(id)));
                    });
                },

                // Is a trigger node already on the canvas? A flow has ONE entry, so
                // only a single trigger is allowed.
                hasTrigger() {
                    const data = (this.editor && this.editor.drawflow && this.editor.drawflow.drawflow && this.editor.drawflow.drawflow.Home)
                        ? this.editor.drawflow.drawflow.Home.data : {};
                    return Object.keys(data || {}).some((id) => (data[id] || {}).name === 'trigger');
                },

                addNode(type) {
                    if (! this.editor) { return; }
                    // A flow has a single entry point — refuse a second trigger.
                    if (type === 'trigger' && this.hasTrigger()) {
                        if (window.$wireui || window.Alpine) { /* no-op */ }
                        alert('This flow already has a Trigger. A flow has one entry point — branch with a Condition instead.');
                        return;
                    }
                    // Cascade new nodes in a grid so they never pile on top of each
                    // other (3 per row, generous spacing for the tallest cards).
                    this._n = (this._n || 0) + 1;
                    const col = this._n % 3;
                    const row = Math.floor(this._n / 3);
                    const x = 120 + col * 300;
                    const y = 60 + row * 300;
                    this.editor.addNode(type, nodeInputCount(type), nodeOutputCount(type), x, y, type, {}, nodeHtml(type), false);
                    if (type === 'record' || type === 'result' || type === 'transaction' || type === 'loop') {
                        var self = this;
                        setTimeout(function () { self.refreshRecordNodes(); }, 0);
                    }
                },

                sync() {
                    if (! this.editor) { return; }
                    const exported = this.editor.export();
                    const definition = toDefinition(exported, config.statePath);
                    this.$wire.set(config.statePath, definition, false);
                },

                // Guarantee no two nodes overlap after an auto-layout (AI/programmatic
                // reconstruct). Measures each node's REAL rendered size, groups nodes
                // into columns by x, then stacks each column top-down (gap ≥ node
                // height) and offsets each column right past the previous column's
                // widest node — so overlap is structurally impossible at any size.
                // Only runs after reconstruct, never on a user's hand-arranged layout.
                reflowNoOverlap() {
                    const editor = this.editor;
                    const data = (editor && editor.drawflow && editor.drawflow.drawflow && editor.drawflow.drawflow.Home)
                        ? editor.drawflow.drawflow.Home.data : null;
                    if (! data) { return; }
                    const GAP_X = 80, GAP_Y = 48, COL_TOL = 200;
                    const items = Object.keys(data).map((id) => {
                        const el = document.getElementById('node-' + id);
                        return el ? { id, el, x: data[id].pos_x, y: data[id].pos_y, w: el.offsetWidth || 250, h: el.offsetHeight || 140 } : null;
                    }).filter(Boolean);
                    if (! items.length) { return; }
                    items.sort((a, b) => a.x - b.x);
                    const cols = [];
                    items.forEach((it) => {
                        let col = cols.find((c) => Math.abs(c.x - it.x) < COL_TOL);
                        if (! col) { col = { x: it.x, items: [] }; cols.push(col); }
                        col.items.push(it);
                    });
                    cols.sort((a, b) => a.x - b.x);
                    let runX = Math.min.apply(null, items.map((i) => i.x));
                    cols.forEach((col) => {
                        col.items.sort((a, b) => a.y - b.y);
                        let runY = Math.min.apply(null, col.items.map((i) => i.y));
                        let maxW = 0;
                        col.items.forEach((it) => {
                            it.el.style.left = runX + 'px';
                            it.el.style.top = runY + 'px';
                            data[it.id].pos_x = runX;
                            data[it.id].pos_y = runY;
                            runY += it.h + GAP_Y;
                            if (it.w > maxW) { maxW = it.w; }
                        });
                        runX += maxW + GAP_X;
                    });
                    Object.keys(data).forEach((id) => { try { editor.updateConnectionNodes('node-' + id); } catch (_) {} });
                    this.sync();
                },

                init() {
                    if (this.editor) { return; }

                    const el = this.$refs.canvas;
                    if (! el) { return; }

                    // Clear any previous content
                    el.innerHTML = '';

                    const editor = new window.Drawflow(el);
                    editor.reroute = true;
                    editor.start();

                    this.editor = editor;

                    // Restore from existing state or seed with a trigger node
                    const existing = this.$wire.get(config.statePath);
                    if (existing && existing._canvas) {
                        try {
                            editor.import(existing._canvas);
                            // import() fires no node/connection events, so re-sync
                            // once to re-normalise the definition (recompute `start`,
                            // preserve transaction/loop bodies) — self-heals a flow
                            // saved by an older, lossy version of this editor.
                            this.sync();
                        } catch (_) {
                            editor.addNode('trigger', 0, 1, 100, 100, 'trigger', {}, nodeHtml('trigger'), false);
                        }
                    } else if (existing && existing.nodes && Object.keys(existing.nodes).length) {
                        // Engine-format flow with no canvas snapshot (AI-built /
                        // programmatic): rebuild the canvas from the node graph,
                        // then persist a _canvas so later opens use the fast path.
                        try {
                            reconstructFromDefinition(editor, existing);
                            this.sync();
                            // Auto-laid-out graph → guarantee no overlap once nodes
                            // have real rendered sizes (a couple frames later).
                            this._needsReflow = true;
                        } catch (_) {
                            editor.addNode('trigger', 0, 1, 100, 100, 'trigger', {}, nodeHtml('trigger'), false);
                        }
                    } else {
                        editor.addNode('trigger', 0, 1, 100, 100, 'trigger', {}, nodeHtml('trigger'), false);
                        this.sync();
                    }

                    // Wire change events → sync. nodeMoved is essential: without
                    // it, dragging a node to re-arrange never updates the form
                    // state, so the layout reverts to its saved positions on the
                    // next open.
                    const syncBound = () => { this.sync(); this.markEntry(); };
                    editor.on('nodeCreated', syncBound);
                    editor.on('nodeRemoved', syncBound);
                    editor.on('nodeMoved', () => this.sync());
                    editor.on('connectionCreated', syncBound);
                    editor.on('connectionRemoved', syncBound);
                    editor.on('nodeDataChanged', syncBound);

                    // Redraw every connection once the DOM has settled. Drawflow
                    // computes a connection's curve from node geometry that isn't
                    // available the instant nodes are created/imported, so paths
                    // can render empty until a node is dragged — applies to BOTH
                    // the import and the reconstruct paths above.
                    const refreshAllConnections = () => {
                        try {
                            const data = editor.export().drawflow.Home.data || {};
                            Object.keys(data).forEach((id) => {
                                try { editor.updateConnectionNodes('node-' + id); } catch (_) {}
                            });
                        } catch (_) {}
                    };
                    try { requestAnimationFrame(refreshAllConnections); } catch (_) { setTimeout(refreshAllConnections, 30); }
                    setTimeout(refreshAllConnections, 120);
                    setTimeout(() => this.markEntry(), 200);
                    // After an auto-layout, resolve overlaps once nodes have measured
                    // sizes, then redraw the connection curves for the new positions.
                    if (this._needsReflow) {
                        this._needsReflow = false;
                        setTimeout(() => { this.reflowNoOverlap(); refreshAllConnections(); this.markEntry(); }, 160);
                    }

                    // Apply operation-based field visibility to restored record nodes.
                    const self = this;
                    setTimeout(() => self.refreshRecordNodes(), 0);

                    // Remember the last-focused node field so the States picker can
                    // insert a reference at the caret.
                    el.addEventListener('focusin', function (e) {
                        const t = e.target;
                        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') && t.closest('.ai-pb-node')) {
                            self._lastField = t;
                        }
                    });
                },

                /**
                 * Insert a {{ states.<key> }} reference at the caret of the last
                 * focused node field (or append if none focused yet).
                 */
                insertState(key) {
                    if (! key) { return; }
                    const f = this._lastField;
                    if (! f || ! f.isConnected) { return; }
                    const token = '{{ states.' + key + ' }}';
                    const start = (f.selectionStart != null) ? f.selectionStart : f.value.length;
                    const end = (f.selectionEnd != null) ? f.selectionEnd : start;
                    f.value = f.value.slice(0, start) + token + f.value.slice(end);
                    const pos = start + token.length;
                    try { f.setSelectionRange(pos, pos); } catch (e) {}
                    f.dispatchEvent(new Event('input', { bubbles: true }));
                    f.focus();
                    this.sync();
                },
            });

            const register = () => window.Alpine.data('aiPbFlow', factory);
            if (window.Alpine) { register(); } else { document.addEventListener('alpine:init', register); }
        })();
    </script>
    @endverbatim
@endonce
