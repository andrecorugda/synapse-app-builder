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
            window.Alpine.store('app').$user = null;
            @unless ($static ?? false)
            fetch('{{ $pbRel('pb-auth/me') }}', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var u = (d && d.user) ? d.user : null;
                    window.Alpine.store('app').$user = u;
                    document.querySelectorAll('[data-pb-auth]').forEach(function (el) {
                        if (! u) { el.style.display = 'none'; }
                    });
                    document.querySelectorAll('[data-pb-roles]').forEach(function (el) {
                        var allowed = (el.getAttribute('data-pb-roles') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                        if (! (u && (u.is_admin || allowed.indexOf(u.role) !== -1))) { el.style.display = 'none'; }
                    });
                })
                .catch(function () {});
            @endunless

            // pbTable — the Data Table block's x-data. Fetches a collection's rows
            // from the auto REST API (GET {api}/{collection} → {data:[…]}) and
            // exposes rows/loading/error for x-for / x-show bindings. The block's
            // static sample rows carry x-show="false" so only real rows render.
            var API_BASE = window.__pbApiBase;
            window.Alpine.data('pbTable', function (collection) {
                return {
                    rows: [], loading: true, error: false,
                    page: 1, lastPage: 1, total: 0, perPage: 10,
                    init: function () {
                        // A curated data-table carries its own x-for row template.
                        // When the block is a bare shell (no template — the shape the
                        // AI often emits), fall back to auto-rendering columns from
                        // the data so the table is never blank.
                        this._auto = ! this.$el.querySelector('template[x-for], [x-for]');
                        this.load();
                        // Live-refresh when a [data-pb-record] form on the page creates
                        // a row, so a management page's list reflects the new record
                        // without a manual reload.
                        var self = this;
                        document.addEventListener('pb:record-created', function () { self.page = 1; self.load(); });
                    },
                    load: function () {
                        var self = this;
                        self.loading = true; self.error = false;
                        // The auto table expands relations (expand=*) so a foreign key
                        // renders as the related record's NAME, not a raw id. Curated
                        // tables bind their own fields and are left untouched.
                        var q = '?page=' + self.page + '&per_page=' + self.perPage + (self._auto ? '&expand=*' : '');
                        fetch(API_BASE + '/' + collection + q, { headers: { Accept: 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                self.rows = (d && d.data) || [];
                                self.lastPage = (d && d.last_page) || 1;
                                self.total = (d && d.total) || self.rows.length;
                                self.loading = false;
                                if (self._auto) { self.renderAuto(); }
                            })
                            .catch(function () { self.loading = false; self.error = true; });
                    },
                    renderAuto: function () {
                        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
                        var human = function (k) { return k.replace(/_id$/, '').replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); }); };
                        var isImg = function (v) { return typeof v === 'string' && /\.(png|jpe?g|gif|webp|svg|avif)(\?|#|$)/i.test(v); };
                        var cell = function (v) {
                            if (isImg(v)) { return '<img src="' + esc(v) + '" alt="" style="height:2.25rem;width:2.25rem;object-fit:cover;border-radius:.35rem;border:1px solid #e2e8f0;" />'; }
                            return esc((v && typeof v === 'object') ? (v.name || v.label || v.title || JSON.stringify(v)) : v);
                        };
                        if (! this.rows.length) { this.$el.innerHTML = '<p class="pb-table__empty" style="padding:1rem;color:#64748b;font-family:inherit;">No records yet.</p>'; return; }
                        var row0 = this.rows[0];
                        // A relation fk `x_id` is expanded onto a sibling `x` (object).
                        // Drop the sibling as its own column and render the related NAME
                        // in the `x_id` cell instead of the raw id.
                        var keys = Object.keys(row0).filter(function (k) {
                            if (k === 'created_at' || k === 'updated_at' || k === 'deleted_at') { return false; }
                            return ! Object.prototype.hasOwnProperty.call(row0, k + '_id'); // hide expansion sibling
                        });
                        var display = function (row, k) {
                            if (/_id$/.test(k)) { var sib = row[k.replace(/_id$/, '')]; if (sib && typeof sib === 'object') { return cell(sib); } }
                            return cell(row[k]);
                        };
                        // A management page pairs this list with a form that writes the
                        // same collection — when present, offer Edit/Delete per row.
                        var recForm = document.querySelector('form[data-pb-record="' + collection + '"]');
                        var thStyle = 'padding:.6rem .9rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:.72rem;letter-spacing:.05em;text-transform:uppercase;color:#64748b;';
                        var th = keys.map(function (k) { return '<th style="' + thStyle + '">' + esc(human(k)) + '</th>'; }).join('')
                            + (recForm ? '<th style="' + thStyle + 'text-align:right;">Actions</th>' : '');
                        var body = this.rows.map(function (row, i) {
                            var cells = keys.map(function (k) { return '<td style="padding:.6rem .9rem;color:#0f172a;">' + display(row, k) + '</td>'; }).join('');
                            if (recForm) {
                                cells += '<td style="padding:.5rem .9rem;text-align:right;white-space:nowrap;">'
                                    + '<button type="button" data-pb-edit="' + i + '" style="border:1px solid #c7d2fe;background:#eef2ff;color:#4338ca;border-radius:.35rem;padding:.2rem .55rem;font-size:.72rem;cursor:pointer;margin-right:.25rem;">Edit</button>'
                                    + '<button type="button" data-pb-del="' + i + '" style="border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:.35rem;padding:.2rem .55rem;font-size:.72rem;cursor:pointer;">Delete</button>'
                                    + '</td>';
                            }
                            return '<tr style="border-bottom:1px solid #f1f5f9;">' + cells + '</tr>';
                        }).join('');
                        this.$el.innerHTML = '<table style="width:100%;border-collapse:collapse;font-family:inherit;font-size:.9rem;background:#fff;border:1px solid #e2e8f0;border-radius:.6rem;overflow:hidden;"><thead style="background:#f8fafc;"><tr>' + th + '</tr></thead><tbody>' + body + '</tbody></table>';
                        if (recForm) { this.wireRowActions(recForm, collection); }
                    },
                    wireRowActions: function (form, collection) {
                        var self = this;
                        var submitBtn = form.querySelector('button[type="submit"], [type="submit"]');
                        var origLabel = submitBtn ? submitBtn.textContent : '';
                        this.$el.querySelectorAll('[data-pb-edit]').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var row = self.rows[+btn.getAttribute('data-pb-edit')];
                                if (! row) { return; }
                                // Fill the form's named inputs from the row (raw fk ids fill relation selects).
                                form.querySelectorAll('[name]').forEach(function (input) {
                                    var k = input.getAttribute('name');
                                    if (Object.prototype.hasOwnProperty.call(row, k)) { input.value = row[k] == null ? '' : row[k]; }
                                });
                                form.setAttribute('data-pb-record-id', row.id);   // → submitRecord does PUT
                                if (submitBtn) { submitBtn.textContent = 'Update'; }
                                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            });
                        });
                        this.$el.querySelectorAll('[data-pb-del]').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var row = self.rows[+btn.getAttribute('data-pb-del')];
                                if (! row || ! window.confirm('Delete this record?')) { return; }
                                fetch(API_BASE + '/' + collection + '/' + encodeURIComponent(row.id), {
                                    method: 'DELETE', headers: { Accept: 'application/json' },
                                }).then(function () {
                                    if (form.getAttribute('data-pb-record-id') == String(row.id)) { form.reset(); form.removeAttribute('data-pb-record-id'); if (submitBtn) { submitBtn.textContent = origLabel; } }
                                    self.load();
                                });
                            });
                        });
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
            window.Alpine.data('pbRecordPicker', function (root) {
                return {
                    q: '', results: [], loading: false,
                    collection: (root && root.getAttribute('data-pb-collection')) || '',
                    labelField: (root && root.getAttribute('data-pb-label-field')) || 'name',
                    imageField: (root && root.getAttribute('data-pb-image-field')) || 'image',
                    priceField: (root && root.getAttribute('data-pb-price-field')) || 'price',
                    target: (root && root.getAttribute('data-pb-target')) || 'cart',
                    init: function () { this.search(); },
                    search: function () {
                        var self = this;
                        if (! this.collection) { this.results = []; return; }
                        this.loading = true;
                        var url = API_BASE + '/' + this.collection + '?per_page=24' + (this.q ? '&search=' + encodeURIComponent(this.q) : '');
                        fetch(url, { headers: { Accept: 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                self.results = ((d && d.data) || []).map(function (row) {
                                    return {
                                        id: row.id,
                                        label: row[self.labelField] != null ? row[self.labelField] : ('#' + row.id),
                                        image: row[self.imageField] || '',                       // product image URL (if any)
                                        price: (row[self.priceField] != null) ? row[self.priceField] : '',
                                        raw: row,
                                    };
                                });
                                self.loading = false;
                            })
                            .catch(function () { self.results = []; self.loading = false; });
                    },
                    pickById: function (id) {
                        var hit = this.results.filter(function (r) { return String(r.id) === String(id); })[0];
                        if (! hit) { return; }
                        var store = window.Alpine.store('app');
                        if (! Array.isArray(store[this.target])) { store[this.target] = []; }
                        // Picking the same product again MERGES into the existing line
                        // (bump its qty) rather than adding a duplicate row — expected
                        // POS/cart behaviour.
                        var line = store[this.target].filter(function (l) { return String(l.id) === String(hit.id); })[0];
                        if (line) { line.qty = (Number(line.qty) || 0) + 1; return; }
                        // Carry the image + price onto the cart line so the cart/grid can show them too.
                        store[this.target].push({ id: hit.id, label: hit.label, image: hit.image || '', qty: 1, price: Number(hit.price) || 0 });
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
            function agg(c, p) { return fetch(API + '/' + c + '/aggregate?' + qs(p), { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }); }
            function fmt(n) { n = Number(n) || 0; return n % 1 === 0 ? n.toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 2 }); }

            document.querySelectorAll('[data-pb-block="kpi"]').forEach(function (el) {
                var c = el.getAttribute('data-pb-collection'); if (! c) { return; }
                agg(c, { metric: el.getAttribute('data-pb-metric') || 'count', field: el.getAttribute('data-pb-field') || '' })
                    .then(function (d) { var v = el.querySelector('[data-pb-kpi-value]'); if (v) { v.textContent = fmt(d && d.total); } })
                    .catch(function () {});
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
                        .catch(function () {});
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

    {{-- Alpine powers the reactive Store + data bindings. Loaded before custom
         JS so the store is initialised and custom JS can read/write it. --}}
    <script src="{{ config('ai-page-builder.assets.alpine_js') }}"></script>

    {{-- Per-page custom JS (authored in the builder's Advanced section) — an
         escape hatch for scenarios the builder doesn't cover. Runs last, after
         the page DOM, the flow runtime, and Alpine (so it can use
         window.Alpine.store('app')). --}}
    @if (! empty($customJs))
        <script>{!! $customJs !!}</script>
    @endif
</body>
</html>
