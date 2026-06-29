{{-- Loads GrapesJS once per panel page AND registers the editor's Alpine
     component. This is injected into the panel LAYOUT via a render hook
     (panels::body.end) — not the field's component view — because Livewire does
     not reliably compile/run scripts (@script / <script>) inside a sub-component
     view. The field view only carries the markup + x-data config call. --}}
@once
    <link rel="stylesheet" href="{{ config('ai-page-builder.assets.grapesjs_css') }}">
    <script src="{{ config('ai-page-builder.assets.grapesjs_js') }}"></script>
    @php
        // Inject flows + pages so component interaction traits offer dropdowns
        // (never free-typed slugs). Guarded — tables may be absent during boot.
        try {
            $pbFlows = (config('ai-page-builder.models.flow', \Andre\AiPageBuilder\Models\Flow::class))::query()
                ->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($f) => ['slug' => $f->slug, 'name' => $f->name])->values()->all();
        } catch (\Throwable $e) {
            $pbFlows = [];
        }
        try {
            $pbPages = (config('ai-page-builder.models.page', \Andre\AiPageBuilder\Models\Page::class))::query()
                ->orderBy('title')->get()
                ->map(fn ($p) => ['slug' => $p->slug, 'title' => $p->title])->values()->all();
        } catch (\Throwable $e) {
            $pbPages = [];
        }
        try {
            $pbStates = (config('ai-page-builder.models.variable', \Andre\AiPageBuilder\Models\Variable::class))::query()
                ->orderBy('key')->get()->map(fn ($v) => ['key' => $v->key, 'type' => $v->type])->values()->all();
        } catch (\Throwable $e) {
            $pbStates = [];
        }
        // Collections feed the form "create record in" trait (key => name).
        try {
            $pbCollections = (config('ai-page-builder.models.model', \Andre\AiPageBuilder\Models\PbModel::class))::query()
                ->orderBy('name')->get()->map(fn ($m) => ['key' => $m->key, 'name' => $m->name])->values()->all();
        } catch (\Throwable $e) {
            $pbCollections = [];
        }
        // Per-collection field lists feed the dependent field-name selects
        // (chart/kpi metric field + group-by, autocomplete label field). Shape:
        // { "<collection key>": [ { name, column, label, type }, … ], … }.
        //   name   — the field key (REST row key / relation attribute name)
        //   column — the physical DB column (what aggregate validates against;
        //            == key for everything except Relation, which is key_id)
        // The selects offer `column` as the value for chart/kpi (aggregation
        // matches real columns) and `name` for the autocomplete label field
        // (the typeahead indexes the REST row by attribute name).
        try {
            $pbModelClass = config('ai-page-builder.models.model', \Andre\AiPageBuilder\Models\PbModel::class);
            $pbSchemaConn = \Illuminate\Support\Facades\Schema::connection(\Andre\AiPageBuilder\Support\Schema::connection());
            $pbFields = [];
            if ($pbSchemaConn->hasTable(\Andre\AiPageBuilder\Support\Schema::table('fields'))
                && $pbSchemaConn->hasTable(\Andre\AiPageBuilder\Support\Schema::table('models'))) {
                foreach ($pbModelClass::query()->with('fields')->get() as $pbModel) {
                    $pbFields[$pbModel->key] = $pbModel->fields->map(fn ($f) => [
                        'name' => $f->key,
                        'column' => $f->columnName(),
                        'label' => $f->label,
                        'type' => $f->type,
                    ])->values()->all();
                }
            }
        } catch (\Throwable $e) {
            $pbFields = [];
        }
        // Reusable partials → draggable blocks that insert a data-pb-partial placeholder.
        try {
            $pbPartials = (config('ai-page-builder.models.partial', \Andre\AiPageBuilder\Models\Partial::class))::query()
                ->orderBy('name')->get()->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name])->values()->all();
        } catch (\Throwable $e) {
            $pbPartials = [];
        }
    @endphp
    <script>
        window.__pbFlows = @js($pbFlows);
        window.__pbPages = @js($pbPages);
        window.__pbStates = @js($pbStates);
        window.__pbCollections = @js($pbCollections);
        window.__pbFields = @js($pbFields);
        window.__pbPartials = @js($pbPartials);
        window.__pbThemeCss = @js(app(\Andre\AiPageBuilder\Services\Theme::class)->css());
    </script>
    <style>
        /* CSS-based maximize (native fullscreen is unreliable inside the panel). */
        .pb-maximized { position: fixed !important; inset: 0 !important; z-index: 9999 !important; width: 100vw !important; height: 100vh !important; margin: 0 !important; border-radius: 0 !important; }
        .pb-maximized .pb-editor-body { height: 100vh !important; }
        /* Centre each block's icon + label in the palette. */
        .gjs-block { display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; text-align: center !important; gap: 0.35rem; }
        .gjs-block__media { display: flex !important; align-items: center; justify-content: center; margin: 0 auto !important; }
        .gjs-block svg { display: block; margin: 0 auto; }
        .gjs-block-label { width: 100%; text-align: center; }
    </style>
    <script>
        (function () {
            // --- Alpine ⇄ GrapesJS attribute bridge -------------------------
            // GrapesJS renders components into a real-DOM canvas, and the DOM
            // rejects `@click` / `:class` (and friends) — `setAttribute('@click')`
            // throws InvalidCharacterError, which aborts the import and leaves the
            // page unstyled (white canvas). So on the way IN we rename Alpine's
            // `@`/`:`-prefixed attributes to harmless `data-pbat-*` / `data-pbcolon-*`
            // placeholders the canvas accepts, and on the way OUT (getHtml → the
            // PUBLISHED snapshot) we restore the real Alpine directives — so editing
            // never corrupts the page's interactivity. Plain `x-data`/`x-show`/etc.
            // are valid attribute names and pass through untouched.
            const pbToSafe = (html) => (html || '')
                .replace(/(\s)@([\w.:-]+)=/g, '$1data-pbat-$2=')
                .replace(/(\s):([\w.-]+)=/g, '$1data-pbcolon-$2=');
            const pbToAlpine = (html) => (html || '')
                .replace(/(\s)data-pbat-([\w.:-]+)=/g, '$1@$2=')
                .replace(/(\s)data-pbcolon-([\w.-]+)=/g, '$1:$2=');

            // --- Page "frame" CSS preservation ------------------------------
            // GrapesJS imports component styles but DROPS page-frame rules
            // (`:root` custom properties, `html`/`body`/`*` base rules,
            // `@font-face`, `@keyframes`). For hand-authored pages whose design
            // tokens + background live there, the canvas goes white AND saving
            // would silently strip them from the published page. So we extract
            // those rules once, inject them into the canvas for a faithful
            // preview, and re-prepend them to getCss() on save so the round-trip
            // is lossless. (Brace-aware so nested @keyframes survive.)
            const pbFrameCss = (css) => {
                css = (css || '').replace(/\/\*[\s\S]*?\*\//g, '');
                const out = [];
                let i = 0; const n = css.length;
                while (i < n) {
                    while (i < n && /\s/.test(css[i])) i++;
                    if (i >= n) break;
                    let j = i;
                    while (j < n && css[j] !== '{' && css[j] !== ';') j++;
                    const prelude = css.slice(i, j).trim();
                    if (css[j] === ';') { i = j + 1; continue; } // @import / bare ; — skip
                    let depth = 0; let k = j;
                    for (; k < n; k++) { if (css[k] === '{') depth++; else if (css[k] === '}') { depth--; if (depth === 0) { k++; break; } } }
                    const block = css.slice(i, k);
                    // Keep: @font-face/@keyframes; page-frame selectors; AND any
                    // rule that DECLARES a custom property (`--x: …`) — GrapesJS
                    // strips those declarations on import, so re-supplying them is
                    // what makes `var(--token)` resolve again (background, colors).
                    const keep = prelude[0] === '@'
                        ? /^@(font-face|(-webkit-)?keyframes)\b/i.test(prelude)
                        : (/(^|,)\s*(:root|html|body|\*)\s*(,|$)/i.test(prelude) || /--[\w-]+\s*:/.test(block));
                    if (keep) out.push(block.trim());
                    i = k;
                }
                return out.join('\n');
            };

            const factory = (config) => ({
                editor: null,

                boot() {
                    const start = () => {
                        if (! window.grapesjs) { return setTimeout(start, 50); }
                        this.init();
                    };
                    start();
                },

                readState(key) {
                    const all = this.$wire.get(config.statePath) || {};
                    return all[key];
                },

                writeState(patch) {
                    const all = this.$wire.get(config.statePath) || {};
                    this.$wire.set(config.statePath, { ...all, ...patch }, false); // deferred
                },

                init() {
                    if (this.editor) { return; }
                    this.$refs.blocks.innerHTML = '';
                    this.$refs.canvas.innerHTML = '';

                    const assetManager = {
                        assets: config.assets || [],
                        uploadName: 'files',
                        autoAdd: true,
                    };
                    if (config.uploadUrl) {
                        assetManager.upload = config.uploadUrl;
                        assetManager.headers = { 'X-CSRF-TOKEN': config.csrf };
                    }

                    const editor = window.grapesjs.init({
                        container: this.$refs.canvas,
                        height: '100%',
                        width: 'auto',
                        fromElement: false,
                        storageManager: false,
                        blockManager: { appendTo: this.$refs.blocks },
                        assetManager,
                        // Responsive breakpoints — the Device dropdown switches these
                        // and per-device edits export proper @media rules in getCss().
                        deviceManager: {
                            devices: [
                                { name: 'Desktop', width: '' },
                                { name: 'Tablet', width: '768px', widthMedia: '992px' },
                                { name: 'Mobile', width: '375px', widthMedia: '576px' },
                            ],
                        },
                        // A pragmatic style manager. The Background sector's
                        // background-image is a `file` property → clicking it opens
                        // the media picker, so any selected component can take a CSS
                        // background image (and gradients/colour) just like in CSS.
                        styleManager: {
                            sectors: [
                                { name: 'Layout', open: false, properties: ['display', 'width', 'height', 'max-width', 'margin', 'padding'] },
                                { name: 'Typography', open: false, properties: ['color', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align'] },
                                { name: 'Background', open: false, properties: [
                                    { property: 'background-color', type: 'color' },
                                    { property: 'background-image', type: 'file' },
                                    { property: 'background-size', type: 'select', defaults: 'auto', options: [{ value: 'auto' }, { value: 'cover' }, { value: 'contain' }] },
                                    { property: 'background-position', type: 'text' },
                                    { property: 'background-repeat', type: 'select', defaults: 'no-repeat', options: [{ value: 'no-repeat' }, { value: 'repeat' }, { value: 'repeat-x' }, { value: 'repeat-y' }] },
                                    // Colour overlay on a section (sits above the background, below content).
                                    { property: '--pb-overlay', type: 'color', label: 'Overlay' },
                                ] },
                                { name: 'Border', open: false, properties: ['border-radius', 'border', 'box-shadow'] },
                                { name: 'Extra', open: false, properties: ['opacity'] },
                            ],
                        },
                    });
                    this.editor = editor;
                    const rootEl = this.$el;

                    config.blocks.forEach((b) => {
                        // The 'image' block is GrapesJS's native image component so it
                        // hooks into the Asset Manager (pick/upload) rather than dropping
                        // a fixed external placeholder.
                        const content = b.key === 'image' ? { type: 'image' } : b.template;
                        editor.BlockManager.add(b.key, {
                            label: b.label,
                            category: b.category,
                            content,
                            media: b.icon || undefined,
                        });
                        if (b.key !== 'image') {
                            editor.DomComponents.addType(b.key, {
                                isComponent: (el) => el.getAttribute && el.getAttribute('data-pb-block') === b.key,
                                model: { defaults: { name: b.label } },
                            });
                        }
                    });

                    // Reusable partials → blocks that drop a data-pb-partial
                    // placeholder. The page stores ONLY the placeholder; the
                    // renderer expands it to the partial's current html, so editing
                    // the partial updates every page. The placeholder's label is
                    // editor-only (the renderer replaces the whole element).
                    (window.__pbPartials || []).forEach((p) => {
                        const ph = '<div data-pb-partial="' + p.slug + '" class="pb-partial-ph" style="padding:1rem 1.25rem;border:1px dashed #94a3b8;border-radius:.5rem;color:#64748b;text-align:center;font:600 14px/1.4 ui-sans-serif,system-ui,sans-serif;">▦ ' + (p.name || p.slug) + '</div>';
                        editor.BlockManager.add('partial:' + p.slug, {
                            label: p.name || p.slug,
                            category: 'Partials',
                            content: ph,
                        });
                    });
                    editor.DomComponents.addType('pb-partial', {
                        isComponent: (el) => el.getAttribute && el.getAttribute('data-pb-partial'),
                        model: { defaults: { name: 'Partial', draggable: true, droppable: false } },
                    });

                    // Open the media picker as soon as an empty image is dropped. The
                    // native image component also opens it on double-click; this just
                    // makes the first pick immediate. Picking sets the component src.
                    const pickImage = (cmp) => {
                        try {
                            editor.AssetManager.open({
                                types: ['image'],
                                select(asset) {
                                    const src = (asset && asset.get) ? asset.get('src') : ((asset && asset.src) || '');
                                    if (src) {
                                        cmp.set('src', src);
                                        if (cmp.addAttributes) { cmp.addAttributes({ src }); }
                                    }
                                    editor.AssetManager.close();
                                },
                            });
                        } catch (e) { /* no-op; double-click still opens it */ }
                    };
                    editor.on('component:add', (cmp) => {
                        if (cmp && cmp.is && cmp.is('image') && ! (cmp.getAttributes() || {}).src) {
                            pickImage(cmp);
                        }
                    });

                    // "Set background image" — opens the media picker and applies the
                    // pick as a CSS background on the selected component (cover/center).
                    editor.Commands.add('pb-set-bg-image', {
                        run(ed) {
                            const cmp = ed.getSelected();
                            if (! cmp) { return; }
                            ed.AssetManager.open({
                                types: ['image'],
                                select(asset) {
                                    const src = (asset && asset.get) ? asset.get('src') : ((asset && asset.src) || '');
                                    if (src) {
                                        cmp.addStyle({
                                            'background-image': "url('" + src + "')",
                                            'background-size': 'cover',
                                            'background-position': 'center',
                                            'background-repeat': 'no-repeat',
                                        });
                                    }
                                    ed.AssetManager.close();
                                },
                            });
                        },
                    });
                    editor.Panels.addButton('options', {
                        id: 'pb-bg-image',
                        command: 'pb-set-bg-image',
                        label: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>',
                        attributes: { title: 'Set background image on the selected element' },
                    });

                    // CSS-based maximize so the canvas actually fills the screen
                    // (native fullscreen is unreliable inside the Filament panel).
                    const maximize = {
                        run(ed) { rootEl.classList.add('pb-maximized'); setTimeout(() => ed.refresh(), 0); },
                        stop(ed) { rootEl.classList.remove('pb-maximized'); setTimeout(() => ed.refresh(), 0); },
                    };
                    editor.Commands.add('fullscreen', maximize);
                    editor.Commands.add('core:fullscreen', maximize);

                    // Preview section colour overlays live in the canvas (the
                    // --pb-overlay style property paints an ::after layer).
                    editor.on('load', () => {
                        try {
                            const doc = editor.Canvas.getDocument();
                            // Global theme tokens first (base :root vars) so the
                            // canvas previews with the site's brand — pages bind to var(--pb-*).
                            if (window.__pbThemeCss) {
                                const ts = doc.createElement('style');
                                ts.id = 'pb-theme';
                                ts.innerHTML = window.__pbThemeCss;
                                doc.head.insertBefore(ts, doc.head.firstChild);
                            }
                            // Page-frame CSS (tokens/fonts/base bg) LAST, so it wins
                            // over GrapesJS's mangled copies of the same rules (it
                            // strips custom props + breaks var() backgrounds). The
                            // frame holds only token-declaring + base/at-rules, never
                            // component rules — so per-component Style Manager edits
                            // still take effect.
                            if (this.frameCss) {
                                const fs = doc.createElement('style');
                                fs.id = 'pb-page-frame';
                                fs.innerHTML = this.frameCss;
                                doc.head.appendChild(fs);
                            }
                            const s = doc.createElement('style');
                            s.innerHTML = '[x-cloak]{display:none !important}[data-pb-block]{position:relative}[data-pb-block]::after{content:"";position:absolute;inset:0;background:var(--pb-overlay,transparent);pointer-events:none;z-index:0}[data-pb-block]>*{position:relative;z-index:1}';
                            doc.head.appendChild(s);
                        } catch (e) { /* no-op */ }
                    });

                    // Entrance-animation trait, offered on every selected component
                    // (sets data-pb-anim; the rendered page animates it on scroll).
                    const PB_ANIMS = [['', 'None'], ['fade', 'Fade'], ['fade-up', 'Fade up'], ['fade-down', 'Fade down'], ['fade-left', 'Fade left'], ['fade-right', 'Fade right'], ['zoom-in', 'Zoom in']];
                    // DOM events a component can trigger a flow on.
                    const PB_EVENTS = [['click', 'Click'], ['dblclick', 'Double click'], ['mouseenter', 'Mouse enter (hover)'], ['mouseleave', 'Mouse leave'], ['mouseover', 'Mouse over'], ['focus', 'Focus'], ['blur', 'Blur'], ['keydown', 'Key down'], ['keyup', 'Key up'], ['change', 'Change'], ['input', 'Input'], ['submit', 'Submit']];
                    const addAnimTraits = (cmp) => {
                        const names = cmp.getTraits().map((t) => t.get('name'));
                        if (! names.includes('data-pb-anim')) {
                            cmp.addTrait({ type: 'select', name: 'data-pb-anim', label: 'Animation', options: PB_ANIMS.map(([id, name]) => ({ id, name })) });
                            cmp.addTrait({ type: 'text', name: 'data-pb-anim-delay', label: 'Anim delay (ms)', placeholder: '0' });
                        }
                        // Interaction: run a flow on a chosen DOM event (the
                        // published-page runtime reads data-pb-flow + the event and
                        // POSTs to the flow run endpoint), and/or link to a page.
                        // Flows and pages are dropdowns — never free-typed slugs.
                        if (! names.includes('data-pb-flow')) {
                            const flowOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbFlows || []).map((f) => ({ id: f.slug, name: f.name + ' (' + f.slug + ')' }))
                            );
                            const pageOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbPages || []).map((p) => ({ id: p.slug, name: p.title + ' (' + p.slug + ')' }))
                            );
                            cmp.addTrait({ type: 'select', name: 'data-pb-flow', label: 'Run flow', options: flowOptions });
                            cmp.addTrait({
                                type: 'select',
                                name: 'data-pb-flow-event',
                                label: 'On event',
                                options: PB_EVENTS.map(([id, name]) => ({ id, name })),
                            });
                            cmp.addTrait({ type: 'select', name: 'data-pb-page', label: 'Link to page', options: pageOptions });
                            // Log the end-user out on click (ends the pb session,
                            // returns to the login page) — works on any element.
                            cmp.addTrait({
                                type: 'select',
                                name: 'data-pb-logout',
                                label: 'Log out on click',
                                options: [{ id: '', name: 'No' }, { id: '1', name: 'Yes — sign out' }],
                            });
                        }

                        // Data binding: connect this component to a value in the
                        // reactive Store. Emits the SAFE Alpine directives
                        // (x-text/x-show/x-model) referencing $store.app.<key> —
                        // no executable directives. Updated live by flows (setState).
                        if (! names.includes('x-text')) {
                            const stateOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbStates || []).map((s) => ({ id: '$store.app.' + s.key, name: s.key + ' · ' + (s.type || 'string') }))
                            );
                            cmp.addTrait({ type: 'select', name: 'x-text', label: 'Bind text → State', options: stateOptions });
                            cmp.addTrait({ type: 'select', name: 'x-show', label: 'Show when (State)', options: stateOptions });
                            cmp.addTrait({ type: 'select', name: 'x-model', label: 'Two-way input ↔ State', options: stateOptions });
                        }

                        // Form submit → create record. Populated from the
                        // collections (key => name). The published-page runtime
                        // reads data-pb-record off a <form> and POSTs the fields
                        // to the auto REST API. (Flow-on-submit already works via
                        // the Run flow + On event=submit traits above.)
                        if (! names.includes('data-pb-record')) {
                            const collectionOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbCollections || []).map((c) => ({ id: c.key, name: c.name + ' (' + c.key + ')' }))
                            );
                            cmp.addTrait({ type: 'select', name: 'data-pb-record', label: 'On submit → create record in', options: collectionOptions });
                        }

                        // Data Table — "Collection" select (from __pbCollections).
                        // The table binds rows via x-data="pbTable('<key>')"; the
                        // trait isn't a real attribute (changeProp:true) — its
                        // handler rewrites the x-data expression so the published
                        // page fetches the chosen collection. Seeds from the
                        // current x-data so re-selecting reflects the live value.
                        if (cmp.getAttributes()['data-pb-block'] === 'data_table' && ! names.includes('pb-collection')) {
                            const collectionOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbCollections || []).map((c) => ({ id: c.key, name: c.name + ' (' + c.key + ')' }))
                            );
                            const xdata = cmp.getAttributes()['x-data'] || '';
                            const m = xdata.match(/pbTable\(\s*['"]([^'"]*)['"]\s*\)/);
                            cmp.set('pb-collection', m ? m[1] : '');
                            cmp.addTrait({ type: 'select', name: 'pb-collection', label: 'Collection', changeProp: true, options: collectionOptions });
                            cmp.on('change:pb-collection', () => {
                                const key = cmp.get('pb-collection') || '';
                                cmp.addAttributes({ 'x-data': "pbTable('" + key + "')" });
                            });
                        }

                        // List — "List source (State)" select (from __pbStates).
                        // The list's child <template> repeats over a $store.app
                        // array via x-for. The trait (changeProp:true) rewrites
                        // that template's x-for to the chosen State key.
                        if (cmp.getAttributes()['data-pb-block'] === 'list' && ! names.includes('pb-list-source')) {
                            const stateArrayOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbStates || [])
                                    .filter((s) => ! s.type || s.type === 'array' || s.type === 'json')
                                    .map((s) => ({ id: s.key, name: s.key + ' · ' + (s.type || 'array') }))
                            );
                            const tplFor = cmp.find('template')[0];
                            const cur = tplFor ? (tplFor.getAttributes()['x-for'] || '') : '';
                            const sm = cur.match(/\$store\.app\.([A-Za-z0-9_]+)/);
                            cmp.set('pb-list-source', sm ? sm[1] : '');
                            cmp.addTrait({ type: 'select', name: 'pb-list-source', label: 'List source (State)', changeProp: true, options: stateArrayOptions });
                            cmp.on('change:pb-list-source', () => {
                                const key = cmp.get('pb-list-source') || 'items';
                                const tpl = cmp.find('template')[0];
                                if (tpl) { tpl.addAttributes({ 'x-for': 'item in $store.app.' + key }); }
                            });
                        }

                        // Field-name options for a collection's dependent selects.
                        // `value` keys the persisted attribute: 'column' (real DB
                        // column — what chart/kpi aggregation validates against) or
                        // 'name' (the field key / REST-row attribute — what the
                        // autocomplete label field indexes). `numericOnly` keeps just
                        // sum/avg/min/max-capable fields for the metric field; others
                        // (group-by, label) list all. Returns an option array with a
                        // leading empty entry, so an unset/unknown collection shows
                        // just the placeholder.
                        const PB_NUMERIC_TYPES = ['number', 'integer', 'int', 'float', 'decimal', 'currency'];
                        const pbFieldOptions = (collectionKey, value, opts) => {
                            const o = opts || {};
                            const empty = { id: '', name: o.emptyLabel || '— select field —' };
                            const list = (window.__pbFields && window.__pbFields[collectionKey]) || [];
                            return [empty].concat(
                                list
                                    .filter((f) => ! o.numericOnly || PB_NUMERIC_TYPES.indexOf(String(f.type || '').toLowerCase()) !== -1)
                                    .map((f) => ({ id: f[value] || f.name, name: (f.label || f.name) + ' (' + (f[value] || f.name) + ')' }))
                            );
                        };

                        // Rebuild a real-attribute select trait in place with a fresh
                        // option list (used when a dependent select's parent changes).
                        // Removes then re-adds at the same index so the panel keeps its
                        // order; the persisted attribute value is untouched, so GrapesJS
                        // re-selects it when it still exists among the new options (the
                        // <select> falls back to the empty option otherwise).
                        const pbReaddSelect = (component, traitName, label, options) => {
                            const traits = component.getTraits();
                            const idx = traits.findIndex((t) => t.get('name') === traitName);
                            if (idx === -1) { return; }
                            component.removeTrait(traitName);
                            component.addTrait({ type: 'select', name: traitName, label: label, options: options }, { at: idx });
                        };

                        // Chart / KPI — bind to a collection + aggregation. These are
                        // plain data-pb-* attributes (persisted in html) read by the
                        // published runtime, so no changeProp rewrite is needed. The
                        // metric field + group-by are dependent selects driven by the
                        // chosen collection's real fields (re-populated when the
                        // collection changes — see change:attributes below).
                        const pbBlock = cmp.getAttributes()['data-pb-block'];
                        if ((pbBlock === 'chart' || pbBlock === 'kpi') && ! names.includes('data-pb-collection')) {
                            const collectionOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbCollections || []).map((c) => ({ id: c.key, name: c.name + ' (' + c.key + ')' }))
                            );
                            const curCollection = cmp.getAttributes()['data-pb-collection'] || '';
                            cmp.addTrait({ type: 'select', name: 'data-pb-collection', label: 'Collection', options: collectionOptions });
                            cmp.addTrait({ type: 'select', name: 'data-pb-metric', label: 'Metric', options: ['count', 'sum', 'avg', 'min', 'max'].map((m) => ({ id: m, name: m })) });
                            cmp.addTrait({ type: 'select', name: 'data-pb-field', label: 'Field (sum/avg/min/max)', options: pbFieldOptions(curCollection, 'column', { numericOnly: true }) });
                            if (pbBlock === 'chart') {
                                cmp.addTrait({ type: 'select', name: 'data-pb-group', label: 'Group by (field)', options: pbFieldOptions(curCollection, 'column') });
                                cmp.addTrait({ type: 'select', name: 'data-pb-date-bucket', label: 'Date bucket', options: [{ id: '', name: '— none —' }, { id: 'day', name: 'Day' }, { id: 'week', name: 'Week' }, { id: 'month', name: 'Month' }, { id: 'year', name: 'Year' }] });
                                cmp.addTrait({ type: 'select', name: 'data-pb-chart-type', label: 'Chart type', options: ['bar', 'line', 'area', 'donut', 'pie'].map((t) => ({ id: t, name: t })) });
                            }
                            // Re-populate the dependent field selects when the
                            // collection changes. data-pb-collection is a real
                            // attribute trait; this GrapesJS build emits only the
                            // generic `change:attributes` (no per-key event), so we
                            // listen to that and act only when the collection value
                            // actually changed — guarding against unrelated attribute
                            // edits and against the re-add itself re-triggering. The
                            // trait is rebuilt with options for the new collection;
                            // the persisted value is untouched, so GrapesJS re-selects
                            // it when still present (else falls back to the empty option).
                            let pbLastCollection = curCollection;
                            cmp.on('change:attributes', () => {
                                const col = cmp.getAttributes()['data-pb-collection'] || '';
                                if (col === pbLastCollection) { return; }
                                pbLastCollection = col;
                                pbReaddSelect(cmp, 'data-pb-field', 'Field (sum/avg/min/max)', pbFieldOptions(col, 'column', { numericOnly: true }));
                                if (pbBlock === 'chart') {
                                    pbReaddSelect(cmp, 'data-pb-group', 'Group by (field)', pbFieldOptions(col, 'column'));
                                }
                            });
                        }

                        // Embed — the iframe URL (set as an attribute, not inlined).
                        if (pbBlock === 'embed' && ! names.includes('data-pb-embed-url')) {
                            cmp.addTrait({ type: 'text', name: 'data-pb-embed-url', label: 'Embed URL (YouTube, Vimeo, Maps, any page)' });
                        }

                        // Autocomplete — bind the typeahead to a collection + label
                        // field. The label field is a dependent select over the
                        // collection's real fields; the typeahead indexes the REST
                        // row by attribute name, so its value is the field `name`
                        // (key), not the physical column. Re-populated on collection
                        // change like the chart/kpi field selects above.
                        if (pbBlock === 'autocomplete' && ! names.includes('data-pb-collection')) {
                            const acCollections = [{ id: '', name: '— none —' }].concat(
                                (window.__pbCollections || []).map((c) => ({ id: c.key, name: c.name + ' (' + c.key + ')' }))
                            );
                            const acCollection = cmp.getAttributes()['data-pb-collection'] || '';
                            cmp.addTrait({ type: 'select', name: 'data-pb-collection', label: 'Collection', options: acCollections });
                            cmp.addTrait({ type: 'select', name: 'data-pb-label-field', label: 'Label field', options: pbFieldOptions(acCollection, 'name', { emptyLabel: '— name (default) —' }) });
                            // Re-populate the label-field select when the collection
                            // changes (generic change:attributes + value guard — this
                            // GrapesJS build emits no per-key attribute event).
                            let acLastCollection = acCollection;
                            cmp.on('change:attributes', () => {
                                const col = cmp.getAttributes()['data-pb-collection'] || '';
                                if (col === acLastCollection) { return; }
                                acLastCollection = col;
                                pbReaddSelect(cmp, 'data-pb-label-field', 'Label field', pbFieldOptions(col, 'name', { emptyLabel: '— name (default) —' }));
                            });
                        }
                    };

                    // Load canonical GrapesJS state if present; otherwise fall
                    // back to importing the stored HTML (pages created by a seed,
                    // an import, or AI have html but no project_data yet).
                    // Capture page-frame CSS (design tokens, fonts, base bg) from
                    // the stored html's inline <style> blocks + the css column,
                    // BEFORE GrapesJS parses and drops them. Reused for the canvas
                    // preview and to keep the published snapshot lossless on save.
                    const rawHtml = this.readState('html') || '';
                    const styleBlocks = [];
                    rawHtml.replace(/<style[^>]*>([\s\S]*?)<\/style>/gi, (m, c) => { styleBlocks.push(c); return m; });
                    this.frameCss = pbFrameCss(styleBlocks.join('\n') + '\n' + (this.readState('css') || ''));

                    const existing = this.readState('project_data');
                    if (existing && Object.keys(existing).length) {
                        try { editor.loadProjectData(existing); } catch (e) { /* ignore malformed */ }
                    } else {
                        const html = this.readState('html');
                        const css = this.readState('css');
                        if (html) { editor.setComponents(pbToSafe(html)); }
                        if (css) { editor.setStyle(css); }
                    }

                    const sync = () => this.writeState({
                        project_data: editor.getProjectData(),
                        html: pbToAlpine(editor.getHtml()), // restore real Alpine for the published snapshot
                        // Append the captured frame CSS (tokens/fonts/base bg) that
                        // GrapesJS drops, so the published page keeps its design
                        // tokens. Frame goes LAST so it wins over GrapesJS's mangled
                        // copies; it holds no component rules, so component edits
                        // (earlier, in getCss) are preserved.
                        css: this.frameCss ? (editor.getCss() + '\n' + this.frameCss) : editor.getCss(),
                    });
                    // Debounce so rapid edits (typing, dragging) don't spam $wire.
                    let syncT = null;
                    const syncSoon = () => { clearTimeout(syncT); syncT = setTimeout(sync, 250); };
                    // `update` is the catch-all for COMPONENT changes, but Style
                    // Manager / CSS-rule edits (e.g. a background colour) do NOT
                    // reliably fire it — so the css never synced and the published
                    // page lost those styles. `change:changesCount` increments on
                    // EVERY tracked change (components AND styles), so bind to both.
                    editor.on('update', sync);
                    editor.on('change:changesCount style:update styleable:change rule:add rule:update rule:remove', syncSoon);

                    editor.on('component:selected', (c) => {
                        addAnimTraits(c);
                        this.writeState({
                            selectedComponentId: c.getId(),
                            selectedComponentHtml: pbToAlpine(c.toHTML()),
                        });
                    });
                    editor.on('component:deselected', () => this.writeState({
                        selectedComponentId: null,
                        selectedComponentHtml: null,
                    }));

                    window.addEventListener('page-builder-apply', (e) => {
                        const d = e.detail || {};
                        if (d.mode === 'replace') {
                            editor.setComponents(pbToSafe(d.html || ''));
                            if (d.css) { editor.setStyle(d.css); }
                        } else if (d.mode === 'insert') {
                            editor.addComponents(pbToSafe(d.html || ''));
                        } else if (d.mode === 'rewrite' && d.targetId) {
                            const target = editor.getWrapper().find('#' + d.targetId)[0];
                            if (target) { target.components(pbToSafe(d.html || '')); }
                        }
                        sync();
                    });
                },
            });

            const register = () => window.Alpine.data('aiPageBuilderEditor', factory);
            if (window.Alpine) { register(); } else { document.addEventListener('alpine:init', register); }

            // Delegated copy-to-clipboard for read-only URL fields (the media edit
            // form). The button carries data-ai-pb-copy; value "input" copies the
            // sibling input's value, otherwise the attribute value itself.
            if (! window.__aiPbCopyBound) {
                window.__aiPbCopyBound = true;
                document.addEventListener('click', function (e) {
                    const btn = e.target.closest('[data-ai-pb-copy]');
                    if (! btn) { return; }
                    e.preventDefault();
                    let val = btn.getAttribute('data-ai-pb-copy');
                    if (val === 'input' || val === '') {
                        const wrp = btn.closest('.fi-input-wrp') || btn.closest('.fi-fo-field-wrp');
                        const input = wrp && wrp.querySelector('input, textarea');
                        val = input ? input.value : '';
                    }
                    if (val && navigator.clipboard) { navigator.clipboard.writeText(val).catch(function () {}); }
                });
            }
        })();
    </script>
@endonce
