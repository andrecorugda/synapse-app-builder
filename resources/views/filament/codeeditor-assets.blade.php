{{-- Loads the Ace editor and registers the aiPbCode Alpine component. Ace's
     src-noconflict build pollutes no globals (unlike Monaco's AMD loader, which
     broke Livewire) and renders robustly inside Livewire/wire:ignore fields
     (unlike CodeMirror 5, whose line-measurement crashed on every keystroke
     here). Linting is server-side (`php -l`) shown via Ace gutter annotations. --}}
@once
    @php $aceBase = rtrim((string) config('ai-page-builder.editor.ace_base', 'https://cdn.jsdelivr.net/npm/ace-builds@1.36.5/src-min-noconflict'), '/'); @endphp
    <script src="{{ $aceBase }}/ace.js"></script>
    <script>window.__pbAceBase = @js($aceBase);</script>
    <style>
        .ai-pb-code .ace_editor { border-radius: 0.5rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    </style>
    @verbatim
    <script>
        (function () {
            if (window.ace && window.__pbAceBase) {
                window.ace.config.set('basePath', window.__pbAceBase);
            }

            function aceMode(language) {
                switch (language) {
                    case 'php': return 'php';
                    case 'css': return 'css';
                    case 'json': return 'json';
                    case 'javascript': return 'javascript';
                    default: return 'text';
                }
            }

            var instances = [];
            function resizeAll() {
                instances.forEach(function (i) {
                    if (i.editor && i.$refs && i.$refs.editor && i.$refs.editor.isConnected) {
                        try { i.editor.resize(); } catch (e) {}
                    }
                });
            }

            var factory = function (config) {
                return {
                    editor: null,
                    _t: null,

                    boot() {
                        var self = this;
                        if (! window.ace) { return setTimeout(function () { self.boot(); }, 50); }
                        this.mount();
                    },

                    mount() {
                        if (this.editor) { return; }
                        var el = this.$refs.editor;
                        if (! el) { return; }
                        var self = this;

                        var ed = window.ace.edit(el);
                        ed.setTheme('ace/theme/monokai');
                        ed.session.setMode('ace/mode/' + aceMode(config.language));
                        var initial = this.$wire.get(config.statePath);
                        ed.setValue(initial == null ? '' : String(initial), -1);
                        ed.setOptions({
                            fontSize: '13px',
                            showPrintMargin: false,
                            useWorker: false,
                            tabSize: 2,
                            useSoftTabs: true,
                            wrap: false,
                            highlightActiveLine: true,
                        });
                        this.editor = ed;
                        instances.push(this);

                        ed.session.on('change', function () {
                            self.$wire.set(config.statePath, ed.getValue(), false);
                            if (config.lintUrl) { self.lintDebounced(); }
                        });
                        if (config.lintUrl) { this.lintDebounced(); }
                    },

                    lintDebounced() {
                        var self = this;
                        clearTimeout(this._t);
                        this._t = setTimeout(function () { self.lint(); }, 500);
                    },

                    lint() {
                        if (! config.lintUrl || ! this.editor) { return; }
                        var self = this;
                        fetch(config.lintUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                            body: JSON.stringify({ code: this.editor.getValue() }),
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                var ann = (d.errors || []).map(function (e) {
                                    return { row: (e.line || 1) - 1, column: 0, text: e.message || 'Syntax error', type: 'error' };
                                });
                                self.editor.session.setAnnotations(ann);
                            })
                            .catch(function () {});
                    },
                };
            };

            var register = function () { window.Alpine.data('aiPbCode', factory); };
            if (window.Alpine) { register(); } else { document.addEventListener('alpine:init', register); }

            // Resize editors after Livewire DOM updates so they re-layout cleanly.
            var hookLivewire = function () {
                if (! window.Livewire || ! window.Livewire.hook) { return; }
                window.Livewire.hook('morph.updated', function () { setTimeout(resizeAll, 0); });
            };
            if (window.Livewire) { hookLivewire(); } else { document.addEventListener('livewire:init', hookLivewire); }
        })();
    </script>
    @endverbatim
@endonce
