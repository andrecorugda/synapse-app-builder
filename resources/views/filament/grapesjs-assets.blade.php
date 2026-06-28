{{-- Loads GrapesJS once per panel page AND registers the editor's Alpine
     component. This is injected into the panel LAYOUT via a render hook
     (panels::body.end) — not the field's component view — because Livewire does
     not reliably compile/run scripts (@script / <script>) inside a sub-component
     view. The field view only carries the markup + x-data config call. --}}
@once
    <link rel="stylesheet" href="{{ config('ai-page-builder.assets.grapesjs_css') }}">
    <script src="{{ config('ai-page-builder.assets.grapesjs_js') }}"></script>
    <style>
        /* CSS-based maximize (native fullscreen is unreliable inside the panel). */
        .pb-maximized { position: fixed !important; inset: 0 !important; z-index: 9999 !important; width: 100vw !important; height: 100vh !important; margin: 0 !important; border-radius: 0 !important; }
        .pb-maximized .pb-editor-body { height: 100vh !important; }
    </style>
    <script>
        (function () {
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

                    // Load canonical GrapesJS state if present; otherwise fall
                    // back to importing the stored HTML (pages created by a seed,
                    // an import, or AI have html but no project_data yet).
                    const existing = this.readState('project_data');
                    if (existing && Object.keys(existing).length) {
                        try { editor.loadProjectData(existing); } catch (e) { /* ignore malformed */ }
                    } else {
                        const html = this.readState('html');
                        const css = this.readState('css');
                        if (html) { editor.setComponents(html); }
                        if (css) { editor.setStyle(css); }
                    }

                    const sync = () => this.writeState({
                        project_data: editor.getProjectData(),
                        html: editor.getHtml(),
                        css: editor.getCss(),
                    });
                    editor.on('update', sync);

                    editor.on('component:selected', (c) => this.writeState({
                        selectedComponentId: c.getId(),
                        selectedComponentHtml: c.toHTML(),
                    }));
                    editor.on('component:deselected', () => this.writeState({
                        selectedComponentId: null,
                        selectedComponentHtml: null,
                    }));

                    window.addEventListener('page-builder-apply', (e) => {
                        const d = e.detail || {};
                        if (d.mode === 'replace') {
                            editor.setComponents(d.html || '');
                            if (d.css) { editor.setStyle(d.css); }
                        } else if (d.mode === 'insert') {
                            editor.addComponents(d.html || '');
                        } else if (d.mode === 'rewrite' && d.targetId) {
                            const target = editor.getWrapper().find('#' + d.targetId)[0];
                            if (target) { target.components(d.html || ''); }
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
