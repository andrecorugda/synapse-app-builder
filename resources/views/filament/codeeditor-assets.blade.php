{{-- Loads the Ace editor and registers the aiPbCode Alpine component. Ace's
     src-noconflict build pollutes no globals (unlike Monaco's AMD loader, which
     broke Livewire) and renders robustly inside Livewire/wire:ignore fields
     (unlike CodeMirror 5, whose line-measurement crashed on every keystroke
     here). Linting is server-side (`php -l`) shown via Ace gutter annotations. --}}
@once
    @php
        $aceBase = rtrim((string) config('ai-page-builder.editor.ace_base', 'https://cdn.jsdelivr.net/npm/ace-builds@1.36.5/src-min-noconflict'), '/');
        try {
            $pbStates = app(\Andre\AiPageBuilder\Services\State\StateShapeService::class)->catalog();
        } catch (\Throwable $e) {
            $pbStates = [];
        }
    @endphp
    <script>
        window.__pbAceBase = @js($aceBase);
        window.__pbStates = @js($pbStates);
    </script>
    <style>
        .ai-pb-code .ace_editor { border-radius: 0.5rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    </style>
    @verbatim
    <script>
        (function () {
            // Idempotent across full loads AND wire:navigate SPA navigations: the
            // panels::body.end render hook re-injects this block on every SPA page,
            // so guard on a window flag — Ace is loaded, Alpine is registered, and
            // the Livewire hook is wired exactly once per browser page lifetime.
            if (window.__pbCodeEditorBooted) { return; }
            window.__pbCodeEditorBooted = true;

            // Load Ace via a guarded dynamic script (not a static tag, which would
            // re-execute on SPA nav and reset Ace's internal module registry).
            if (! window.ace) {
                var s = document.createElement('script');
                s.src = window.__pbAceBase + '/ace.js';
                s.onload = function () { try { window.ace.config.set('basePath', window.__pbAceBase); } catch (e) {} };
                document.head.appendChild(s);
            } else {
                try { window.ace.config.set('basePath', window.__pbAceBase); } catch (e) {}
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

                    // ── Function-helper dropdown state ──
                    helpersOpen: false,
                    helperSearch: '',
                    helperDefs: (config && config.helperDefs) || [],

                    /** Helpers matching the search box (label / description / usage / category). */
                    filteredHelpers() {
                        var q = (this.helperSearch || '').trim().toLowerCase();
                        var defs = this.helperDefs || [];
                        if (! q) { return defs; }
                        return defs.filter(function (d) {
                            return (
                                (d.label || '').toLowerCase().indexOf(q) !== -1 ||
                                (d.description || '').toLowerCase().indexOf(q) !== -1 ||
                                (d.usage || '').toLowerCase().indexOf(q) !== -1 ||
                                (d.category_label || '').toLowerCase().indexOf(q) !== -1 ||
                                (d.key || '').toLowerCase().indexOf(q) !== -1
                            );
                        });
                    },

                    /**
                     * Filtered helpers grouped by category, ordered by category_order
                     * (the registry already sorts by order then label).
                     */
                    helperGroups() {
                        var byCat = {};
                        this.filteredHelpers().forEach(function (d) {
                            var cat = d.category || 'other';
                            if (! byCat[cat]) {
                                byCat[cat] = {
                                    category: cat,
                                    label: d.category_label || cat,
                                    order: (typeof d.category_order === 'number') ? d.category_order : 999,
                                    helpers: [],
                                };
                            }
                            byCat[cat].helpers.push(d);
                        });
                        return Object.keys(byCat)
                            .map(function (k) { return byCat[k]; })
                            .sort(function (a, b) { return a.order - b.order || a.label.localeCompare(b.label); });
                    },

                    /** Insert a helper's usage snippet at the cursor and refocus. */
                    insertHelper(def) {
                        if (! def || ! this.editor) { return; }
                        this.editor.insert(def.usage || '');
                        this.editor.focus();
                    },

                    boot() {
                        var self = this;
                        if (! window.ace) { return setTimeout(function () { self.boot(); }, 50); }
                        try { window.ace.config.set('basePath', window.__pbAceBase); } catch (e) {}
                        this.mount();
                    },

                    mount() {
                        if (this.editor) { return; }
                        var el = this.$refs.editor;
                        if (! el) { return; }
                        var self = this;

                        // If a Livewire morph handed us a node that still carries a
                        // previous editor's Ace DOM, wipe it so ace.edit() starts clean
                        // (otherwise the new editor inherits stale markup/theme).
                        if (el.firstChild) { el.innerHTML = ''; }

                        var ed = window.ace.edit(el);
                        ed.setTheme('ace/theme/monokai');
                        // Ace's PHP mode is HTML-embedded: it only highlights PHP
                        // inside <?php ?> tags. Function bodies are bare PHP (no open
                        // tag), so use the mode's `inline` option to tokenize the whole
                        // buffer as PHP — otherwise it renders as uncolored HTML text.
                        if (config.language === 'php') {
                            ed.session.setMode({ path: 'ace/mode/php', inline: true });
                        } else {
                            ed.session.setMode('ace/mode/' + aceMode(config.language));
                        }
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

                    /**
                     * Insert a State reference at the cursor, in the syntax of the
                     * field's runtime: php → $states['key'], expression
                     * (javascript) → state('key'), else states['key']. A dotted ref
                     * (address.city) chains bracket accessors onto the root.
                     */
                    insertState(ref) {
                        if (! ref || ! this.editor) { return; }
                        var parts = ref.split('.');
                        var head = parts.shift();
                        var out;
                        if (config.language === 'php') {
                            out = "$states['" + head + "']";
                        } else if (config.language === 'javascript') {
                            out = "state('" + head + "')";
                        } else {
                            out = "states['" + head + "']";
                        }
                        parts.forEach(function (p) { out += "['" + p + "']"; });
                        this.editor.insert(out);
                        this.editor.focus();
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
