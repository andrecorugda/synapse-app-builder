{{-- Loads Drawflow once per panel page AND registers the aiPbFlow Alpine
     component. This is injected into the panel LAYOUT via a render hook
     (panels::body.end) — not the field's component view — because Livewire does
     not reliably compile/run scripts inside a sub-component view. The field view
     only carries the markup + x-data config call. --}}
@once
    <link rel="stylesheet" href="{{ config('ai-page-builder.flow.drawflow_css', 'https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.css') }}">
    <script src="{{ config('ai-page-builder.flow.drawflow_js', 'https://cdn.jsdelivr.net/npm/drawflow/dist/drawflow.min.js') }}"></script>
    <style>
        /* ── Drawflow canvas wrapper ── */
        .ai-pb-flow-wrap {
            position: relative;
            background: #f1f5f9;
            border: 1px solid rgb(0 0 0 / 0.1);
            border-radius: 0.75rem;
            overflow: hidden;
        }
        .ai-pb-flow-wrap .drawflow {
            width: 100%;
            height: 100%;
            background-color: #f1f5f9;
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 20px 20px;
        }
        /* ── Node card styles ── */
        .ai-pb-node {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            min-width: 200px;
            font-size: 0.75rem;
            font-family: ui-sans-serif, system-ui, sans-serif;
            box-shadow: 0 1px 3px rgb(0 0 0 / 0.08);
        }
        .ai-pb-node-title {
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .ai-pb-node input,
        .ai-pb-node select {
            width: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 0.25rem;
            padding: 0.2rem 0.35rem;
            font-size: 0.72rem;
            margin-bottom: 0.25rem;
            outline: none;
            background: #f8fafc;
            color: #0f172a;
        }
        .ai-pb-node input:focus,
        .ai-pb-node select:focus {
            border-color: #6366f1;
            background: #fff;
        }
        .ai-pb-node textarea {
            width: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 0.25rem;
            padding: 0.2rem 0.35rem;
            font-size: 0.7rem;
            font-family: ui-monospace, monospace;
            resize: vertical;
            min-height: 3rem;
            margin-bottom: 0.25rem;
            outline: none;
            background: #f8fafc;
            color: #0f172a;
        }
        .ai-pb-node textarea:focus {
            border-color: #6366f1;
            background: #fff;
        }
        .ai-pb-node label.ai-pb-node-label {
            display: block;
            font-size: 0.67rem;
            color: #94a3b8;
            margin-bottom: 0.1rem;
        }
        /* ── Palette bar ── */
        .ai-pb-palette {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
            padding: 0.5rem 0.75rem;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            border-radius: 0.75rem 0.75rem 0 0;
        }
        .ai-pb-palette button {
            padding: 0.2rem 0.6rem;
            border-radius: 0.3rem;
            font-size: 0.72rem;
            font-weight: 500;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #334155;
            cursor: pointer;
            line-height: 1.4;
        }
        .ai-pb-palette button:hover {
            background: #e0e7ff;
            border-color: #6366f1;
            color: #4338ca;
        }
        /* node type accent colours via title bar */
        .ai-pb-node[data-node-type="trigger"] .ai-pb-node-title { color: #16a34a; }
        .ai-pb-node[data-node-type="ai_invoke"] .ai-pb-node-title { color: #7c3aed; }
        .ai-pb-node[data-node-type="http_request"] .ai-pb-node-title { color: #0284c7; }
        .ai-pb-node[data-node-type="condition"] .ai-pb-node-title { color: #d97706; }
        .ai-pb-node[data-node-type="result"] .ai-pb-node-title { color: #db2777; }
        /* ── Neutralise Drawflow's default node chrome so only our card shows ──
           (otherwise the library's box draws a second border around the card and
           clamps it to ~160px, clipping the content). */
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
        /* keep the connection ports visible against the white card */
        .ai-pb-flow-wrap .drawflow .drawflow-node .input,
        .ai-pb-flow-wrap .drawflow .drawflow-node .output {
            background: #fff;
            border: 2px solid #6366f1;
        }
    </style>
    {{-- The script below is wrapped so Blade does not parse literal braces in JS. --}}
    @verbatim
    <script>
        (function () {
            /** Build the inner HTML for a Drawflow node by type. */
            function nodeHtml(type) {
                switch (type) {
                    case 'trigger':
                        return '<div class="ai-pb-node" data-node-type="trigger">'
                            + '<div class="ai-pb-node-title">&#9654; Trigger</div>'
                            + '<span style="font-size:0.7rem;color:#64748b;">Flow entry point</span>'
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
                            + '<label class="ai-pb-node-label">Function slug</label>'
                            + '<input type="text" df-function placeholder="e.g. markup-price" />'
                            + '<label class="ai-pb-node-label">Args (JSON)</label>'
                            + '<textarea df-args placeholder=\'{"price":"{{vars.amount}}"}\'></textarea>'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. result" />'
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
                            + '<span style="color:#16a34a;">&#9679; output_1 = true</span>'
                            + '<span style="color:#dc2626;">&#9679; output_2 = false</span>'
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

                    // Parse JSON text areas
                    let args = {};
                    if (data.args) {
                        try { args = JSON.parse(data.args); } catch (_) { args = {}; }
                    }
                    let actions = [];
                    if (data.actions) {
                        try { actions = JSON.parse(data.actions); } catch (_) { actions = []; }
                    }
                    let headers = {};
                    if (data.headers) {
                        try { headers = JSON.parse(data.headers); } catch (_) { headers = {}; }
                    }
                    let body = {};
                    if (data.body) {
                        try { body = JSON.parse(data.body); } catch (_) { body = {}; }
                    }

                    // Build config by type
                    let config = {};
                    switch (type) {
                        case 'trigger':
                            config = {};
                            break;
                        case 'ai_invoke':
                            config = {
                                integration: data.integration || '',
                                args: args,
                                output: data.output || '',
                            };
                            break;
                        case 'http_request':
                            config = {
                                method: data.method || 'GET',
                                url: data.url || '',
                                headers: headers,
                                body: body,
                                output: data.output || '',
                            };
                            break;
                        case 'function':
                            config = {
                                function: data.function || '',
                                args: args,
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
                            config = { actions: actions };
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
                        // output_1 → true branch, output_2 → false branch
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

                boot() {
                    const start = () => {
                        if (! window.Drawflow) { return setTimeout(start, 50); }
                        this.init();
                    };
                    start();
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
                            // Malformed — start fresh
                            editor.addNode('trigger', 0, 1, 100, 100, 'trigger', {}, nodeHtml('trigger'), false);
                        }
                    } else {
                        // New flow: seed with one trigger node
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
                },
            });

            const register = () => window.Alpine.data('aiPbFlow', factory);
            if (window.Alpine) { register(); } else { document.addEventListener('alpine:init', register); }
        })();
    </script>
    @endverbatim
@endonce
