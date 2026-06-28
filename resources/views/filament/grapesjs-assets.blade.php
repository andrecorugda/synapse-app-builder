{{-- Loads GrapesJS once per panel page AND registers the editor's Alpine
     component. This is injected into the panel LAYOUT via a render hook
     (panels::body.end) — not the field's component view — because Livewire does
     not reliably compile/run scripts (@script / <script>) inside a sub-component
     view. The field view only carries the markup + x-data config call. --}}
@once
    <link rel="stylesheet" href="{{ config('ai-page-builder.assets.grapesjs_css') }}">
    <script src="{{ config('ai-page-builder.assets.grapesjs_js') }}"></script>
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

                    const editor = window.grapesjs.init({
                        container: this.$refs.canvas,
                        height: config.height + 'px',
                        width: 'auto',
                        fromElement: false,
                        storageManager: false,
                        blockManager: { appendTo: this.$refs.blocks },
                    });
                    this.editor = editor;

                    config.blocks.forEach((b) => {
                        editor.BlockManager.add(b.key, {
                            label: b.label,
                            category: b.category,
                            content: b.template,
                            media: b.icon || undefined,
                        });
                        editor.DomComponents.addType(b.key, {
                            isComponent: (el) => el.getAttribute && el.getAttribute('data-pb-block') === b.key,
                            model: { defaults: { name: b.label } },
                        });
                    });

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
        })();
    </script>
@endonce
