{{-- Loads CodeMirror 5 (stable, no AMD loader / web workers — unlike Monaco,
     which conflicts with Livewire's bundled deps and SPA navigation) and
     registers the aiPbCode Alpine component. Injected via a panel render hook. --}}
@once
    @php $cm = rtrim((string) config('ai-page-builder.editor.codemirror_base', 'https://cdn.jsdelivr.net/npm/codemirror@5.65.16'), '/'); @endphp
    <link rel="stylesheet" href="{{ $cm }}/lib/codemirror.css">
    <link rel="stylesheet" href="{{ $cm }}/addon/lint/lint.css">
    <script src="{{ $cm }}/lib/codemirror.js"></script>
    <script src="{{ $cm }}/addon/lint/lint.js"></script>
    <script src="{{ $cm }}/addon/edit/closebrackets.js"></script>
    <script src="{{ $cm }}/addon/edit/matchbrackets.js"></script>
    <script src="{{ $cm }}/mode/xml/xml.js"></script>
    <script src="{{ $cm }}/mode/javascript/javascript.js"></script>
    <script src="{{ $cm }}/mode/css/css.js"></script>
    <script src="{{ $cm }}/mode/clike/clike.js"></script>
    <script src="{{ $cm }}/mode/htmlmixed/htmlmixed.js"></script>
    <script src="{{ $cm }}/mode/php/php.js"></script>
    <style>
        .ai-pb-code .CodeMirror { height: auto; border-radius: 0.5rem; font-size: 13px; }
    </style>
    @verbatim
    <script>
        (function () {
            function modeFor(language) {
                switch (language) {
                    case 'php': return 'application/x-httpd-php';
                    case 'css': return 'text/css';
                    case 'json': return { name: 'javascript', json: true };
                    default: return 'text/javascript';
                }
            }

            var factory = function (config) {
                return {
                    cm: null,
                    _t: null,

                    boot() {
                        var self = this;
                        if (! window.CodeMirror) { return setTimeout(function () { self.boot(); }, 50); }
                        this.mount();
                    },

                    mount() {
                        if (this.cm) { return; }
                        var self = this;
                        var initial = this.$wire.get(config.statePath);
                        var opts = {
                            value: initial == null ? '' : String(initial),
                            mode: modeFor(config.language),
                            lineNumbers: true,
                            tabSize: 2,
                            indentUnit: 2,
                            autoCloseBrackets: true,
                            matchBrackets: true,
                            viewportMargin: Infinity,
                        };
                        if (config.lintUrl) {
                            opts.gutters = ['CodeMirror-lint-markers'];
                            opts.lint = { async: true, getAnnotations: function (text, cb) { self.lint(text, cb); } };
                        }
                        this.cm = window.CodeMirror(this.$refs.editor, opts);
                        this.cm.setSize('100%', (config.height || 260) + 'px');
                        this.cm.on('change', function () {
                            self.$wire.set(config.statePath, self.cm.getValue(), false);
                        });
                    },

                    lint(text, cb) {
                        if (! config.lintUrl) { return cb([]); }
                        clearTimeout(this._t);
                        this._t = setTimeout(function () {
                            fetch(config.lintUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                                body: JSON.stringify({ code: text }),
                            })
                                .then(function (r) { return r.json(); })
                                .then(function (d) {
                                    var ann = (d.errors || []).map(function (e) {
                                        var ln = (e.line || 1) - 1;
                                        return {
                                            message: e.message || 'Syntax error',
                                            severity: 'error',
                                            from: window.CodeMirror.Pos(ln, 0),
                                            to: window.CodeMirror.Pos(ln, 200),
                                        };
                                    });
                                    cb(ann);
                                })
                                .catch(function () { cb([]); });
                        }, 500);
                    },
                };
            };

            var register = function () { window.Alpine.data('aiPbCode', factory); };
            if (window.Alpine) { register(); } else { document.addEventListener('alpine:init', register); }
        })();
    </script>
    @endverbatim
@endonce
