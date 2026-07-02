<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $meta['title'] ?? $title }}</title>
    @if (! empty($meta['description']))
        <meta name="description" content="{{ $meta['description'] }}">
    @endif
    @if (! empty($meta['canonical']))
        <link rel="canonical" href="{{ $meta['canonical'] }}">
    @endif
    @if (! empty($meta['noindex']))
        <meta name="robots" content="noindex">
    @endif
    @if (! empty($meta['og_image']))
        <meta property="og:image" content="{{ $meta['og_image'] }}">
    @endif
    <meta property="og:title" content="{{ $meta['title'] ?? $title }}">
    <style>
        {{-- Global theme tokens (brand colours/fonts/shape) — pages reference var(--pb-*). --}}
        {!! app(\Andre\AiPageBuilder\Services\Theme::class)->css() !!}
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: var(--pb-font, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif); }
        [x-cloak] { display: none !important; }

        /* Auth-gated elements fail CLOSED: hidden by default, revealed only after
           /pb-auth/me resolves and the element passes its check (see the auth
           bootstrap below). On a fetch error they stay hidden — no flash, no leak.
           The html.pb-auth-ready guard keeps them visible with JS disabled (server
           data is already permission-secured; this is a UX layer). */
        html.pb-auth-ready [data-pb-auth], html.pb-auth-ready [data-pb-roles] { display: none !important; }
        html.pb-auth-ready [data-pb-auth].pb-auth-ok, html.pb-auth-ready [data-pb-roles].pb-auth-ok { display: revert !important; }

        /* Section colour overlays (the builder's --pb-overlay style property). */
        [data-pb-block] { position: relative; }
        [data-pb-block]::after { content: ""; position: absolute; inset: 0; background: var(--pb-overlay, transparent); pointer-events: none; z-index: 0; }
        [data-pb-block] > * { position: relative; z-index: 1; }

        /* Entrance animations (the data-pb-anim trait). Hidden only once JS is
           ready, so content stays visible with JS disabled. */
        html.pb-anim-ready [data-pb-anim]:not([data-pb-anim=""]) { opacity: 0; }
        html.pb-anim-ready [data-pb-anim].pb-anim-in { opacity: 1; animation-duration: .7s; animation-fill-mode: both; }
        [data-pb-anim="fade"].pb-anim-in { animation-name: pbFade; }
        [data-pb-anim="fade-up"].pb-anim-in { animation-name: pbFadeUp; }
        [data-pb-anim="fade-down"].pb-anim-in { animation-name: pbFadeDown; }
        [data-pb-anim="fade-left"].pb-anim-in { animation-name: pbFadeLeft; }
        [data-pb-anim="fade-right"].pb-anim-in { animation-name: pbFadeRight; }
        [data-pb-anim="zoom-in"].pb-anim-in { animation-name: pbZoomIn; }
        @keyframes pbFade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes pbFadeUp { from { opacity: 0; transform: translateY(28px); } to { opacity: 1; transform: none; } }
        @keyframes pbFadeDown { from { opacity: 0; transform: translateY(-28px); } to { opacity: 1; transform: none; } }
        @keyframes pbFadeLeft { from { opacity: 0; transform: translateX(28px); } to { opacity: 1; transform: none; } }
        @keyframes pbFadeRight { from { opacity: 0; transform: translateX(-28px); } to { opacity: 1; transform: none; } }
        @keyframes pbZoomIn { from { opacity: 0; transform: scale(.92); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { html.pb-anim-ready [data-pb-anim] { opacity: 1 !important; animation: none !important; } }

        /* Component config styles (driven by the components' data-pb-* settings). */
        @include('ai-page-builder::render.component-styles')

        {!! $css !!}
        {{-- Per-page custom CSS overrides (authored in the builder's Advanced section). --}}
        {!! $customCss !!}
    </style>

    {{-- Reactive Store: seed Alpine's $store.app from this page's persistent
         States before Alpine boots. Components bind with x-text / x-show /
         x-model; flows push updates via the setState result action. --}}
    @php
        // Origin-relative path (no scheme+host) so every fetch/redirect stays on the
        // visitor's actual scheme+host+port — see flow-runtime.blade.php for why.
        $pbRel = static fn (string $path): string => '/'.ltrim((string) (parse_url(url($path), PHP_URL_PATH) ?: $path), '/');
    @endphp
    <script>
        window.__pbState = @js($state ?? []);
        window.__pbApiBase = '{{ $pbRel(config('ai-page-builder.data.api_prefix', 'api/pb')) }}';
        {{-- The page currently being rendered. A shared nav partial reads this to
             auto-mark its own link (is-active) — see flow-runtime's [data-pb-page]
             loop — so nav markup never hardcodes which link is active. --}}
        window.__pbCurrentSlug = @js((string) $page->slug);
        document.addEventListener('alpine:init', function () {
            window.Alpine.store('app', Object.assign({}, window.__pbState));

            // End-user identity → component visibility. $store.app.$user is null
            // when signed out (use x-show="$store.app.$user" to react). Elements
            // tagged data-pb-auth show only when logged in; data-pb-roles="a,b"
            // show only for those role slugs (admins always pass). Server-side
            // data is already secured by the permission engine — this is UX.
            //
            // FAIL CLOSED: the moment JS runs we add html.pb-auth-ready, which the
            // stylesheet uses to hide every [data-pb-auth]/[data-pb-roles] element
            // BEFORE the identity fetch resolves. Only elements that pass the check
            // get .pb-auth-ok (→ revealed). A fetch error leaves everything hidden,
            // so a gated block never flashes to a signed-out / unauthorized visitor.
            window.Alpine.store('app').$user = null;
            @unless ($static ?? false)
            document.documentElement.classList.add('pb-auth-ready');
            function pbApplyAuth(u) {
                document.querySelectorAll('[data-pb-auth]').forEach(function (el) {
                    if (u) { el.classList.add('pb-auth-ok'); } else { el.classList.remove('pb-auth-ok'); }
                });
                document.querySelectorAll('[data-pb-roles]').forEach(function (el) {
                    var allowed = (el.getAttribute('data-pb-roles') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                    if (u && (u.is_admin || allowed.indexOf(u.role) !== -1)) { el.classList.add('pb-auth-ok'); } else { el.classList.remove('pb-auth-ok'); }
                });
            }
            fetch('{{ $pbRel('pb-auth/me') }}', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var u = (d && d.user) ? d.user : null;
                    window.Alpine.store('app').$user = u;
                    pbApplyAuth(u);
                })
                .catch(function () { /* stay hidden — fail closed, no reveal on error */ });
            @endunless

            // pbTable — the Data Table block's x-data.
            //
            // Collection mode (data-pb-collection="<key>"): fetches the
            // collection schema (GET {api}/{collection}/schema) then the rows
            // (GET {api}/{collection}), and renders TYPE-DRIVEN cells — never by
            // magic field name. Relations are resolved via the related
            // collection's display_field from the schema.
            //
            // State mode (data-pb-state="<key>"): renders rows from
            // $store.app[key] (an array shaped by flows/functions), reactively.
            // Columns come from data-pb-columns or the first row's keys.
            // Type-driven cell formatting only applies in collection mode.
            //
            // Config attributes (all optional):
            //   data-pb-collection="<key>"       — source collection
            //   data-pb-state="<key>"            — source state array (mutually exclusive with collection)
            //   data-pb-columns="k,k:Header,…"   — explicit columns + optional rename
            //   data-pb-hide="k,k"               — columns to omit (when not using explicit columns)
            //   data-pb-sortable="true"           — clickable column headers for sort (default true)
            //   data-pb-no-sort="k,k"            — per-column sort opt-out
            //   data-pb-searchable="true"         — search box
            //   data-pb-filters="k,k"            — filter controls per field
            //   data-pb-selectable="true"         — checkbox column + select-all
            //   data-pb-bulk="action:Label,…"     — bulk action buttons (built-in: delete)
            //   data-pb-per-page="20"             — rows per page (default 20)
            var API_BASE = window.__pbApiBase;
            window.Alpine.data('pbTable', function (collectionArg) {
                return {
                    // Core state
                    rows: [], loading: true, error: false,
                    page: 1, lastPage: 1, total: 0, perPage: 20,
                    // Schema (collection mode only)
                    _schema: null,    // { fields:[{key,label,type,options}], display_field, relations:{} }
                    // Config (read from data-pb-* attrs in init)
                    _collection: '',  // collection key (empty = state mode)
                    _stateKey: '',    // $store.app key (state mode)
                    _colSpec: null,   // parsed columns spec [{key,header}] or null
                    _hide: [],        // field keys to hide
                    _sortable: true,  // global sortable flag
                    _noSort: [],      // per-column sort opt-out
                    _searchable: false,
                    _filterKeys: [],  // field keys that get filter controls
                    _selectable: false,
                    _bulkSpec: [],    // [{action, label}]
                    // Runtime
                    _sortKey: '', _sortDir: 'asc',
                    _search: '',
                    _filters: {},     // {key: value}
                    _selected: {},    // {id: true}
                    _filterTypes: {}, // {key: type} — from schema, for filter UI
                    _filterOptions: {}, // {key: [{value,label}]} — for select filters
                    _stateWatcher: null,

                    init: function () {
                        var self = this;
                        var el = this.$el;

                        // Read config from the root element's data-pb-* attrs
                        self._collection = (el.getAttribute('data-pb-collection') || collectionArg || '').trim();
                        self._stateKey = (el.getAttribute('data-pb-state') || '').trim();

                        var ppRaw = parseInt(el.getAttribute('data-pb-per-page') || '', 10);
                        if (! isNaN(ppRaw) && ppRaw > 0) { self.perPage = ppRaw; }

                        // Columns spec: "key,key:Header,…"
                        var colsRaw = (el.getAttribute('data-pb-columns') || '').trim();
                        if (colsRaw) {
                            self._colSpec = colsRaw.split(',').map(function (s) {
                                s = s.trim();
                                var colon = s.indexOf(':');
                                if (colon > 0) { return { key: s.slice(0, colon).trim(), header: s.slice(colon + 1).trim() }; }
                                return { key: s, header: '' };
                            }).filter(function (c) { return c.key; });
                        }

                        var hideRaw = (el.getAttribute('data-pb-hide') || '').trim();
                        self._hide = hideRaw ? hideRaw.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];

                        var sortableAttr = el.getAttribute('data-pb-sortable');
                        self._sortable = sortableAttr === null || sortableAttr !== 'false';

                        var noSortRaw = (el.getAttribute('data-pb-no-sort') || '').trim();
                        self._noSort = noSortRaw ? noSortRaw.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];

                        self._searchable = el.getAttribute('data-pb-searchable') === 'true';

                        var filtersRaw = (el.getAttribute('data-pb-filters') || '').trim();
                        self._filterKeys = filtersRaw ? filtersRaw.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];

                        self._selectable = el.getAttribute('data-pb-selectable') === 'true';

                        var bulkRaw = (el.getAttribute('data-pb-bulk') || '').trim();
                        self._bulkSpec = bulkRaw ? bulkRaw.split(',').map(function (s) {
                            s = s.trim();
                            var colon = s.indexOf(':');
                            if (colon > 0) { return { action: s.slice(0, colon).trim(), label: s.slice(colon + 1).trim() }; }
                            return { action: s, label: s };
                        }).filter(function (b) { return b.action; }) : [];

                        // State mode: watch the store array and re-render when it changes
                        if (self._stateKey) {
                            self.loading = false;
                            var store = window.Alpine.store('app');
                            if (! Array.isArray(store[self._stateKey])) { store[self._stateKey] = []; }
                            self.rows = store[self._stateKey];
                            // Reactive watch: re-render when the store array changes
                            self._stateWatcher = self.$watch('$store.app.' + self._stateKey, function (val) {
                                self.rows = Array.isArray(val) ? val : [];
                                self.total = self.rows.length;
                                self.renderTable();
                            });
                            self.total = self.rows.length;
                            self.renderTable();
                            return;
                        }

                        // Collection mode: fetch schema then rows
                        if (self._collection) {
                            self.loadSchema(function () { self.load(); });
                        } else {
                            self.loading = false;
                        }

                        // Live-refresh when a [data-pb-record] form creates a row
                        document.addEventListener('pb:record-created', function () {
                            if (self._stateKey) { return; }
                            self.page = 1; self.load();
                        });
                    },

                    loadSchema: function (cb) {
                        var self = this;
                        fetch(API_BASE + '/' + self._collection + '/schema', { headers: { Accept: 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                self._schema = d || null;
                                // Build filter type/options maps from schema
                                if (d && d.fields) {
                                    d.fields.forEach(function (f) {
                                        self._filterTypes[f.key] = f.type;
                                        if ((f.type === 'select' || f.type === 'boolean') && f.options) {
                                            if (f.type === 'boolean') {
                                                self._filterOptions[f.key] = [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }];
                                            } else {
                                                self._filterOptions[f.key] = (f.options || []).map(function (o) { return { value: o, label: o }; });
                                            }
                                        }
                                    });
                                }
                                if (cb) { cb(); }
                            })
                            .catch(function () { if (cb) { cb(); } });
                    },

                    load: function () {
                        var self = this;
                        self.loading = true; self.error = false;
                        var params = '?page=' + self.page + '&per_page=' + self.perPage + '&expand=*';
                        if (self._sortKey) { params += '&sort=' + encodeURIComponent((self._sortDir === 'desc' ? '-' : '') + self._sortKey); }
                        if (self._search) { params += '&search=' + encodeURIComponent(self._search); }
                        Object.keys(self._filters).forEach(function (k) {
                            var v = self._filters[k];
                            if (v === '' || v == null) { return; }
                            // Select / boolean filters match exactly; free-text column
                            // filters are substring matches (server supports `like`).
                            var ftype = self._filterTypes[k];
                            var op = (ftype === 'select' || ftype === 'boolean') ? 'eq' : 'like';
                            params += '&filter[' + encodeURIComponent(k) + '][' + op + ']=' + encodeURIComponent(v);
                        });
                        fetch(API_BASE + '/' + self._collection + params, { headers: { Accept: 'application/json' } })
                            .then(function (r) {
                                // Distinguish an actual failure (403/500/…) from an empty
                                // collection — a non-OK status is an error, not "no records".
                                if (! r.ok) { throw new Error('HTTP ' + r.status); }
                                return r.json();
                            })
                            .then(function (d) {
                                self.rows = (d && d.data) || [];
                                self.lastPage = (d && d.last_page) || 1;
                                self.total = (d && d.total) || self.rows.length;
                                self.loading = false;
                                self.renderTable();
                            })
                            .catch(function () { self.loading = false; self.error = true; self.rows = []; self.renderTable(); });
                    },

                    // Compute visible columns from config + schema (or row keys in state mode)
                    resolveColumns: function () {
                        var self = this;
                        var schema = self._schema;

                        if (self._colSpec) {
                            // Explicit columns: use as-is; fill header from schema if blank
                            return self._colSpec.map(function (c) {
                                var header = c.header;
                                if (! header && schema && schema.fields) {
                                    var sf = schema.fields.filter(function (f) { return f.key === c.key; })[0];
                                    header = sf ? sf.label : self.humanize(c.key);
                                }
                                return { key: c.key, header: header || self.humanize(c.key) };
                            });
                        }

                        // Auto: derive from schema fields or row keys
                        var SYSTEM = ['created_at', 'updated_at', 'deleted_at'];
                        var keys;
                        if (schema && schema.fields && schema.fields.length) {
                            keys = schema.fields
                                .map(function (f) { return f.key; })
                                .filter(function (k) { return SYSTEM.indexOf(k) === -1 && self._hide.indexOf(k) === -1; });
                        } else if (self.rows.length) {
                            // State mode or schema unavailable: derive from first row
                            var row0 = self.rows[0];
                            keys = Object.keys(row0).filter(function (k) {
                                return SYSTEM.indexOf(k) === -1 && self._hide.indexOf(k) === -1;
                            });
                        } else {
                            return [];
                        }

                        return keys.map(function (k) {
                            var header = k;
                            if (schema && schema.fields) {
                                var sf = schema.fields.filter(function (f) { return f.key === k; })[0];
                                header = sf ? sf.label : self.humanize(k);
                            } else {
                                header = self.humanize(k);
                            }
                            return { key: k, header: header };
                        });
                    },

                    // Render a cell value from TYPE (collection mode) or as-is (state mode)
                    renderCell: function (row, colKey) {
                        var self = this;
                        var schema = self._schema;
                        var esc = self.esc;

                        // In state mode (no schema) render values as-is
                        if (! schema) {
                            var v = row[colKey];
                            if (v === null || v === undefined) { return ''; }
                            if (typeof v === 'object') { return esc(JSON.stringify(v)); }
                            return esc(String(v));
                        }

                        // Collection mode: look up field type from schema
                        var fieldDef = (schema.fields || []).filter(function (f) { return f.key === colKey; })[0];
                        var type = fieldDef ? fieldDef.type : null;

                        // Relation: the API expands `x_id` onto sibling `x` (the related object).
                        // Display the related row's display_field from schema.relations.
                        if (type === 'relation') {
                            var colName = colKey; // may already include _id suffix
                            var sibKey = /^(.+)_id$/.test(colName) ? colName.replace(/_id$/, '') : colName;
                            var sib = row[sibKey];
                            if (sib && typeof sib === 'object') {
                                var relInfo = schema.relations && schema.relations[colKey];
                                var displayKey = relInfo ? relInfo.display_field : 'id';
                                var label = sib[displayKey];
                                return label != null ? esc(String(label)) : esc(String(sib.id || ''));
                            }
                            // Fallback: raw id
                            var rawId = row[colName];
                            return rawId != null ? esc(String(rawId)) : '';
                        }

                        var val = row[colKey];
                        if (val === null || val === undefined) { return ''; }

                        if (type === 'image') {
                            if (typeof val !== 'string' || val === '') { return ''; }
                            return '<img src="' + esc(val) + '" alt="" style="height:2.25rem;width:2.25rem;object-fit:cover;border-radius:.35rem;border:1px solid #e2e8f0;">';
                        }
                        if (type === 'boolean') {
                            return val ? '<span style="color:#16a34a;">&#10003;</span>' : '<span style="color:#dc2626;">&#10007;</span>';
                        }
                        if (type === 'date') {
                            try { return esc(new Date(val).toLocaleDateString()); } catch (e) { return esc(String(val)); }
                        }
                        if (type === 'datetime') {
                            try { return esc(new Date(val).toLocaleString()); } catch (e) { return esc(String(val)); }
                        }
                        if (type === 'json') {
                            if (typeof val === 'object') { return '<code style="font-size:.78rem;color:#475569;">' + esc(JSON.stringify(val)) + '</code>'; }
                            return '<code style="font-size:.78rem;color:#475569;">' + esc(String(val)) + '</code>';
                        }
                        // integer, decimal, string, text, select — render as text
                        if (typeof val === 'object') { return esc(JSON.stringify(val)); }
                        return esc(String(val));
                    },

                    esc: function (s) {
                        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
                            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
                        });
                    },

                    humanize: function (k) {
                        return k.replace(/_id$/, '').replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
                    },

                    // Returns selected ids as an array
                    selectedIds: function () {
                        return Object.keys(this._selected).filter(function (k) { return this._selected[k]; }, this);
                    },

                    toggleAll: function (checked) {
                        var self = this;
                        var newSel = {};
                        if (checked) { self.rows.forEach(function (r) { if (r.id != null) { newSel[String(r.id)] = true; } }); }
                        self._selected = newSel;
                    },

                    toggleRow: function (id, checked) {
                        var newSel = Object.assign({}, this._selected);
                        if (checked) { newSel[String(id)] = true; } else { delete newSel[String(id)]; }
                        this._selected = newSel;
                    },

                    applySort: function (key) {
                        if (! this._sortable || this._noSort.indexOf(key) !== -1) { return; }
                        if (this._sortKey === key) {
                            this._sortDir = this._sortDir === 'asc' ? 'desc' : 'asc';
                        } else {
                            this._sortKey = key; this._sortDir = 'asc';
                        }
                        this.page = 1;
                        if (this._collection) { this.load(); }
                    },

                    applySearch: function (val) {
                        this._search = val;
                        this.page = 1;
                        if (this._collection) { this.load(); }
                    },

                    applyFilter: function (key, val) {
                        this._filters[key] = val;
                        this.page = 1;
                        if (this._collection) { this.load(); }
                    },

                    doBulk: function (action) {
                        var self = this;
                        var ids = self.selectedIds();
                        if (! ids.length) { return; }

                        // Dispatch custom event so flow/author JS can intercept
                        self.$el.dispatchEvent(new CustomEvent('pb:bulk', {
                            bubbles: true, detail: { action: action, ids: ids, collection: self._collection },
                        }));
                        document.dispatchEvent(new CustomEvent('pb:bulk', {
                            bubbles: true, detail: { action: action, ids: ids, collection: self._collection },
                        }));

                        // Built-in delete: DELETE each id, then reload
                        if (action === 'delete' && self._collection) {
                            if (! window.confirm('Delete ' + ids.length + ' record(s)?')) { return; }
                            var deletes = ids.map(function (id) {
                                return fetch(API_BASE + '/' + self._collection + '/' + encodeURIComponent(id), {
                                    method: 'DELETE', headers: { Accept: 'application/json' },
                                });
                            });
                            Promise.all(deletes).then(function () { self._selected = {}; self.load(); });
                        }
                    },

                    renderTable: function () {
                        var self = this;
                        var esc = self.esc.bind(self);
                        var cols = self.resolveColumns();
                        var recForm = self._collection
                            ? document.querySelector('form[data-pb-record="' + self._collection + '"]')
                            : null;
                        var thStyle = 'padding:.6rem .9rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:.72rem;letter-spacing:.05em;text-transform:uppercase;color:#64748b;white-space:nowrap;';
                        var sortIcon = function (k) {
                            if (! self._sortable || self._noSort.indexOf(k) !== -1) { return ''; }
                            if (self._sortKey !== k) { return '<span style="opacity:.35;margin-left:.3rem;">&#8597;</span>'; }
                            return '<span style="margin-left:.3rem;">' + (self._sortDir === 'asc' ? '&#8593;' : '&#8595;') + '</span>';
                        };

                        // Build toolbar (search + filters + bulk actions)
                        var toolbarParts = [];
                        if (self._searchable) {
                            toolbarParts.push('<input type="search" placeholder="Search…" value="' + esc(self._search) + '" data-pb-tbl-search style="padding:.4rem .65rem;border:1px solid #cbd5e1;border-radius:.4rem;font:inherit;font-size:.85rem;min-width:14rem;">');
                        }
                        self._filterKeys.forEach(function (fk) {
                            var ftype = self._filterTypes[fk] || 'string';
                            var fopts = self._filterOptions[fk] || null;
                            var curVal = self._filters[fk] || '';
                            var label = esc(self.humanize(fk));
                            if (fopts && (ftype === 'select' || ftype === 'boolean')) {
                                var opts = '<option value="">All ' + label + '</option>';
                                fopts.forEach(function (o) {
                                    opts += '<option value="' + esc(o.value) + '"' + (curVal === o.value ? ' selected' : '') + '>' + esc(o.label) + '</option>';
                                });
                                toolbarParts.push('<select data-pb-tbl-filter="' + esc(fk) + '" style="padding:.4rem .65rem;border:1px solid #cbd5e1;border-radius:.4rem;font:inherit;font-size:.85rem;">' + opts + '</select>');
                            } else {
                                toolbarParts.push('<input type="text" placeholder="Filter ' + label + '…" value="' + esc(curVal) + '" data-pb-tbl-filter="' + esc(fk) + '" style="padding:.4rem .65rem;border:1px solid #cbd5e1;border-radius:.4rem;font:inherit;font-size:.85rem;min-width:10rem;">');
                            }
                        });
                        var selIds = self.selectedIds();
                        if (self._selectable && selIds.length && self._bulkSpec.length) {
                            self._bulkSpec.forEach(function (b) {
                                var btnStyle = b.action === 'delete'
                                    ? 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;'
                                    : 'background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe;';
                                toolbarParts.push('<button type="button" data-pb-tbl-bulk="' + esc(b.action) + '" style="' + btnStyle + 'border-radius:.4rem;padding:.4rem .8rem;font:inherit;font-size:.85rem;cursor:pointer;">' + esc(b.label) + ' (' + selIds.length + ')</button>');
                            });
                        }
                        var toolbar = toolbarParts.length
                            ? '<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;padding:.6rem .9rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;">' + toolbarParts.join('') + '</div>'
                            : '';

                        // Build table header
                        var cbHead = self._selectable
                            ? '<th style="' + thStyle + 'width:2.5rem;"><input type="checkbox" data-pb-tbl-selall style="cursor:pointer;"></th>'
                            : '';
                        var thCells = cols.map(function (c) {
                            var clickable = self._sortable && self._noSort.indexOf(c.key) === -1;
                            var cursor = clickable ? 'cursor:pointer;user-select:none;' : '';
                            var attr = clickable ? ' data-pb-tbl-sort="' + esc(c.key) + '"' : '';
                            return '<th style="' + thStyle + cursor + '"' + attr + '>' + esc(c.header) + sortIcon(c.key) + '</th>';
                        }).join('');
                        var actHead = recForm ? '<th style="' + thStyle + 'text-align:right;">Actions</th>' : '';
                        var thead = '<thead style="background:#f8fafc;"><tr>' + cbHead + thCells + actHead + '</tr></thead>';

                        // Build table body
                        var tbody;
                        if (self.error) {
                            // A load failure (403 / server error / network) — surface it
                            // instead of masquerading as an empty collection.
                            var colspanE = cols.length + (self._selectable ? 1 : 0) + (recForm ? 1 : 0);
                            tbody = '<tbody><tr><td colspan="' + colspanE + '" style="padding:1rem .9rem;color:#b91c1c;font-family:inherit;">'
                                + '<span style="display:inline-flex;align-items:center;gap:.4rem;">'
                                + '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                                + 'Could not load records. '
                                + '<button type="button" data-pb-tbl-retry style="background:none;border:0;color:#b91c1c;text-decoration:underline;cursor:pointer;font:inherit;padding:0;">Retry</button>'
                                + '</span></td></tr></tbody>';
                        } else if (! self.rows.length) {
                            var colspan = cols.length + (self._selectable ? 1 : 0) + (recForm ? 1 : 0);
                            tbody = '<tbody><tr><td colspan="' + colspan + '" style="padding:1rem .9rem;color:#64748b;font-family:inherit;">No records yet.</td></tr></tbody>';
                        } else {
                            var tdStyle = 'padding:.55rem .9rem;color:#0f172a;border-bottom:1px solid #f1f5f9;vertical-align:middle;';
                            var rows = self.rows.map(function (row, i) {
                                var isChecked = self._selectable && row.id != null && self._selected[String(row.id)];
                                var cbCell = self._selectable
                                    ? '<td style="' + tdStyle + 'width:2.5rem;"><input type="checkbox" data-pb-tbl-sel="' + esc(String(row.id)) + '"' + (isChecked ? ' checked' : '') + ' style="cursor:pointer;"></td>'
                                    : '';
                                var cells = cols.map(function (c) {
                                    return '<td style="' + tdStyle + '">' + self.renderCell(row, c.key) + '</td>';
                                }).join('');
                                var actCell = recForm
                                    ? '<td style="' + tdStyle + 'text-align:right;white-space:nowrap;">'
                                        + '<button type="button" data-pb-edit="' + i + '" style="border:1px solid #c7d2fe;background:#eef2ff;color:#4338ca;border-radius:.35rem;padding:.2rem .55rem;font-size:.72rem;cursor:pointer;margin-right:.25rem;">Edit</button>'
                                        + '<button type="button" data-pb-del="' + i + '" style="border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:.35rem;padding:.2rem .55rem;font-size:.72rem;cursor:pointer;">Delete</button>'
                                        + '</td>'
                                    : '';
                                return '<tr>' + cbCell + cells + actCell + '</tr>';
                            }).join('');
                            tbody = '<tbody>' + rows + '</tbody>';
                        }

                        // Build pagination footer
                        var colspan2 = cols.length + (self._selectable ? 1 : 0) + (recForm ? 1 : 0);
                        var tfoot = self.lastPage > 1
                            ? '<tfoot><tr><td colspan="' + colspan2 + '" style="padding:.55rem .9rem;border-top:1px solid #e2e8f0;">'
                                + '<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;color:#64748b;font-size:.85rem;">'
                                + '<span>Page ' + self.page + ' of ' + self.lastPage + ' &middot; ' + self.total + ' records</span>'
                                + '<span style="display:flex;gap:.5rem;">'
                                + '<button type="button" data-pb-tbl-prev ' + (self.page <= 1 ? 'disabled' : '') + ' style="padding:.35rem .7rem;border:1px solid #e2e8f0;border-radius:.375rem;background:#fff;cursor:pointer;">Prev</button>'
                                + '<button type="button" data-pb-tbl-next ' + (self.page >= self.lastPage ? 'disabled' : '') + ' style="padding:.35rem .7rem;border:1px solid #e2e8f0;border-radius:.375rem;background:#fff;cursor:pointer;">Next</button>'
                                + '</span></div></td></tr></tfoot>'
                            : '';

                        self.$el.innerHTML = toolbar
                            + '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-family:inherit;font-size:.9rem;background:#fff;">'
                            + thead + tbody + tfoot
                            + '</table></div>';

                        // Wire interactive elements via delegation on the table container
                        self.wireTableEvents(recForm);
                    },

                    wireTableEvents: function (recForm) {
                        var self = this;
                        var el = self.$el;

                        // Sort headers
                        el.querySelectorAll('[data-pb-tbl-sort]').forEach(function (th) {
                            th.addEventListener('click', function () { self.applySort(th.getAttribute('data-pb-tbl-sort')); self.renderTable(); });
                        });

                        // Search
                        var searchEl = el.querySelector('[data-pb-tbl-search]');
                        if (searchEl) {
                            var debounce;
                            searchEl.addEventListener('input', function () {
                                clearTimeout(debounce);
                                debounce = setTimeout(function () { self.applySearch(searchEl.value); }, 350);
                            });
                        }

                        // Filters
                        el.querySelectorAll('[data-pb-tbl-filter]').forEach(function (fi) {
                            fi.addEventListener('input', function () { self.applyFilter(fi.getAttribute('data-pb-tbl-filter'), fi.value); });
                            fi.addEventListener('change', function () { self.applyFilter(fi.getAttribute('data-pb-tbl-filter'), fi.value); });
                        });

                        // Select all
                        var selAll = el.querySelector('[data-pb-tbl-selall]');
                        if (selAll) {
                            selAll.addEventListener('change', function () { self.toggleAll(selAll.checked); self.renderTable(); });
                        }

                        // Row checkboxes
                        el.querySelectorAll('[data-pb-tbl-sel]').forEach(function (cb) {
                            cb.addEventListener('change', function () { self.toggleRow(cb.getAttribute('data-pb-tbl-sel'), cb.checked); self.renderTable(); });
                        });

                        // Bulk actions
                        el.querySelectorAll('[data-pb-tbl-bulk]').forEach(function (btn) {
                            btn.addEventListener('click', function () { self.doBulk(btn.getAttribute('data-pb-tbl-bulk')); });
                        });

                        // Retry after a load error
                        var retryBtn = el.querySelector('[data-pb-tbl-retry]');
                        if (retryBtn) { retryBtn.addEventListener('click', function () { self.load(); }); }

                        // Pagination
                        var prevBtn = el.querySelector('[data-pb-tbl-prev]');
                        if (prevBtn) { prevBtn.addEventListener('click', function () { if (self.page > 1) { self.page--; self.load(); } }); }
                        var nextBtn = el.querySelector('[data-pb-tbl-next]');
                        if (nextBtn) { nextBtn.addEventListener('click', function () { if (self.page < self.lastPage) { self.page++; self.load(); } }); }

                        // Per-row Edit / Delete (management pages)
                        if (recForm) {
                            var submitBtn = recForm.querySelector('button[type="submit"], [type="submit"]');
                            var origLabel = submitBtn ? submitBtn.textContent : '';
                            el.querySelectorAll('[data-pb-edit]').forEach(function (btn) {
                                btn.addEventListener('click', function () {
                                    var row = self.rows[+btn.getAttribute('data-pb-edit')];
                                    if (! row) { return; }
                                    recForm.querySelectorAll('[name]').forEach(function (input) {
                                        var k = input.getAttribute('name');
                                        if (Object.prototype.hasOwnProperty.call(row, k)) { input.value = row[k] == null ? '' : row[k]; }
                                    });
                                    recForm.setAttribute('data-pb-record-id', row.id);
                                    if (submitBtn) { submitBtn.textContent = 'Update'; }
                                    recForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                });
                            });
                            el.querySelectorAll('[data-pb-del]').forEach(function (btn) {
                                btn.addEventListener('click', function () {
                                    var row = self.rows[+btn.getAttribute('data-pb-del')];
                                    if (! row || ! window.confirm('Delete this record?')) { return; }
                                    fetch(API_BASE + '/' + self._collection + '/' + encodeURIComponent(row.id), {
                                        method: 'DELETE', headers: { Accept: 'application/json' },
                                    }).then(function () {
                                        if (recForm.getAttribute('data-pb-record-id') == String(row.id)) {
                                            recForm.reset(); recForm.removeAttribute('data-pb-record-id');
                                            if (submitBtn) { submitBtn.textContent = origLabel; }
                                        }
                                        self.load();
                                    });
                                });
                            });
                        }
                    },

                    prev: function () { if (this.page > 1) { this.page--; this.load(); } },
                    next: function () { if (this.page < this.lastPage) { this.page++; this.load(); } },
                };
            });

            // pbAutocomplete — the Autocomplete block's x-data. Typeahead against a
            // collection's REST endpoint; `selectedId` holds the chosen record id.
            window.Alpine.data('pbAutocomplete', function (root) {
                return {
                    q: '', results: [], open: false, selectedId: '',
                    collection: (root && root.getAttribute('data-pb-collection')) || '',
                    labelField: (root && root.getAttribute('data-pb-label-field')) || 'name',
                    search: function () {
                        var self = this;
                        if (! this.collection || this.q.length < 1) { this.results = []; return; }
                        fetch(API_BASE + '/' + this.collection + '?search=' + encodeURIComponent(this.q) + '&per_page=8', { headers: { Accept: 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                self.results = ((d && d.data) || []).map(function (row) {
                                    return { id: row.id, label: row[self.labelField] != null ? row[self.labelField] : ('#' + row.id) };
                                });
                                self.open = true;
                            })
                            .catch(function () { self.results = []; });
                    },
                    pick: function (r) { this.q = r.label; this.selectedId = r.id; this.open = false; this.results = []; },
                    // Delegated-friendly variant: the option carries data-pb-ac-pick="<id>"
                    // (the loop var can't reach a delegated handler), so resolve the
                    // result by id from the current list. Mirrors pbRecordPicker.pickById.
                    pickById: function (id) {
                        var hit = this.results.filter(function (r) { return String(r.id) === String(id); })[0];
                        if (hit) { this.pick(hit); }
                    },
                };
            });

            // ── Interactive line-item components ──────────────────────────────
            // All click/step wiring is DELEGATED (see the runtime IIFE in <body>)
            // and keyed off data-pb-* hooks, because the AI sanitizer strips
            // @click / x-on:. These x-data components hold the reactive state and
            // expose the mutation methods the delegated listeners call via
            // Alpine.$data(el). State is proxied to $store.app[data-pb-state] so
            // flows and sibling components share one array (e.g. the cart).

            // Bind a component's `rows` array to $store.app[key] so it survives
            // outside the component and reactive bindings see the same reference.
            function pbBindState(self, root, defKey) {
                var store = window.Alpine.store('app');
                var key = (root && root.getAttribute('data-pb-state')) || defKey;
                self.stateKey = key;
                if (key) {
                    if (! Array.isArray(store[key])) { store[key] = []; }
                    self.rows = store[key];
                }
            }
            function pbNum(root, attr, fallback) {
                var v = parseFloat(root && root.getAttribute(attr));
                return isNaN(v) ? fallback : v;
            }

            // pbRepeater — repeats an inner template per item in a bound array.
            window.Alpine.data('pbRepeater', function (root) {
                return {
                    rows: [], stateKey: '',
                    min: 0, max: 0,
                    init: function () {
                        this.min = pbNum(root, 'data-pb-min', 0);
                        this.max = pbNum(root, 'data-pb-max', 0);
                        pbBindState(this, root, 'items');
                    },
                    add: function () {
                        if (this.max > 0 && this.rows.length >= this.max) { return; }
                        this.rows.push({ label: '' });
                    },
                    removeAt: function (i) {
                        if (this.rows.length <= this.min) { return; }
                        if (i >= 0 && i < this.rows.length) { this.rows.splice(i, 1); }
                    },
                };
            });

            // pbGrid — the editable data grid (cart). Live per-row subtotal and a
            // grand total are Alpine expressions in the template; `total` is a
            // getter so it recomputes reactively.
            window.Alpine.data('pbGrid', function (root) {
                return {
                    rows: [], stateKey: '',
                    qtyKey: 'qty', priceKey: 'price', max: 0,
                    init: function () {
                        this.qtyKey = (root && root.getAttribute('data-pb-qty')) || 'qty';
                        this.priceKey = (root && root.getAttribute('data-pb-price')) || 'price';
                        this.max = pbNum(root, 'data-pb-max', 0);
                        pbBindState(this, root, 'cart');
                    },
                    add: function () {
                        if (this.max > 0 && this.rows.length >= this.max) { return; }
                        this.rows.push({ label: '', qty: 1, price: 0 });
                    },
                    removeAt: function (i) {
                        if (i >= 0 && i < this.rows.length) { this.rows.splice(i, 1); }
                    },
                    money: function (n) {
                        n = Number(n) || 0;
                        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },
                    get total() {
                        return this.rows.reduce(function (sum, r) {
                            return sum + (Number(r.qty) || 0) * (Number(r.price) || 0);
                        }, 0);
                    },
                };
            });

            // pbStepper — −/+ around a number input, bound to $store.app[key].
            window.Alpine.data('pbStepper', function (root) {
                return {
                    stateKey: '', min: 0, max: 0, step: 1,
                    init: function () {
                        this.min = pbNum(root, 'data-pb-min', 0);
                        this.max = pbNum(root, 'data-pb-max', 0);
                        this.step = pbNum(root, 'data-pb-step', 1) || 1;
                        this.stateKey = (root && root.getAttribute('data-pb-state')) || 'quantity';
                        var store = window.Alpine.store('app');
                        if (store[this.stateKey] == null) { store[this.stateKey] = this.min; }
                    },
                    get value() {
                        var store = window.Alpine.store('app');
                        return Number(store[this.stateKey]) || 0;
                    },
                    set value(v) {
                        v = Number(v); if (isNaN(v)) { v = this.min; }
                        if (v < this.min) { v = this.min; }
                        if (this.max > 0 && v > this.max) { v = this.max; }
                        window.Alpine.store('app')[this.stateKey] = v;
                    },
                    bump: function (dir) { this.value = this.value + dir * this.step; },
                };
            });

            // pbContextMenu — kebab / right-click menu. `open` + `pos` are local;
            // opening (toggle / contextmenu) and item clicks are delegated.
            window.Alpine.data('pbContextMenu', function (root) {
                return {
                    open: false, pos: '',
                    toggle: function () { this.open = ! this.open; },
                    close: function () { this.open = false; },
                    openAt: function (x, y) {
                        this.pos = 'left:' + x + 'px;top:' + y + 'px;';
                        this.open = true;
                    },
                    // When this menu is nested inside an editable_grid row or a
                    // repeater item, remove that row from its owner array. Index
                    // is the row's position among its siblings.
                    removeItem: function () {
                        this.open = false;
                        var ownerEl = root.closest('[data-pb-block="editable_grid"],[data-pb-block="repeater"]');
                        if (! ownerEl) { return; }
                        var isGrid = ownerEl.getAttribute('data-pb-block') === 'editable_grid';
                        var rowSel = isGrid ? '.pb-grid__row' : '.pb-repeater__item';
                        var rowEl = root.closest(rowSel);
                        if (! rowEl) { return; }
                        var owner = window.Alpine.$data(ownerEl);
                        var nodes = ownerEl.querySelectorAll(rowSel), i = -1;
                        for (var n = 0; n < nodes.length; n++) { if (nodes[n] === rowEl) { i = n; break; } }
                        if (owner && typeof owner.removeAt === 'function' && i >= 0) { owner.removeAt(i); }
                    },
                };
            });

            // pbRecordPicker — searchable tile grid; clicking a tile appends the
            // record (projection) to $store.app[data-pb-target].
            //
            // Config attrs (all optional):
            //   data-pb-collection   — collection to search (required)
            //   data-pb-label-field  — field key for the tile's primary label text
            //                          (required; no default — use the schema display field
            //                          or be explicit; a bare picker shows only this label)
            //   data-pb-image-field  — OPT-IN field key for a thumbnail image; omit → no image shown
            //   data-pb-extra-field  — OPT-IN field key for a secondary line on the tile; omit → hidden
            //   data-pb-target       — $store.app key for the output array (required for picking)
            //
            // Each pick appends { id, label } to the target array. If data-pb-image-field
            // is set the pick also carries { image }. A "qty" merging is NOT applied here —
            // that is a domain concern; wire it in a flow or custom JS if needed.
            // The editable_grid / cart qty/price fields are a genuine line-item component,
            // NOT part of the picker — configure those on the editable_grid block.
            window.Alpine.data('pbRecordPicker', function (root) {
                return {
                    q: '', results: [], loading: false,
                    collection: (root && root.getAttribute('data-pb-collection')) || '',
                    // Default to 'name' when unset — consistent with pbAutocomplete and
                    // the relation-select populator — so tiles show a label, not #id.
                    labelField: (root && root.getAttribute('data-pb-label-field')) || 'name',
                    imageField: (root && root.getAttribute('data-pb-image-field')) || '',   // '' = no image
                    extraField: (root && root.getAttribute('data-pb-extra-field')) || '',   // '' = no extra line
                    target: (root && root.getAttribute('data-pb-target')) || '',
                    init: function () { this.search(); },
                    search: function () {
                        var self = this;
                        if (! this.collection) { this.results = []; return; }
                        this.loading = true;
                        var url = API_BASE + '/' + this.collection + '?per_page=24' + (this.q ? '&search=' + encodeURIComponent(this.q) : '');
                        fetch(url, { headers: { Accept: 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                var lf = self.labelField;
                                var imf = self.imageField;
                                var exf = self.extraField;
                                self.results = ((d && d.data) || []).map(function (row) {
                                    var result = {
                                        id: row.id,
                                        label: lf && row[lf] != null ? row[lf] : ('#' + row.id),
                                        raw: row,
                                    };
                                    // Image only when explicitly configured
                                    if (imf) { result.image = row[imf] || ''; }
                                    // Extra line only when explicitly configured
                                    if (exf) { result.extra = row[exf] != null ? String(row[exf]) : ''; }
                                    return result;
                                });
                                self.loading = false;
                            })
                            .catch(function () { self.results = []; self.loading = false; });
                    },
                    pickById: function (id) {
                        var hit = this.results.filter(function (r) { return String(r.id) === String(id); })[0];
                        if (! hit || ! this.target) { return; }
                        var store = window.Alpine.store('app');
                        if (! Array.isArray(store[this.target])) { store[this.target] = []; }
                        // Append a projection of the picked row. Include image only when the
                        // picker is configured with data-pb-image-field.
                        var line = { id: hit.id, label: hit.label };
                        if (this.imageField) { line.image = hit.image || ''; }
                        if (this.extraField) { line.extra = hit.extra || ''; }
                        store[this.target].push(line);
                    },
                };
            });
        });
    </script>
</head>
<body x-data>
    {!! $html !!}

    <script>
        (function () {
            var els = document.querySelectorAll('[data-pb-anim]:not([data-pb-anim=""])');
            if (! els.length || ! ('IntersectionObserver' in window)) { return; }
            document.documentElement.classList.add('pb-anim-ready');
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (! e.isIntersecting) { return; }
                    var el = e.target;
                    var delay = el.getAttribute('data-pb-anim-delay');
                    if (delay) { el.style.animationDelay = parseInt(delay, 10) + 'ms'; }
                    el.classList.add('pb-anim-in');
                    io.unobserve(el);
                });
            }, { threshold: 0.15 });
            els.forEach(function (el) { io.observe(el); });
        })();
    </script>

    {{-- Flow trigger runtime: components with data-pb-flow run a flow on click. --}}
    @include('ai-page-builder::render.flow-runtime')

    {{-- Interactive components runtime: repeater / editable_grid / stepper /
         context_menu / record_picker. Click + input wiring is DELEGATED and
         keyed off data-pb-* hooks (never inline @click), so the block templates
         survive the AI HtmlSanitizer (which strips @click / x-on:) unchanged.
         Each handler resolves the owning Alpine component via Alpine.$data()
         and calls the method the x-data component exposes. --}}
    <script>
        (function () {
            function ad(el) { return (window.Alpine && window.Alpine.$data) ? window.Alpine.$data(el) : null; }
            // 0-based index of `el` among matches of `sel` within `scope`.
            function indexAmong(el, scope, sel) {
                var nodes = scope.querySelectorAll(sel);
                for (var i = 0; i < nodes.length; i++) { if (nodes[i] === el) { return i; } }
                return -1;
            }

            document.addEventListener('click', function (e) {
                if (! window.Alpine) { return; }

                // Repeater add / remove.
                var repAdd = e.target.closest('[data-pb-repeater-add]');
                if (repAdd) {
                    var repRoot = repAdd.closest('[data-pb-block="repeater"]');
                    var rep = repRoot && ad(repRoot);
                    if (rep && rep.add) { rep.add(); }
                    return;
                }
                var repRem = e.target.closest('[data-pb-repeater-remove]');
                if (repRem) {
                    var repRoot2 = repRem.closest('[data-pb-block="repeater"]');
                    var itemEl = repRem.closest('.pb-repeater__item');
                    var rep2 = repRoot2 && ad(repRoot2);
                    if (rep2 && rep2.removeAt && itemEl) { rep2.removeAt(indexAmong(itemEl, repRoot2, '.pb-repeater__item')); }
                    return;
                }

                // Editable grid add / remove row.
                var gridAdd = e.target.closest('[data-pb-grid-add]');
                if (gridAdd) {
                    var gRoot = gridAdd.closest('[data-pb-block="editable_grid"]');
                    var g = gRoot && ad(gRoot);
                    if (g && g.add) { g.add(); }
                    return;
                }
                var gridRem = e.target.closest('[data-pb-grid-remove]');
                if (gridRem) {
                    var gRoot2 = gridRem.closest('[data-pb-block="editable_grid"]');
                    var rowEl = gridRem.closest('.pb-grid__row');
                    var g2 = gRoot2 && ad(gRoot2);
                    if (g2 && g2.removeAt && rowEl) { g2.removeAt(indexAmong(rowEl, gRoot2, '.pb-grid__row')); }
                    return;
                }

                // Stepper −/+.
                var step = e.target.closest('[data-pb-step]');
                if (step && step.closest('[data-pb-block="stepper"]')) {
                    var sRoot = step.closest('[data-pb-block="stepper"]');
                    var s = ad(sRoot);
                    var dir = parseInt(step.getAttribute('data-pb-step'), 10) || 0;
                    if (s && s.bump) { s.bump(dir); }
                    return;
                }

                // Context menu: toggle, item close, and the built-in Remove item.
                var ctxToggle = e.target.closest('[data-pb-context-toggle]');
                if (ctxToggle) {
                    var cRoot = ctxToggle.closest('[data-pb-block="context_menu"]');
                    var c = cRoot && ad(cRoot);
                    if (c && c.toggle) { c.toggle(); }
                    return;
                }
                var ctxRemove = e.target.closest('[data-pb-context-remove]');
                if (ctxRemove) {
                    var cRoot2 = ctxRemove.closest('[data-pb-block="context_menu"]');
                    var c2 = cRoot2 && ad(cRoot2);
                    if (c2 && c2.removeItem) { c2.removeItem(); }
                    return; // (flow-runtime also handles any data-pb-flow on this item)
                }
                var ctxClose = e.target.closest('[data-pb-context-close]');
                if (ctxClose) {
                    var cRoot3 = ctxClose.closest('[data-pb-block="context_menu"]');
                    var c3 = cRoot3 && ad(cRoot3);
                    if (c3 && c3.close) { c3.close(); }
                    // no return — allow data-pb-flow items to also fire their flow
                }

                // Record picker: click a tile to add its record to the target array.
                var tile = e.target.closest('[data-pb-pick]');
                if (tile) {
                    var pRoot = tile.closest('[data-pb-block="record_picker"]');
                    var p = pRoot && ad(pRoot);
                    if (p && p.pickById) { p.pickById(tile.getAttribute('data-pb-pick-id')); }
                    return;
                }

                // Dismiss any open context menu when clicking outside it.
                document.querySelectorAll('[data-pb-block="context_menu"]').forEach(function (root) {
                    if (root.contains(e.target)) { return; }
                    var cd = ad(root);
                    if (cd && cd.open) { cd.close(); }
                });
            }, false);

            // Right-click opens the context menu at the pointer.
            document.addEventListener('contextmenu', function (e) {
                if (! window.Alpine) { return; }
                var root = e.target.closest('[data-pb-contextmenu]');
                if (! root) { return; }
                e.preventDefault();
                var c = ad(root);
                if (! c || ! c.openAt) { return; }
                var box = root.getBoundingClientRect();
                c.openAt(e.clientX - box.left, e.clientY - box.top);
            }, false);

            // Record picker search-as-you-type (delegated; x-model already syncs q).
            document.addEventListener('input', function (e) {
                if (! window.Alpine) { return; }
                var search = e.target.closest('[data-pb-picker-search]');
                if (! search) { return; }
                var pRoot = search.closest('[data-pb-block="record_picker"]');
                var p = pRoot && ad(pRoot);
                if (p && p.search) { p.search(); }
            }, false);
        })();
    </script>

    {{-- Components-kit interaction runtime: the disclosure/overlay UI-kit blocks
         (modal, drawer, tabs, accordion, dropdown_menu, tooltip, autocomplete,
         banner) wire behaviour through data-pb-* hooks — NEVER inline @click /
         @keydown / @mouseenter, which the AI HtmlSanitizer strips. The blocks keep
         declarative Alpine (x-data / x-show / x-bind / x-transition) for their
         reactive state; this single delegated runtime resolves the owning Alpine
         component via Alpine.$data(el) and mutates the named x-data prop:
           data-pb-toggle="<prop>"  — flip a boolean prop
           data-pb-open="<prop>"    — set a prop true (open overlay)
           data-pb-close="<prop>"   — set a prop false (close overlay / dismiss)
           data-pb-set="<prop>:<v>" — assign a literal value (tabs, single-open)
           data-pb-dismiss          — set `show` false (banner)
           data-pb-hover="<prop>"   — true on enter/focus, false on leave/blur (tooltip)
         Overlays additionally close on outside-click (their backdrop carries
         data-pb-close) and on Escape. --}}
    <script>
        (function () {
            function ad(el) { return (window.Alpine && window.Alpine.$data) ? window.Alpine.$data(el) : null; }

            // Coerce a literal from data-pb-set="prop:value" — quoted → string,
            // true/false → boolean, numeric → number, else string.
            function coerce(raw) {
                if (raw === 'true') { return true; }
                if (raw === 'false') { return false; }
                if (/^-?\d+(\.\d+)?$/.test(raw)) { return Number(raw); }
                if ((raw.charAt(0) === "'" && raw.slice(-1) === "'") || (raw.charAt(0) === '"' && raw.slice(-1) === '"')) {
                    return raw.slice(1, -1);
                }
                return raw;
            }

            // Apply a prop mutation to the Alpine component owning `el`.
            function mutate(el, kind, arg) {
                var data = ad(el);
                if (! data) { return; }
                if (kind === 'toggle') { data[arg] = ! data[arg]; return; }
                if (kind === 'open') { data[arg] = true; return; }
                if (kind === 'close') { data[arg] = false; return; }
                if (kind === 'dismiss') { data.show = false; return; }
                if (kind === 'set') {
                    var i = arg.indexOf(':');
                    if (i < 0) { return; }
                    data[arg.slice(0, i)] = coerce(arg.slice(i + 1));
                }
            }

            document.addEventListener('click', function (e) {
                if (! window.Alpine) { return; }

                var toggle = e.target.closest('[data-pb-toggle]');
                if (toggle) { mutate(toggle, 'toggle', toggle.getAttribute('data-pb-toggle')); return; }

                var open = e.target.closest('[data-pb-open]');
                if (open) { mutate(open, 'open', open.getAttribute('data-pb-open')); return; }

                var setEl = e.target.closest('[data-pb-set]');
                if (setEl) { mutate(setEl, 'set', setEl.getAttribute('data-pb-set')); return; }

                var dismiss = e.target.closest('[data-pb-dismiss]');
                if (dismiss) { mutate(dismiss, 'dismiss', ''); return; }

                // Autocomplete: pick an option by id (loop var can't reach a
                // delegated handler, so the id rides on the attribute).
                var acPick = e.target.closest('[data-pb-ac-pick]');
                if (acPick) {
                    var acRoot = acPick.closest('[data-pb-block="autocomplete"]');
                    var acData = acRoot && ad(acRoot);
                    if (acData && acData.pickById) { acData.pickById(acPick.getAttribute('data-pb-ac-pick')); }
                    return;
                }

                // data-pb-close fires last so a backdrop with data-pb-close only
                // closes on a click ON the backdrop itself (not bubbled from inside
                // the panel) — the handler element is the closest match to e.target.
                var close = e.target.closest('[data-pb-close]');
                if (close) {
                    // Guard: a backdrop marked data-pb-close-self closes only when the
                    // click landed directly on it (mirrors @click.self on overlays).
                    if (close.hasAttribute('data-pb-close-self') && e.target !== close) { return; }
                    mutate(close, 'close', close.getAttribute('data-pb-close'));
                    return;
                }
            }, false);

            // Escape closes any open overlay: every element that declares
            // data-pb-close is asked to close its owning component's named prop.
            document.addEventListener('keydown', function (e) {
                if (! window.Alpine || e.key !== 'Escape') { return; }
                document.querySelectorAll('[data-pb-escape-close]').forEach(function (el) {
                    mutate(el, 'close', el.getAttribute('data-pb-escape-close'));
                });
            }, false);

            // Outside-click closes elements marked data-pb-outside-close="<prop>"
            // (dropdown menus, autocomplete lists) when the click lands outside the
            // component root that carries the hook.
            document.addEventListener('click', function (e) {
                if (! window.Alpine) { return; }
                document.querySelectorAll('[data-pb-outside-close]').forEach(function (root) {
                    if (root.contains(e.target)) { return; }
                    mutate(root, 'close', root.getAttribute('data-pb-outside-close'));
                });
            }, false);

            // Tooltip hover/focus: data-pb-hover="<prop>" sets the prop true on
            // pointer-enter / focus-in and false on leave / focus-out.
            function hoverOn(e) {
                var el = e.target.closest ? e.target.closest('[data-pb-hover]') : null;
                if (el) { mutate(el, 'set', el.getAttribute('data-pb-hover') + ':true'); }
            }
            function hoverOff(e) {
                var el = e.target.closest ? e.target.closest('[data-pb-hover]') : null;
                if (el) { mutate(el, 'set', el.getAttribute('data-pb-hover') + ':false'); }
            }
            document.addEventListener('mouseenter', hoverOn, true);
            document.addEventListener('mouseleave', hoverOff, true);
            document.addEventListener('focusin', hoverOn, true);
            document.addEventListener('focusout', hoverOff, true);

            // Autocomplete: search-as-you-type on the input (x-model already syncs q).
            document.addEventListener('input', function (e) {
                if (! window.Alpine) { return; }
                var input = e.target.closest('[data-pb-ac-search]');
                if (! input) { return; }
                var root = input.closest('[data-pb-block="autocomplete"]');
                var p = root && ad(root);
                if (p && p.search) { p.open = true; p.search(); }
            }, false);
        })();
    </script>

    {{-- End-user logout: any element with data-pb-logout="1" ends the pb session
         and returns to the login page. The CSRF token is read from the per-session
         XSRF-TOKEN cookie at click time — NEVER baked into this (cached) HTML. --}}
    @if ((bool) config('ai-page-builder.auth.enabled', true) && ! ($static ?? false))
    <script>
        (function () {
            var nodes = document.querySelectorAll('[data-pb-logout="1"]');
            if (! nodes.length) { return; }
            var url = '{{ $pbRel('pb-logout') }}';
            var loginUrl = '{{ $pbRel(trim((string) config('ai-page-builder.auth.login_path', 'login'), '/')) }}';
            function xsrf() { var m = document.cookie.match(/XSRF-TOKEN=([^;]+)/); return m ? decodeURIComponent(m[1]) : ''; }
            nodes.forEach(function (el) {
                el.style.cursor = 'pointer';
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    fetch(url, { method: 'POST', headers: { 'X-XSRF-TOKEN': xsrf(), 'Accept': 'application/json' }, credentials: 'same-origin' })
                        .then(function () { window.location.href = loginUrl; })
                        .catch(function () { window.location.href = loginUrl; });
                });
            });
        })();
    </script>
    @endif

    {{-- Charts & KPI cards: aggregate from a collection via the REST API. KPI
         cards need no library; charts lazy-load Chart.js (config-overridable for
         offline/vendored use) only when the page actually contains a chart. --}}
    <script>
        (function () {
            var API = window.__pbApiBase;
            function qs(o) { return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] != null; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&'); }
            // Throw on a non-2xx so callers can show a real error state instead
            // of treating a 403/500 body as data (which rendered a misleading 0).
            function agg(c, p) { return fetch(API + '/' + c + '/aggregate?' + qs(p), { headers: { Accept: 'application/json' } }).then(function (r) { if (! r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); }); }
            function fmt(n) { n = Number(n) || 0; return n % 1 === 0 ? n.toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 2 }); }

            document.querySelectorAll('[data-pb-block="kpi"]').forEach(function (el) {
                var c = el.getAttribute('data-pb-collection'); if (! c) { return; }
                agg(c, { metric: el.getAttribute('data-pb-metric') || 'count', field: el.getAttribute('data-pb-field') || '' })
                    .then(function (d) { var v = el.querySelector('[data-pb-kpi-value]'); if (v) { v.textContent = fmt(d && d.total); } })
                    // Don't leave a fake "0" on failure — show it couldn't load.
                    .catch(function () { var v = el.querySelector('[data-pb-kpi-value]'); if (v) { v.textContent = '—'; v.setAttribute('title', 'Could not load — you may not have access.'); } });
            });

            // Relation <select data-pb-options="<collection>">: populate its options
            // from the collection's records so a management form can pick a related
            // row (e.g. a product's category). data-pb-label-field sets the visible
            // text (default "name"); the option value is the record id.
            document.querySelectorAll('select[data-pb-options]').forEach(function (sel) {
                var c = sel.getAttribute('data-pb-options'); if (! c) { return; }
                var labelField = sel.getAttribute('data-pb-label-field') || 'name';
                fetch(API + '/' + c + '?per_page=100', { headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        (((d && d.data) || [])).forEach(function (row) {
                            var o = document.createElement('option');
                            o.value = row.id;
                            o.textContent = row[labelField] != null ? row[labelField] : ('#' + row.id);
                            sel.appendChild(o);
                        });
                    })
                    .catch(function () {});
            });

            var charts = document.querySelectorAll('[data-pb-block="chart"]');
            if (! charts.length) { return; }
            var palette = ['#6366f1', '#22d3ee', '#fbbf24', '#34d399', '#f472b6', '#60a5fa', '#f87171', '#a78bfa'];
            function render() {
                charts.forEach(function (el) {
                    var c = el.getAttribute('data-pb-collection'); if (! c) { return; }
                    var raw = el.getAttribute('data-pb-chart-type') || 'bar';
                    var area = raw === 'area';
                    var type = area ? 'line' : (raw === 'donut' ? 'doughnut' : raw);
                    agg(c, { metric: el.getAttribute('data-pb-metric') || 'count', field: el.getAttribute('data-pb-field') || '', group_by: el.getAttribute('data-pb-group') || '', date_bucket: el.getAttribute('data-pb-date-bucket') || '' })
                        .then(function (d) {
                            var rows = (d && d.rows) || [];
                            var ph = el.querySelector('.pb-chart__placeholder'); if (ph) { ph.style.display = 'none'; }
                            var canvas = el.querySelector('canvas'); if (! canvas || ! window.Chart) { return; }
                            var labels = rows.map(function (r) { return r.label == null ? '—' : r.label; });
                            var values = rows.map(function (r) { return r.value; });
                            var pie = (type === 'doughnut' || type === 'pie');
                            new window.Chart(canvas.getContext('2d'), {
                                type: type,
                                data: { labels: labels, datasets: [{
                                    label: el.getAttribute('data-pb-metric') || 'count',
                                    data: values,
                                    backgroundColor: (type === 'line') ? 'rgba(99,102,241,0.15)' : labels.map(function (_, i) { return palette[i % palette.length]; }),
                                    borderColor: '#6366f1', borderWidth: 2, fill: area, tension: 0.3,
                                }] },
                                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: pie } } },
                            });
                        })
                        // Surface a load failure instead of an empty canvas.
                        .catch(function () { var ph = el.querySelector('.pb-chart__placeholder'); if (ph) { ph.style.display = ''; ph.textContent = 'Could not load chart data — you may not have access.'; } });
                });
            }
            if (window.Chart) { render(); return; }
            var s = document.createElement('script');
            s.src = '{{ config('ai-page-builder.assets.chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js') }}';
            s.onload = render;
            document.body.appendChild(s);
        })();
    </script>

    {{-- Embed blocks: set the iframe src from data-pb-embed-url (normalizing
         common share links) so the URL is configurable without touching markup. --}}
    <script>
        (function () {
            function toEmbed(url) {
                var yt = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]{6,})/);
                if (yt) { return 'https://www.youtube.com/embed/' + yt[1]; }
                var vm = url.match(/vimeo\.com\/(\d+)/);
                if (vm) { return 'https://player.vimeo.com/video/' + vm[1]; }
                return url;
            }
            document.querySelectorAll('[data-pb-block="embed"]').forEach(function (el) {
                var url = el.getAttribute('data-pb-embed-url'); if (! url) { return; }
                var f = el.querySelector('iframe'); if (f) { f.src = toEmbed(url); }
                var ph = el.querySelector('.pb-embed__placeholder'); if (ph) { ph.style.display = 'none'; }
            });
        })();
    </script>

    {{-- Per-page custom JS (authored in the builder's Advanced section) — an
         escape hatch for scenarios the builder doesn't cover. Emitted BEFORE
         Alpine (which is deferred) so that any component factory it defines
         (e.g. `window.myApp = () => ({…})` used in `x-data="myApp()"`) exists
         when Alpine boots. To read/write the reactive store, use the
         `alpine:init` event — `document.addEventListener('alpine:init', () => {
         window.Alpine.store('app')… })` — exactly like the framework's own
         components above; it fires when the deferred Alpine starts. --}}
    @if (! empty($customJs))
        <script>{!! $customJs !!}</script>
    @endif

    {{-- Alpine powers the reactive Store + data bindings. Deferred so it starts
         after the inline framework registrations AND the custom JS above have
         run — component factories and `alpine:init` listeners are in place before
         the first `x-data` is evaluated. --}}
    <script defer src="{{ config('ai-page-builder.assets.alpine_js') }}"></script>
</body>
</html>
