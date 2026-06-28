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
    @endphp
    <script>
        window.__pbFlowFunctions = @js($pbFlowFunctions);
        window.__pbCollections = @js($pbCollections);
        window.__pbVariables = @js($pbVariables);
        window.__pbEmailTemplates = @js($pbEmailTemplates);
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
                            + '<input type="text" df-integration placeholder="e.g. page_builder" />'
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
                            + '<label class="ai-pb-node-label">Actions (JSON array)</label>'
                            + '<textarea df-actions placeholder=\'[{"type":"notify","message":"Done"}]\'></textarea>'
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
                return type === 'condition' ? 2 : 1;
            }

            function nodeInputCount(type) {
                return type === 'trigger' ? 0 : 1;
            }

            function parseJson(text, fallback) {
                if (!text) { return fallback; }
                try { return JSON.parse(text); } catch (_) { return fallback; }
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
                        default:
                            config = {};
                    }

                    // Resolve output connections
                    const outputs = node.outputs || {};
                    const next = [];
                    const nextTrue = [];
                    const nextFalse = [];

                    if (type === 'condition') {
                        const out1 = outputs['output_1'] && outputs['output_1'].connections
                            ? outputs['output_1'].connections
                            : [];
                        const out2 = outputs['output_2'] && outputs['output_2'].connections
                            ? outputs['output_2'].connections
                            : [];
                        out1.forEach((c) => { if (c.node) { nextTrue.push(String(c.node)); } });
                        out2.forEach((c) => { if (c.node) { nextFalse.push(String(c.node)); } });
                    } else {
                        const out1 = outputs['output_1'] && outputs['output_1'].connections
                            ? outputs['output_1'].connections
                            : [];
                        out1.forEach((c) => { if (c.node) { next.push(String(c.node)); } });
                    }

                    const defNode = { type, config };

                    if (type === 'condition') {
                        defNode.next_true = nextTrue;
                        defNode.next_false = nextFalse;
                    } else {
                        defNode.next = next;
                    }

                    defNodes[String(id)] = defNode;

                    if (type === 'trigger' && startId === null) {
                        startId = String(id);
                    }
                });

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

                boot() {
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
                },

                addNode(type) {
                    if (! this.editor) { return; }
                    // Cascade new nodes in a grid so they never pile on top of each
                    // other (3 per row, generous spacing for the tallest cards).
                    this._n = (this._n || 0) + 1;
                    const col = this._n % 3;
                    const row = Math.floor(this._n / 3);
                    const x = 120 + col * 300;
                    const y = 60 + row * 300;
                    this.editor.addNode(type, nodeInputCount(type), nodeOutputCount(type), x, y, type, {}, nodeHtml(type), false);
                    if (type === 'record') {
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
                        } catch (_) {
                            editor.addNode('trigger', 0, 1, 100, 100, 'trigger', {}, nodeHtml('trigger'), false);
                        }
                    } else {
                        editor.addNode('trigger', 0, 1, 100, 100, 'trigger', {}, nodeHtml('trigger'), false);
                        this.sync();
                    }

                    // Wire change events → sync
                    const syncBound = () => this.sync();
                    editor.on('nodeCreated', syncBound);
                    editor.on('nodeRemoved', syncBound);
                    editor.on('connectionCreated', syncBound);
                    editor.on('connectionRemoved', syncBound);
                    editor.on('nodeDataChanged', syncBound);

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
