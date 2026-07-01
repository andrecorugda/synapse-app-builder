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
        window.__pbNodeIcons = {'trigger':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#22C55E" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M128,32a96,96,0,1,0,96,96A96,96,0,0,0,128,32ZM108,168V88l64,40Z" opacity="0.2"/><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm48.24-94.78-64-40A8,8,0,0,0,100,88v80a8,8,0,0,0,12.24,6.78l64-40a8,8,0,0,0,0-13.56ZM116,153.57V102.43L156.91,128Z"/></svg>','condition':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#FBBF24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,64a24,24,0,1,1-24-24A24,24,0,0,1,224,64Z" opacity="0.2"/><path d="M232,64a32,32,0,1,0-40,31v17a8,8,0,0,1-8,8H96a23.84,23.84,0,0,0-8,1.38V95a32,32,0,1,0-16,0v66a32,32,0,1,0,16,0V144a8,8,0,0,1,8-8h88a24,24,0,0,0,24-24V95A32.06,32.06,0,0,0,232,64ZM64,64A16,16,0,1,1,80,80,16,16,0,0,1,64,64ZM96,192a16,16,0,1,1-16-16A16,16,0,0,1,96,192ZM200,80a16,16,0,1,1,16-16A16,16,0,0,1,200,80Z"/></svg>','set_variable':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#E879F9" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M216,83.31V208a8,8,0,0,1-8,8H176V152a8,8,0,0,0-8-8H88a8,8,0,0,0-8,8v64H48a8,8,0,0,1-8-8V48a8,8,0,0,1,8-8H172.69a8,8,0,0,1,5.65,2.34l35.32,35.32A8,8,0,0,1,216,83.31Z" opacity="0.2"/><path d="M219.31,72,184,36.69A15.86,15.86,0,0,0,172.69,32H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V83.31A15.86,15.86,0,0,0,219.31,72ZM168,208H88V152h80Zm40,0H184V152a16,16,0,0,0-16-16H88a16,16,0,0,0-16,16v56H48V48H172.69L208,83.31ZM160,72a8,8,0,0,1-8,8H96a8,8,0,0,1,0-16h56A8,8,0,0,1,160,72Z"/></svg>','function':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#F59E0B" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M200,40V200a16,16,0,0,1-16,16H56V56A16,16,0,0,1,72,40Z" opacity="0.2"/><path d="M208,40a8,8,0,0,1-8,8H170.71a24,24,0,0,0-23.62,19.71L137.59,120H184a8,8,0,0,1,0,16H134.68l-10,55.16A40,40,0,0,1,85.29,224H56a8,8,0,0,1,0-16H85.29a24,24,0,0,0,23.62-19.71l9.5-52.29H72a8,8,0,0,1,0-16h49.32l10-55.16A40,40,0,0,1,170.71,32H200A8,8,0,0,1,208,40Z"/></svg>','record':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#2DD4BF" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M216,80c0,26.51-39.4,48-88,48S40,106.51,40,80s39.4-48,88-48S216,53.49,216,80Z" opacity="0.2"/><path d="M128,24C74.17,24,32,48.6,32,80v96c0,31.4,42.17,56,96,56s96-24.6,96-56V80C224,48.6,181.83,24,128,24Zm80,104c0,9.62-7.88,19.43-21.61,26.92C170.93,163.35,150.19,168,128,168s-42.93-4.65-58.39-13.08C55.88,147.43,48,137.62,48,128V111.36c17.06,15,46.23,24.64,80,24.64s62.94-9.68,80-24.64ZM69.61,53.08C85.07,44.65,105.81,40,128,40s42.93,4.65,58.39,13.08C200.12,60.57,208,70.38,208,80s-7.88,19.43-21.61,26.92C170.93,115.35,150.19,120,128,120s-42.93-4.65-58.39-13.08C55.88,99.43,48,89.62,48,80S55.88,60.57,69.61,53.08ZM186.39,202.92C170.93,211.35,150.19,216,128,216s-42.93-4.65-58.39-13.08C55.88,195.43,48,185.62,48,176V159.36c17.06,15,46.23,24.64,80,24.64s62.94-9.68,80-24.64V176C208,185.62,200.12,195.43,186.39,202.92Z"/></svg>','http_request':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#38BDF8" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,128a96,96,0,1,1-96-96A96,96,0,0,1,224,128Z" opacity="0.2"/><path d="M128,24h0A104,104,0,1,0,232,128,104.12,104.12,0,0,0,128,24Zm88,104a87.61,87.61,0,0,1-3.33,24H174.16a157.44,157.44,0,0,0,0-48h38.51A87.61,87.61,0,0,1,216,128ZM102,168H154a115.11,115.11,0,0,1-26,45A115.27,115.27,0,0,1,102,168Zm-3.9-16a140.84,140.84,0,0,1,0-48h59.88a140.84,140.84,0,0,1,0,48ZM40,128a87.61,87.61,0,0,1,3.33-24H81.84a157.44,157.44,0,0,0,0,48H43.33A87.61,87.61,0,0,1,40,128ZM154,88H102a115.11,115.11,0,0,1,26-45A115.27,115.27,0,0,1,154,88Zm52.33,0H170.71a135.28,135.28,0,0,0-22.3-45.6A88.29,88.29,0,0,1,206.37,88ZM107.59,42.4A135.28,135.28,0,0,0,85.29,88H49.63A88.29,88.29,0,0,1,107.59,42.4ZM49.63,168H85.29a135.28,135.28,0,0,0,22.3,45.6A88.29,88.29,0,0,1,49.63,168Zm98.78,45.6a135.28,135.28,0,0,0,22.3-45.6h35.66A88.29,88.29,0,0,1,148.41,213.6Z"/></svg>','ai_invoke':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#A78BFA" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M194.82,151.43l-55.09,20.3-20.3,55.09a7.92,7.92,0,0,1-14.86,0l-20.3-55.09-55.09-20.3a7.92,7.92,0,0,1,0-14.86l55.09-20.3,20.3-55.09a7.92,7.92,0,0,1,14.86,0l20.3,55.09,55.09,20.3A7.92,7.92,0,0,1,194.82,151.43Z" opacity="0.2"/><path d="M197.58,129.06,146,110l-19-51.62a15.92,15.92,0,0,0-29.88,0L78,110l-51.62,19a15.92,15.92,0,0,0,0,29.88L78,178l19,51.62a15.92,15.92,0,0,0,29.88,0L146,178l51.62-19a15.92,15.92,0,0,0,0-29.88ZM137,164.22a8,8,0,0,0-4.74,4.74L112,223.85,91.78,169A8,8,0,0,0,87,164.22L32.15,144,87,123.78A8,8,0,0,0,91.78,119L112,64.15,132.22,119a8,8,0,0,0,4.74,4.74L191.85,144ZM144,40a8,8,0,0,1,8-8h16V16a8,8,0,0,1,16,0V32h16a8,8,0,0,1,0,16H184V64a8,8,0,0,1-16,0V48H152A8,8,0,0,1,144,40ZM248,88a8,8,0,0,1-8,8h-8v8a8,8,0,0,1-16,0V96h-8a8,8,0,0,1,0-16h8V72a8,8,0,0,1,16,0v8h8A8,8,0,0,1,248,88Z"/></svg>','send_email':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#34D399" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,56l-96,88L32,56Z" opacity="0.2"/><path d="M224,48H32a8,8,0,0,0-8,8V192a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A8,8,0,0,0,224,48Zm-96,85.15L52.57,64H203.43ZM98.71,128,40,181.81V74.19Zm11.84,10.85,12,11.05a8,8,0,0,0,10.82,0l12-11.05,58,53.15H52.57ZM157.29,128,216,74.18V181.82Z"/></svg>','result':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#F472B6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M232,56l-45.71,96H40l48-48L40,56Z" opacity="0.2"/><path d="M238.76,51.73A8,8,0,0,0,232,48H40a8,8,0,0,0-5.66,13.66L76.69,104,34.34,146.34A8,8,0,0,0,40,160H173.62l-28.84,60.56a8,8,0,1,0,14.44,6.88l80-168A8,8,0,0,0,238.76,51.73ZM181.23,144H59.31l34.35-34.34a8,8,0,0,0,0-11.32L59.31,64h160Z"/></svg>','loop':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#F43F5E" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,64v64a64,64,0,0,1-64,64H32V128A64,64,0,0,1,96,64Z" opacity="0.2"/><path d="M24,128A72.08,72.08,0,0,1,96,56H204.69L194.34,45.66a8,8,0,0,1,11.32-11.32l24,24a8,8,0,0,1,0,11.32l-24,24a8,8,0,0,1-11.32-11.32L204.69,72H96a56.06,56.06,0,0,0-56,56,8,8,0,0,1-16,0Zm200-8a8,8,0,0,0-8,8,56.06,56.06,0,0,1-56,56H51.31l10.35-10.34a8,8,0,0,0-11.32-11.32l-24,24a8,8,0,0,0,0,11.32l24,24a8,8,0,0,0,11.32-11.32L51.31,200H160a72.08,72.08,0,0,0,72-72A8,8,0,0,0,224,120Z"/></svg>','transaction':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#22C55E" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M216,56v56c0,96-88,120-88,120S40,208,40,112V56a8,8,0,0,1,8-8H208A8,8,0,0,1,216,56Z" opacity="0.2"/><path d="M208,40H48A16,16,0,0,0,32,56v56c0,52.72,25.52,84.67,46.93,102.19,23.06,18.86,46,25.26,47,25.53a8,8,0,0,0,4.2,0c1-.27,23.91-6.67,47-25.53C198.48,196.67,224,164.72,224,112V56A16,16,0,0,0,208,40Zm0,72c0,37.07-13.66,67.16-40.6,89.42A129.3,129.3,0,0,1,128,223.62a128.25,128.25,0,0,1-38.92-21.81C61.82,179.51,48,149.3,48,112l0-56,160,0ZM82.34,141.66a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35a8,8,0,0,1,11.32,11.32l-56,56a8,8,0,0,1-11.32,0Z"/></svg>','call_flow':'<svg style="width:18px;height:18px;vertical-align:middle;fill:#8B5CF6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M80,176a32,32,0,1,1-32-32A32,32,0,0,1,80,176Z" opacity="0.2"/><path d="M245.66,74.34l-32-32a8,8,0,0,0-11.32,11.32L220.69,72H208c-49.33,0-61.05,28.12-71.38,52.92-9.38,22.51-16.92,40.59-49.48,42.84a40,40,0,1,0,.1,16c43.26-2.65,54.34-29.15,64.14-52.69C161.41,107,169.33,88,208,88h12.69l-18.35,18.34a8,8,0,0,0,11.32,11.32l32-32A8,8,0,0,0,245.66,74.34ZM48,200a24,24,0,1,1,24-24A24,24,0,0,1,48,200Z"/></svg>',};
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
        /* Drawflow's stylesheet sets `svg { position: absolute }` (for its connection
           lines); our node-title / node-body icons inherit it and stack on top of the
           label. Force them back into normal flow so the flex row lays them out. */
        .ai-pb-node svg { position: static !important; }
        .ai-pb-node-title svg,
        .ai-pb-node-label svg { flex: 0 0 auto; }
        .ai-pb-step-icon { display: inline-flex; align-items: center; flex: 0 0 auto; }
        .ai-pb-step-icon svg { width: 16px !important; height: 16px !important; }
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
            position: relative;
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-left: 3px solid #6366f1;
            border-radius: 0.45rem;
            padding: 0.35rem 0.45rem 0.5rem;
            margin: 0.3rem 0;
            background: rgba(30, 41, 59, 0.4);
        }
        /* Reorder / delete live in the corner, OUT of the layout flow, so they never
           steal width from the kind dropdown or get clipped. */
        .ai-pb-step-actions { position: absolute; top: 4px; right: 4px; z-index: 4; }
        .ai-pb-step-kebab {
            border: 0; background: transparent; color: #94a3b8; cursor: pointer;
            width: 1.4rem; height: 1.4rem; line-height: 1; font-size: 1rem; border-radius: 0.3rem;
        }
        .ai-pb-step-kebab:hover { background: rgba(148,163,184,0.2); color: #e2e8f0; }
        .ai-pb-step-menu {
            position: absolute; top: 1.5rem; right: 0; display: flex; gap: 2px;
            background: #0f172a; border: 1px solid rgba(148,163,184,0.3);
            border-radius: 0.4rem; padding: 3px; box-shadow: 0 6px 20px rgba(0,0,0,0.5); z-index: 10000;
        }
        .ai-pb-step-menu[hidden] { display: none; }
        /* The step whose menu is open floats above sibling steps and the node body
           (its own stacking context would otherwise trap the popover behind them). */
        .ai-pb-step.ai-pb-step--menu-open { z-index: 9999; }
        .ai-pb-step-head { display: flex; align-items: center; gap: 0.3rem; margin-bottom: 0.25rem; padding-right: 1.6rem; }
        .ai-pb-step-num {
            flex: 0 0 auto; width: 1.15rem; height: 1.15rem; line-height: 1.15rem;
            text-align: center; border-radius: 999px; background: #6366f1; color: #eef2ff;
            font-size: 0.62rem; font-weight: 700;
        }
        /* Shrinkable so the reorder/delete buttons after it never get pushed
           out of the card (min-width:0 lets flex actually shrink it). */
        .ai-pb-step-kind { flex: 1 1 auto; min-width: 0; width: auto; margin: 0 !important; }
        .ai-pb-step-btn {
            flex: 0 0 auto; border: 0; background: rgba(148, 163, 184, 0.18); color: #cbd5e1;
            border-radius: 0.3rem; width: 1.3rem; height: 1.3rem; line-height: 1; cursor: pointer; font-size: 0.8rem;
        }
        .ai-pb-step-btn:hover { background: rgba(148, 163, 184, 0.34); }
        .ai-pb-step-del { background: rgba(248, 113, 113, 0.15); color: #fca5a5; }
        .ai-pb-step-nested { margin: 0.25rem 0 0.25rem 0.5rem; padding-left: 0.4rem; border-left: 1px dashed rgba(148,163,184,0.3); }
        .ai-pb-step-raw { font-size: 0.68rem; color: #94a3b8; font-style: italic; padding: 0.15rem 0; }
        .ai-pb-step-fields { display: flex; flex-direction: column; }
        /* Keep transaction/loop nodes compact: fixed width + scroll the step list
           instead of the card growing tall enough to overlap neighbours. Nothing
           inside may overflow the card horizontally. */
        .drawflow .drawflow-node .ai-pb-node[data-node-type="transaction"],
        .drawflow .drawflow-node .ai-pb-node[data-node-type="loop"],
        .ai-pb-node[data-node-type="transaction"],
        .ai-pb-node[data-node-type="loop"] { width: 400px; max-width: 400px; }
        .ai-pb-node[data-node-type="transaction"] *,
        .ai-pb-node[data-node-type="loop"] * { max-width: 100%; box-sizing: border-box; }
        /* No inner scroll — the node grows to show every step (inner scrolling is
           awkward to use). The canvas zoom / Fit + the non-overlap reflow handle a
           tall node. Horizontal is still contained so nothing spills out sideways. */
        .ai-pb-steps { overflow: visible; }
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
        /* ── Zoom controls ── */
        .ai-pb-zoom { display: inline-flex; gap: 2px; align-items: center; margin-right: 0.4rem; }
        .ai-pb-zoom-btn {
            border: 1px solid rgb(255 255 255 / 0.14);
            background: rgb(255 255 255 / 0.06);
            color: #cbd5e1;
            border-radius: 0.35rem;
            min-width: 1.7rem;
            height: 1.7rem;
            padding: 0 0.4rem;
            font-size: 0.85rem;
            line-height: 1;
            cursor: pointer;
        }
        .ai-pb-zoom-btn:hover { background: rgb(255 255 255 / 0.14); color: #fff; }
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
        .ai-pb-node[data-node-type="call_flow"] .ai-pb-node-title { color: #8b5cf6; }
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
        /* Branch ports carry meaning: the first output is the true / committed path
           (green), the second is the false / rolled-back path (red). addNode()'s 6th
           arg puts the node type as a class on .drawflow-node, so we can target it. */
        .ai-pb-flow-wrap .drawflow .drawflow-node.condition .output_1,
        .ai-pb-flow-wrap .drawflow .drawflow-node.transaction .output_1 {
            border-color: #22c55e;
            background: #14532d;
        }
        .ai-pb-flow-wrap .drawflow .drawflow-node.condition .output_2,
        .ai-pb-flow-wrap .drawflow .drawflow-node.transaction .output_2 {
            border-color: #f87171;
            background: #7f1d1d;
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

            // Render the low-code actions builder into `mount`, operating on the
            // in-memory `actions` array. On any edit it mutates `actions` in place and
            // calls onChange(actions). Shared by the top-level Result node (via a
            // hidden df-actions bridge) and the Result STEP inside a transaction/loop
            // body (which persists straight to step.config.actions) — single source of
            // truth for the action-type catalog + field rendering.
            function pbActionsBuilder(mount, actions, onChange) {
                function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
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
                        row.querySelector('.ai-pb-action-type').addEventListener('change', function (e) { actions[idx] = { type: e.target.value }; onChange(actions); render(); });
                        row.querySelector('.ai-pb-action-del').addEventListener('click', function () { actions.splice(idx, 1); onChange(actions); render(); });
                        spec.fields.forEach(function (f) {
                            var ctl = row.querySelector('[data-k="' + f.key + '"]');
                            if (! ctl) { return; }
                            if (f.type === 'select' && act[f.key] != null) { ctl.value = act[f.key]; }
                            ctl.addEventListener(f.type === 'select' ? 'change' : 'input', function () {
                                actions[idx][f.key] = ctl.value;
                                onChange(actions);
                                if (f.type === 'select') { render(); } // a select may gate a showIf field
                            });
                        });
                        mount.appendChild(row);
                    });
                    var add = document.createElement('button');
                    add.type = 'button';
                    add.className = 'ai-pb-action-add';
                    add.textContent = '+ Add action';
                    add.addEventListener('click', function () { actions.push({ type: 'notify', message: '', level: 'success' }); onChange(actions); render(); });
                    mount.appendChild(add);
                }
                render();
            }

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

                // Delegate the UI to the shared builder; bridge writes back into the
                // hidden df-actions textarea + dispatch input/change so Drawflow re-syncs.
                pbActionsBuilder(mount, actions, function (updated) {
                    hidden.value = JSON.stringify(updated);
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                });
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
                    else if (t === 'loop') { steps.push({ kind: 'loop', over: c.over || '', item_var: c.item_var || 'item', index_var: c.index_var || '', max_iterations: (c.max_iterations != null ? c.max_iterations : ''), output: c.output || '', steps: pbDecompileBody(c.body || {}) }); }
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
                    else if (s.kind === 'loop') {
                        var loopCfg = { over: s.over || '', item_var: s.item_var || 'item', index_var: s.index_var || '', body: pbCompileSteps(s.steps || []) };
                        // Only carry the optional caps when the author set them, so an
                        // untouched loop's config stays minimal (LoopNode defaults apply).
                        if (s.max_iterations !== '' && s.max_iterations != null && ! isNaN(s.max_iterations)) { loopCfg.max_iterations = s.max_iterations; }
                        if (s.output) { loopCfg.output = s.output; }
                        node = { type: 'loop', config: loopCfg };
                    }
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
            function pbEsc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
            // Node types that can be a step (besides function/flow/loop), shown in the
            // kind dropdown and edited via their capability-schema fields.
            var PB_STEP_NODE_TYPES = [
                { type: 'record', label: 'Record (data op)' },
                { type: 'set_variable', label: 'Set state' },
                // NOTE: 'condition' is intentionally omitted here. A step body is a
                // LINEAR sub-flow — pbCompileSteps only chains each step's `next` — but
                // ConditionNode branches on next_true/next_false, which a linear chain
                // can't express. A condition step inside a transaction/loop body would
                // dead-end (neither branch wired). Branch-in-body needs a full branch
                // compiler; until then, condition is only available as a top-level node.
                { type: 'http_request', label: 'HTTP request' },
                { type: 'send_email', label: 'Send email' },
                { type: 'ai_invoke', label: 'AI invoke' },
                { type: 'result', label: 'Result' }
            ];
            function pbNodeDef(type) { return (window.__pbNodeDefs || []).filter(function (d) { return d.key === type; })[0] || null; }
            // Render one capability input as an editable control bound to config[key].
            function pbNodeFieldHtml(input, config) {
                var key = input.key, val = config[key], showIf = input.show_if || input.showIf;
                if (showIf && Object.keys(showIf).length && ! Object.keys(showIf).every(function (k) { return (showIf[k] || []).indexOf(config[k]) !== -1; })) { return ''; }
                var label = '<label class="ai-pb-node-label">' + pbEsc(input.label) + '</label>';
                if (input.type === 'actions') {
                    // Mount point for the low-code actions builder — wired after the
                    // card renders (in the node-step binding loop) so it edits
                    // step.config[key] directly. Same builder the top-level Result uses.
                    return label + '<div class="ai-pb-actions" data-cfg-actions="' + key + '"></div>';
                }
                if (input.type === 'select') {
                    var o = Object.keys(input.options || {}).map(function (k) { return '<option value="' + pbEsc(k) + '">' + pbEsc(input.options[k]) + '</option>'; }).join('');
                    return label + '<select data-cfg="' + key + '">' + o + '</select>';
                }
                if (input.type === 'collection') {
                    var co = (window.__pbCollections || []).map(function (c) { return '<option value="' + pbEsc(c.key) + '">' + pbEsc(c.name || c.key) + '</option>'; }).join('');
                    return label + '<select data-cfg="' + key + '">' + co + '</select>';
                }
                if (input.type === 'boolean') { return label + '<input type="checkbox" data-cfg="' + key + '"' + (val ? ' checked' : '') + ' />'; }
                if (input.type === 'number') { return label + '<input type="number" data-cfg="' + key + '" value="' + pbEsc(val) + '" />'; }
                if (input.type === 'json' || input.type === 'keyvalue') {
                    var jv = (val && typeof val === 'object') ? JSON.stringify(val) : (val || '');
                    return label + '<textarea data-cfg="' + key + '" data-json placeholder=\'{"key":"value"}\'>' + pbEsc(jv) + '</textarea>';
                }
                if (input.type === 'text' || input.type === 'code') { return label + '<textarea data-cfg="' + key + '">' + pbEsc(val) + '</textarea>'; }
                return label + '<input type="text" data-cfg="' + key + '" value="' + pbEsc(val) + '" />';
            }

            // Close any open step kebab menu when clicking elsewhere (bound once).
            if (! window.__pbStepMenuBound) {
                window.__pbStepMenuBound = true;
                document.addEventListener('click', function () {
                    document.querySelectorAll('.ai-pb-step-menu:not([hidden])').forEach(function (m) { m.setAttribute('hidden', ''); });
                    document.querySelectorAll('.ai-pb-step--menu-open').forEach(function (s) { s.classList.remove('ai-pb-step--menu-open'); });
                });
            }
            // Colored Phosphor icon for a step's selected kind (native <option> can't
            // hold SVG, so the icon lives beside the kind picker and updates on change).
            function stepKindIcon(step) {
                var map = window.__pbNodeIcons || {};
                if (step.kind === 'function') { return map['function'] || ''; }
                if (step.kind === 'flow') { return map['call_flow'] || ''; }
                if (step.kind === 'loop') { return map['loop'] || ''; }
                if (step.kind === 'node') { return map[step.type] || ''; }
                return '';
            }
            // Render an editable step list into `mount`, persisting to onChange(steps).
            function pbRenderStepList(mount, steps, onChange) {
                function commit() { onChange(steps); render(); }
                function render() {
                    mount.innerHTML = '';
                    steps.forEach(function (step, idx) {
                        var card = document.createElement('div');
                        card.className = 'ai-pb-step';
                        var head = '<div class="ai-pb-step-actions">'
                            + '<button type="button" class="ai-pb-step-kebab" data-menu title="Options">⋮</button>'
                            + '<div class="ai-pb-step-menu" data-menu-list hidden>'
                            +   '<button type="button" class="ai-pb-step-btn" data-up title="Move up">↑</button>'
                            +   '<button type="button" class="ai-pb-step-btn" data-down title="Move down">↓</button>'
                            +   '<button type="button" class="ai-pb-step-btn ai-pb-step-del" data-del title="Delete">🗑</button>'
                            + '</div>'
                            + '</div>'
                            + '<div class="ai-pb-step-head">'
                            + '<span class="ai-pb-step-num">' + (idx + 1) + '</span>'
                            + '<span class="ai-pb-step-icon">' + stepKindIcon(step) + '</span>'
                            + '<select class="ai-pb-step-kind">'
                            +   '<option value="function"' + (step.kind === 'function' ? ' selected' : '') + '>Function</option>'
                            +   '<option value="flow"' + (step.kind === 'flow' ? ' selected' : '') + '>Flow</option>'
                            +   '<option value="loop"' + (step.kind === 'loop' ? ' selected' : '') + '>Loop</option>'
                            +   PB_STEP_NODE_TYPES.map(function (nt) { return '<option value="node:' + nt.type + '"' + (step.kind === 'node' && step.type === nt.type ? ' selected' : '') + '>' + nt.label + '</option>'; }).join('')
                            + '</select>'
                            + '</div>';
                        var bodyHtml = '';
                        if (step.kind === 'function') {
                            // args (JSON) + output round-trip via pbCompileSteps/pbDecompileBody
                            // (which already carry s.args / s.output).
                            var fnArgs = (step.args && typeof step.args === 'object') ? JSON.stringify(step.args) : (step.args || '');
                            bodyHtml = '<label class="ai-pb-node-label">Function</label><select data-ref>' + pbFnOptions() + '</select>'
                                + '<label class="ai-pb-node-label">Args (JSON)</label><textarea data-args data-json placeholder=\'{"price":"{{vars.amount}}"}\'>' + pbEsc(fnArgs) + '</textarea>'
                                + '<label class="ai-pb-node-label">Output variable</label><input type="text" data-output placeholder="e.g. result" value="' + pbEsc(step.output || '') + '" />';
                        }
                        else if (step.kind === 'flow') {
                            bodyHtml = '<label class="ai-pb-node-label">Flow (runs with shared context)</label><select data-ref>' + pbFlowOptions() + '</select>'
                                + '<label class="ai-pb-node-label">Output variable</label><input type="text" data-output placeholder="e.g. sub_result" value="' + pbEsc(step.output || '') + '" />';
                        }
                        else if (step.kind === 'loop') {
                            bodyHtml = '<label class="ai-pb-node-label">For each item in</label><input type="text" data-over placeholder="input.cart_items" value="' + pbEsc(step.over || '') + '" />'
                                + '<label class="ai-pb-node-label">Item variable</label><input type="text" data-itemvar value="' + pbEsc(step.item_var || 'item') + '" />'
                                + '<label class="ai-pb-node-label">Index variable (optional)</label><input type="text" data-indexvar placeholder="index" value="' + pbEsc(step.index_var || '') + '" />'
                                + '<label class="ai-pb-node-label">Max iterations (optional)</label><input type="number" data-maxiter placeholder="e.g. 100" value="' + pbEsc(step.max_iterations != null ? step.max_iterations : '') + '" />'
                                + '<label class="ai-pb-node-label">Output variable (optional)</label><input type="text" data-loopoutput placeholder="e.g. loop_stats" value="' + pbEsc(step.output || '') + '" />'
                                + '<div class="ai-pb-step-nested" data-nested></div>'
                                + '<button type="button" class="ai-pb-action-add" data-addnested>+ Add step (per item)</button>';
                        } else { // 'node' — render its capability-schema fields
                            var def = pbNodeDef(step.type);
                            if (def && def.inputs && def.inputs.length) {
                                if (! step.config) { step.config = {}; }
                                bodyHtml = '<div class="ai-pb-step-fields">' + def.inputs.map(function (inp) { return pbNodeFieldHtml(inp, step.config); }).join('') + '</div>';
                            } else {
                                bodyHtml = '<div class="ai-pb-step-raw">' + pbEsc(step.type || 'node') + ' — no editable fields</div>';
                            }
                        }
                        card.innerHTML = head + bodyHtml;

                        card.querySelector('.ai-pb-step-kind').addEventListener('change', function (e) {
                            var k = e.target.value;
                            if (k === 'function') { steps[idx] = { kind: 'function', ref: '', args: {}, output: '' }; }
                            else if (k === 'flow') { steps[idx] = { kind: 'flow', ref: '', output: '' }; }
                            else if (k === 'loop') { steps[idx] = { kind: 'loop', over: '', item_var: 'item', index_var: '', steps: [] }; }
                            else if (k.indexOf('node:') === 0) { steps[idx] = { kind: 'node', type: k.slice(5), config: {} }; }
                            commit();
                        });
                        // Kebab (⋮) toggles the options menu; close others + on outside click.
                        var kebab = card.querySelector('[data-menu]'), menu = card.querySelector('[data-menu-list]');
                        kebab.addEventListener('click', function (e) {
                            e.stopPropagation();
                            var wasHidden = menu.hasAttribute('hidden');
                            document.querySelectorAll('.ai-pb-step-menu:not([hidden])').forEach(function (m) { m.setAttribute('hidden', ''); });
                            document.querySelectorAll('.ai-pb-step--menu-open').forEach(function (s) { s.classList.remove('ai-pb-step--menu-open'); });
                            if (wasHidden) { menu.removeAttribute('hidden'); card.classList.add('ai-pb-step--menu-open'); }
                            else { menu.setAttribute('hidden', ''); card.classList.remove('ai-pb-step--menu-open'); }
                        });
                        card.querySelector('[data-up]').addEventListener('click', function () { if (idx > 0) { var t = steps[idx - 1]; steps[idx - 1] = steps[idx]; steps[idx] = t; commit(); } });
                        card.querySelector('[data-down]').addEventListener('click', function () { if (idx < steps.length - 1) { var t = steps[idx + 1]; steps[idx + 1] = steps[idx]; steps[idx] = t; commit(); } });
                        card.querySelector('[data-del]').addEventListener('click', function () { steps.splice(idx, 1); commit(); });

                        var refSel = card.querySelector('[data-ref]');
                        if (refSel) { if (step.ref) { refSel.value = step.ref; } refSel.addEventListener('change', function () { step.ref = refSel.value; onChange(steps); }); }
                        // Function-step args (JSON) — parse to an object so pbCompileSteps
                        // stores it as structured config; fall back to the raw string.
                        var argsEl = card.querySelector('[data-args]');
                        if (argsEl) { argsEl.addEventListener('input', function () { try { step.args = JSON.parse(argsEl.value || 'null'); } catch (e) { step.args = argsEl.value; } onChange(steps); }); }
                        // Shared "Output variable" for function + flow steps.
                        var outEl = card.querySelector('[data-output]');
                        if (outEl) { outEl.addEventListener('input', function () { step.output = outEl.value; onChange(steps); }); }
                        // Node-step schema fields.
                        if (step.kind === 'node') {
                            var def2 = pbNodeDef(step.type);
                            card.querySelectorAll('[data-cfg]').forEach(function (ctl) {
                                var key = ctl.getAttribute('data-cfg');
                                if (ctl.tagName === 'SELECT' && step.config[key] != null) { ctl.value = step.config[key]; }
                                var evt = (ctl.tagName === 'SELECT' || ctl.type === 'checkbox') ? 'change' : 'input';
                                ctl.addEventListener(evt, function () {
                                    var v = ctl.type === 'checkbox' ? ctl.checked : ctl.value;
                                    if (ctl.hasAttribute('data-json')) { try { v = JSON.parse(ctl.value || 'null'); } catch (e) { v = ctl.value; } }
                                    step.config[key] = v;
                                    onChange(steps);
                                    // Re-render if another field's visibility depends on this one.
                                    var gates = def2 && def2.inputs && def2.inputs.some(function (i) { var sf = i.show_if || i.showIf; return sf && Object.keys(sf).indexOf(key) !== -1; });
                                    if (gates) { render(); }
                                });
                            });
                            // Mount the low-code actions builder for any 'actions' field
                            // (e.g. a Result step) — same builder the top-level Result
                            // node uses, persisting straight to step.config[key].
                            card.querySelectorAll('[data-cfg-actions]').forEach(function (mnt) {
                                var akey = mnt.getAttribute('data-cfg-actions');
                                if (! Array.isArray(step.config[akey])) { step.config[akey] = []; }
                                pbActionsBuilder(mnt, step.config[akey], function (updated) {
                                    step.config[akey] = updated;
                                    onChange(steps);
                                });
                            });
                        }
                        var over = card.querySelector('[data-over]'); if (over) { over.addEventListener('input', function () { step.over = over.value; onChange(steps); }); }
                        var iv = card.querySelector('[data-itemvar]'); if (iv) { iv.addEventListener('input', function () { step.item_var = iv.value; onChange(steps); }); }
                        var ixv = card.querySelector('[data-indexvar]'); if (ixv) { ixv.addEventListener('input', function () { step.index_var = ixv.value; onChange(steps); }); }
                        var mxi = card.querySelector('[data-maxiter]'); if (mxi) { mxi.addEventListener('input', function () { step.max_iterations = mxi.value === '' ? '' : parseInt(mxi.value, 10); onChange(steps); }); }
                        var lop = card.querySelector('[data-loopoutput]'); if (lop) { lop.addEventListener('input', function () { step.output = lop.value; onChange(steps); }); }
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
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#22C55E" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M128,32a96,96,0,1,0,96,96A96,96,0,0,0,128,32ZM108,168V88l64,40Z" opacity="0.2"/><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm48.24-94.78-64-40A8,8,0,0,0,100,88v80a8,8,0,0,0,12.24,6.78l64-40a8,8,0,0,0,0-13.56ZM116,153.57V102.43L156.91,128Z"/></svg> <span>Trigger</span></div>'
                            + '<span style="font-size:0.7rem;color:#94a3b8;">Flow entry point</span>'
                            + '</div>';

                    case 'ai_invoke':
                        return '<div class="ai-pb-node" data-node-type="ai_invoke">'
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#A78BFA" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M194.82,151.43l-55.09,20.3-20.3,55.09a7.92,7.92,0,0,1-14.86,0l-20.3-55.09-55.09-20.3a7.92,7.92,0,0,1,0-14.86l55.09-20.3,20.3-55.09a7.92,7.92,0,0,1,14.86,0l20.3,55.09,55.09,20.3A7.92,7.92,0,0,1,194.82,151.43Z" opacity="0.2"/><path d="M197.58,129.06,146,110l-19-51.62a15.92,15.92,0,0,0-29.88,0L78,110l-51.62,19a15.92,15.92,0,0,0,0,29.88L78,178l19,51.62a15.92,15.92,0,0,0,29.88,0L146,178l51.62-19a15.92,15.92,0,0,0,0-29.88ZM137,164.22a8,8,0,0,0-4.74,4.74L112,223.85,91.78,169A8,8,0,0,0,87,164.22L32.15,144,87,123.78A8,8,0,0,0,91.78,119L112,64.15,132.22,119a8,8,0,0,0,4.74,4.74L191.85,144ZM144,40a8,8,0,0,1,8-8h16V16a8,8,0,0,1,16,0V32h16a8,8,0,0,1,0,16H184V64a8,8,0,0,1-16,0V48H152A8,8,0,0,1,144,40ZM248,88a8,8,0,0,1-8,8h-8v8a8,8,0,0,1-16,0V96h-8a8,8,0,0,1,0-16h8V72a8,8,0,0,1,16,0v8h8A8,8,0,0,1,248,88Z"/></svg> <span>AI Invoke</span></div>'
                            + '<label class="ai-pb-node-label">Integration slug</label>'
                            + '<select df-integration>' + optionList(window.__pbIntegrations, 'slug', '— select integration —') + '</select>'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. ai_result" />'
                            + '<label class="ai-pb-node-label">Args (JSON)</label>'
                            + '<textarea df-args placeholder=\'{"prompt":"Hello"}\'></textarea>'
                            + '</div>';

                    case 'call_flow':
                        // Composes another saved flow inline. `flow` is an author-fixed
                        // slug (never interpolated — IDOR guard in CallFlowNode); the
                        // picker reuses pbFlowOptions() so the current flow is excluded.
                        return '<div class="ai-pb-node" data-node-type="call_flow">'
                            + '<div class="ai-pb-node-title">' + (window.__pbNodeIcons['call_flow'] || '') + ' <span>Run Flow</span></div>'
                            + '<label class="ai-pb-node-label">Flow (runs with shared context)</label>'
                            + '<select df-flow><option value="">— select flow —</option>' + pbFlowOptions() + '</select>'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. sub_result" />'
                            + '</div>';

                    case 'http_request':
                        return '<div class="ai-pb-node" data-node-type="http_request">'
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#38BDF8" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,128a96,96,0,1,1-96-96A96,96,0,0,1,224,128Z" opacity="0.2"/><path d="M128,24h0A104,104,0,1,0,232,128,104.12,104.12,0,0,0,128,24Zm88,104a87.61,87.61,0,0,1-3.33,24H174.16a157.44,157.44,0,0,0,0-48h38.51A87.61,87.61,0,0,1,216,128ZM102,168H154a115.11,115.11,0,0,1-26,45A115.27,115.27,0,0,1,102,168Zm-3.9-16a140.84,140.84,0,0,1,0-48h59.88a140.84,140.84,0,0,1,0,48ZM40,128a87.61,87.61,0,0,1,3.33-24H81.84a157.44,157.44,0,0,0,0,48H43.33A87.61,87.61,0,0,1,40,128ZM154,88H102a115.11,115.11,0,0,1,26-45A115.27,115.27,0,0,1,154,88Zm52.33,0H170.71a135.28,135.28,0,0,0-22.3-45.6A88.29,88.29,0,0,1,206.37,88ZM107.59,42.4A135.28,135.28,0,0,0,85.29,88H49.63A88.29,88.29,0,0,1,107.59,42.4ZM49.63,168H85.29a135.28,135.28,0,0,0,22.3,45.6A88.29,88.29,0,0,1,49.63,168Zm98.78,45.6a135.28,135.28,0,0,0,22.3-45.6h35.66A88.29,88.29,0,0,1,148.41,213.6Z"/></svg> <span>HTTP Request</span></div>'
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
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#F59E0B" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M200,40V200a16,16,0,0,1-16,16H56V56A16,16,0,0,1,72,40Z" opacity="0.2"/><path d="M208,40a8,8,0,0,1-8,8H170.71a24,24,0,0,0-23.62,19.71L137.59,120H184a8,8,0,0,1,0,16H134.68l-10,55.16A40,40,0,0,1,85.29,224H56a8,8,0,0,1,0-16H85.29a24,24,0,0,0,23.62-19.71l9.5-52.29H72a8,8,0,0,1,0-16h49.32l10-55.16A40,40,0,0,1,170.71,32H200A8,8,0,0,1,208,40Z"/></svg> <span>Function</span></div>'
                            + '<label class="ai-pb-node-label">Function</label>'
                            + '<select df-function>' + optionList(window.__pbFlowFunctions, 'slug', '— select function —') + '</select>'
                            + '<label class="ai-pb-node-label">Args (JSON)</label>'
                            + '<textarea df-args placeholder=\'{"price":"{{vars.amount}}"}\'></textarea>'
                            + '<label class="ai-pb-node-label">Output variable</label>'
                            + '<input type="text" df-output placeholder="e.g. result" />'
                            + '</div>';

                    case 'send_email':
                        return '<div class="ai-pb-node" data-node-type="send_email">'
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#34D399" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,56l-96,88L32,56Z" opacity="0.2"/><path d="M224,48H32a8,8,0,0,0-8,8V192a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A8,8,0,0,0,224,48Zm-96,85.15L52.57,64H203.43ZM98.71,128,40,181.81V74.19Zm11.84,10.85,12,11.05a8,8,0,0,0,10.82,0l12-11.05,58,53.15H52.57ZM157.29,128,216,74.18V181.82Z"/></svg> <span>Send Email</span></div>'
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
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#2DD4BF" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M216,80c0,26.51-39.4,48-88,48S40,106.51,40,80s39.4-48,88-48S216,53.49,216,80Z" opacity="0.2"/><path d="M128,24C74.17,24,32,48.6,32,80v96c0,31.4,42.17,56,96,56s96-24.6,96-56V80C224,48.6,181.83,24,128,24Zm80,104c0,9.62-7.88,19.43-21.61,26.92C170.93,163.35,150.19,168,128,168s-42.93-4.65-58.39-13.08C55.88,147.43,48,137.62,48,128V111.36c17.06,15,46.23,24.64,80,24.64s62.94-9.68,80-24.64ZM69.61,53.08C85.07,44.65,105.81,40,128,40s42.93,4.65,58.39,13.08C200.12,60.57,208,70.38,208,80s-7.88,19.43-21.61,26.92C170.93,115.35,150.19,120,128,120s-42.93-4.65-58.39-13.08C55.88,99.43,48,89.62,48,80S55.88,60.57,69.61,53.08ZM186.39,202.92C170.93,211.35,150.19,216,128,216s-42.93-4.65-58.39-13.08C55.88,195.43,48,185.62,48,176V159.36c17.06,15,46.23,24.64,80,24.64s62.94-9.68,80-24.64V176C208,185.62,200.12,195.43,186.39,202.92Z"/></svg> <span>Collection</span></div>'
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
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#E879F9" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M216,83.31V208a8,8,0,0,1-8,8H176V152a8,8,0,0,0-8-8H88a8,8,0,0,0-8,8v64H48a8,8,0,0,1-8-8V48a8,8,0,0,1,8-8H172.69a8,8,0,0,1,5.65,2.34l35.32,35.32A8,8,0,0,1,216,83.31Z" opacity="0.2"/><path d="M219.31,72,184,36.69A15.86,15.86,0,0,0,172.69,32H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V83.31A15.86,15.86,0,0,0,219.31,72ZM168,208H88V152h80Zm40,0H184V152a16,16,0,0,0-16-16H88a16,16,0,0,0-16,16v56H48V48H172.69L208,83.31ZM160,72a8,8,0,0,1-8,8H96a8,8,0,0,1,0-16h56A8,8,0,0,1,160,72Z"/></svg> <span>Set State</span></div>'
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
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#FBBF24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,64a24,24,0,1,1-24-24A24,24,0,0,1,224,64Z" opacity="0.2"/><path d="M232,64a32,32,0,1,0-40,31v17a8,8,0,0,1-8,8H96a23.84,23.84,0,0,0-8,1.38V95a32,32,0,1,0-16,0v66a32,32,0,1,0,16,0V144a8,8,0,0,1,8-8h88a24,24,0,0,0,24-24V95A32.06,32.06,0,0,0,232,64ZM64,64A16,16,0,1,1,80,80,16,16,0,0,1,64,64ZM96,192a16,16,0,1,1-16-16A16,16,0,0,1,96,192ZM200,80a16,16,0,1,1,16-16A16,16,0,0,1,200,80Z"/></svg> <span>Condition</span></div>'
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
                            + '<span style="color:#22c55e;">&#9679; True</span>'
                            + '<span style="color:#f87171;">&#9679; False</span>'
                            + '</div>'
                            + '</div>';

                    case 'result':
                        return '<div class="ai-pb-node" data-node-type="result">'
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#F472B6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M232,56l-45.71,96H40l48-48L40,56Z" opacity="0.2"/><path d="M238.76,51.73A8,8,0,0,0,232,48H40a8,8,0,0,0-5.66,13.66L76.69,104,34.34,146.34A8,8,0,0,0,40,160H173.62l-28.84,60.56a8,8,0,1,0,14.44,6.88l80-168A8,8,0,0,0,238.76,51.73ZM181.23,144H59.31l34.35-34.34a8,8,0,0,0,0-11.32L59.31,64h160Z"/></svg> <span>Result</span></div>'
                            + '<span style="font-size:0.7rem;color:#94a3b8;">What happens when the flow reaches here — add one or more actions.</span>'
                            + '<div class="ai-pb-actions" data-pb-actions-mount></div>'
                            + '<textarea df-actions style="display:none"></textarea>'
                            + '</div>';

                    case 'transaction':
                        return '<div class="ai-pb-node" data-node-type="transaction">'
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#22C55E" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M216,56v56c0,96-88,120-88,120S40,208,40,112V56a8,8,0,0,1,8-8H208A8,8,0,0,1,216,56Z" opacity="0.2"/><path d="M208,40H48A16,16,0,0,0,32,56v56c0,52.72,25.52,84.67,46.93,102.19,23.06,18.86,46,25.26,47,25.53a8,8,0,0,0,4.2,0c1-.27,23.91-6.67,47-25.53C198.48,196.67,224,164.72,224,112V56A16,16,0,0,0,208,40Zm0,72c0,37.07-13.66,67.16-40.6,89.42A129.3,129.3,0,0,1,128,223.62a128.25,128.25,0,0,1-38.92-21.81C61.82,179.51,48,149.3,48,112l0-56,160,0ZM82.34,141.66a8,8,0,0,1,11.32-11.32L112,148.69l50.34-50.35a8,8,0,0,1,11.32,11.32l-56,56a8,8,0,0,1-11.32,0Z"/></svg> <span>Transaction</span></div>'
                            + '<span style="font-size:0.7rem;color:#94a3b8;">Runs these steps atomically — all commit together, or all roll back on any error.</span>'
                            + '<label class="ai-pb-node-label">Steps (run in order)</label>'
                            + '<div class="ai-pb-steps" data-pb-steps-mount></div>'
                            + '<textarea df-body style="display:none"></textarea>'
                            + '<div style="display:flex;gap:1rem;font-size:0.67rem;margin-top:0.2rem;">'
                            + '<span style="color:#22c55e;">&#9679; Committed</span>'
                            + '<span style="color:#f87171;">&#9679; Rolled back</span>'
                            + '</div>'
                            + '</div>';

                    case 'loop':
                        return '<div class="ai-pb-node" data-node-type="loop">'
                            + '<div class="ai-pb-node-title"><svg style="width:18px;height:18px;vertical-align:middle;fill:#F43F5E" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,64v64a64,64,0,0,1-64,64H32V128A64,64,0,0,1,96,64Z" opacity="0.2"/><path d="M24,128A72.08,72.08,0,0,1,96,56H204.69L194.34,45.66a8,8,0,0,1,11.32-11.32l24,24a8,8,0,0,1,0,11.32l-24,24a8,8,0,0,1-11.32-11.32L204.69,72H96a56.06,56.06,0,0,0-56,56,8,8,0,0,1-16,0Zm200-8a8,8,0,0,0-8,8,56.06,56.06,0,0,1-56,56H51.31l10.35-10.34a8,8,0,0,0-11.32-11.32l-24,24a8,8,0,0,0,0,11.32l24,24a8,8,0,0,0,11.32-11.32L51.31,200H160a72.08,72.08,0,0,0,72-72A8,8,0,0,0,224,120Z"/></svg> <span>Loop</span></div>'
                            + '<label class="ai-pb-node-label">For each item in</label>'
                            + '<input type="text" df-over placeholder="input.cart_items" />'
                            + '<label class="ai-pb-node-label">Item variable</label>'
                            + '<input type="text" df-item_var placeholder="item" />'
                            + '<label class="ai-pb-node-label">Index variable (optional)</label>'
                            + '<input type="text" df-index_var placeholder="index" />'
                            + '<label class="ai-pb-node-label">Max iterations (optional)</label>'
                            + '<input type="number" df-max_iterations placeholder="e.g. 100" />'
                            + '<label class="ai-pb-node-label">Output variable (optional)</label>'
                            + '<input type="text" df-output placeholder="e.g. loop_stats" />'
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
                    case 'call_flow':
                        return { flow: config.flow || '', output: config.output || '' };
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
                        return { over: config.over || '', item_var: config.item_var || 'item', index_var: config.index_var || '', max_iterations: (config.max_iterations != null ? String(config.max_iterations) : ''), output: config.output || '', body: jsonStr(config.body) };
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
                        case 'call_flow':
                            // Preserve {flow, output} — without this the sub-flow slug
                            // is wiped on every save and the node no-ops at runtime.
                            config = {
                                flow: data.flow || '',
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
                            // Optional caps — only persist when set (LoopNode applies
                            // its own defaults otherwise).
                            if (data.max_iterations !== '' && data.max_iterations != null) {
                                var mi = parseInt(data.max_iterations, 10);
                                if (! isNaN(mi)) { config.max_iterations = mi; }
                            }
                            if (data.output) { config.output = data.output; }
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
                    // Expose node capability schemas so the step-list can render a
                    // record / set-state / etc. step with its real fields, not a label.
                    window.__pbNodeDefs = (config && config.nodeDefs) || [];
                    const start = () => {
                        if (! window.Drawflow) { return setTimeout(start, 50); }
                        this.init();
                    };
                    start();
                },

                toggleFullscreen() {
                    this.fullscreen = ! this.fullscreen;
                },

                // ── Canvas zoom ──
                zoomIn() { if (this.editor && this.editor.zoom_in) { this.editor.zoom_in(); } },
                zoomOut() { if (this.editor && this.editor.zoom_out) { this.editor.zoom_out(); } },
                zoomReset() { if (this.editor && this.editor.zoom_reset) { this.editor.zoom_reset(); } },
                // Fit all nodes into view: reset zoom, then scale down to fit the
                // bounding box of every node, and scroll to the top-left node.
                zoomFit() {
                    var editor = this.editor;
                    if (! editor) { return; }
                    try {
                        editor.zoom_reset();
                        var nodes = this.$refs.canvas.querySelectorAll('.drawflow-node');
                        if (! nodes.length) { return; }
                        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
                        nodes.forEach(function (n) {
                            var x = parseFloat(n.style.left) || 0, y = parseFloat(n.style.top) || 0;
                            minX = Math.min(minX, x); minY = Math.min(minY, y);
                            maxX = Math.max(maxX, x + n.offsetWidth); maxY = Math.max(maxY, y + n.offsetHeight);
                        });
                        var wrap = this.$refs.canvas.getBoundingClientRect();
                        var w = maxX - minX + 80, h = maxY - minY + 80;
                        var z = Math.min(1, Math.min(wrap.width / w, wrap.height / h));
                        if (z > 0 && z < 1) { editor.zoom = z; editor.zoom_refresh(); }
                        var prec = this.$refs.canvas.querySelector('.drawflow');
                        if (prec) { prec.scrollLeft = 0; prec.scrollTop = 0; }
                    } catch (e) {}
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
                            // Drawflow's import() throws ("Cannot convert undefined or
                            // null to object") on any node missing an `inputs`/`outputs`
                            // map — and a 0-input node like the trigger exports WITHOUT
                            // an `inputs` key. Normalise every node to carry both maps
                            // (+ data) so import never throws. Also self-heals flows
                            // saved before this fix, which otherwise reopened
                            // trigger-only and then overwrote the DB on the next save.
                            const cdata = existing._canvas.drawflow
                                && existing._canvas.drawflow.Home
                                && existing._canvas.drawflow.Home.data;
                            if (cdata) {
                                Object.values(cdata).forEach((nd) => {
                                    if (! nd || typeof nd !== 'object') { return; }
                                    if (! nd.inputs) { nd.inputs = {}; }
                                    if (! nd.outputs) { nd.outputs = {}; }
                                    if (! nd.data) { nd.data = {}; }
                                });
                            }
                            editor.import(existing._canvas);
                            // import() fires no node/connection events, so re-sync
                            // once to re-normalise the definition (recompute `start`,
                            // preserve transaction/loop bodies) — self-heals a flow
                            // saved by an older, lossy version of this editor.
                            this.sync();
                        } catch (e) {
                            console.error('ai-page-builder: flow canvas import failed, falling back to trigger', e);
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
                        } catch (e) {
                            console.error('ai-page-builder: flow reconstruct failed, falling back to trigger', e);
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
