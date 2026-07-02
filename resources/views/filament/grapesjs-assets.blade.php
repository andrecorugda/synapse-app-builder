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
        // Roles feed the "Visible to roles" component-visibility trait as a
        // dropdown (never free-typed slugs). `slug` is what /pb-auth/me exposes
        // as the acting user's role, so it is the value data-pb-roles matches on.
        try {
            $pbRoles = (config('ai-page-builder.models.role', \Andre\AiPageBuilder\Models\PbRole::class))::query()
                ->orderBy('name')->get()
                ->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name])->values()->all();
        } catch (\Throwable $e) {
            $pbRoles = [];
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
        // When editing a partial, exclude it from its OWN list so a partial can't
        // embed (and infinitely re-render) itself — only other partials are offered.
        try {
            $partialClass = config('ai-page-builder.models.partial', \Andre\AiPageBuilder\Models\Partial::class);
            $editingPartialKey = null;
            $editRoute = \Andre\AiPageBuilder\Filament\Resources\PartialResource::getRouteBaseName().'.edit';
            if (optional(request()->route())->getName() === $editRoute) {
                $editingPartialKey = request()->route('record');
            }
            $partialRouteKey = (new $partialClass)->getRouteKeyName();
            $pbPartials = $partialClass::query()
                ->when($editingPartialKey !== null, fn ($q) => $q->where($partialRouteKey, '!=', $editingPartialKey))
                ->orderBy('name')->get()->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name])->values()->all();
        } catch (\Throwable $e) {
            $pbPartials = [];
        }
    @endphp
    <script>
        window.__pbFlows = @js($pbFlows);
        window.__pbPages = @js($pbPages);
        window.__pbStates = @js($pbStates);
        window.__pbRoles = @js($pbRoles);
        window.__pbCollections = @js($pbCollections);
        window.__pbFields = @js($pbFields);
        window.__pbPartials = @js($pbPartials);
        window.__pbThemeCss = @js(app(\Andre\AiPageBuilder\Services\Theme::class)->css());
        // API base for the live-data canvas preview (same origin, same path as the
        // render page uses). Injected here so the canvas script can read it via the
        // parent frame reference (window.parent.__pbEditorApiBase) without relying on
        // window.__pbApiBase being present inside the canvas iframe itself.
        window.__pbEditorApiBase = @js('/'.ltrim(config('ai-page-builder.data.api_prefix', 'api/pb'), '/'));
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

            // ── Live data preview (editor canvas only) ────────────────────────
            // Renders real data into [data-pb-live] overlay containers inside each
            // data block in the GrapesJS canvas iframe. These containers are:
            //
            //   1. NOT persisted: GrapesJS has no knowledge of them — they are
            //      injected into the canvas iframe DOM directly via
            //      editor.Canvas.getDocument(), bypassing GrapesJS's component
            //      model. editor.getHtml() serialises only the components it
            //      tracks (via its internal component tree), so [data-pb-live]
            //      nodes are invisible to it. Belt-and-suspenders: pbToAlpine()
            //      (called on the getHtml() output in sync()) also strips any
            //      stray [data-pb-live] elements from the exported string —
            //      ensuring saved html can never contain data rows even if a
            //      future GrapesJS version started tracking injected DOM.
            //
            //   2. No behavior fires: the overlay uses pointer-events:none so
            //      every click passes through to the block element below it
            //      (GrapesJS then selects the block component normally). No flow/
            //      record/bulk event listeners are attached in the canvas.
            //
            //   3. Editability preserved: preview is capped at 5 rows. The
            //      overlay is a CHILD of the block element, not a replacement —
            //      GrapesJS still tracks the block element as a selectable/
            //      draggable component. Re-render is triggered on component:mount
            //      and component:update (which GrapesJS fires when it re-renders
            //      a component after editing), since re-rendering wipes injected
            //      child DOM. An idempotent clear-and-re-inject pattern handles
            //      that: any existing [data-pb-live] inside a block is removed
            //      before injecting a fresh one.

            // Strip [data-pb-live] from getHtml() output (belt-and-suspenders for
            // constraint #1). Uses a brace-depth counter so nested <div>s inside
            // the live container are correctly consumed — a simple lazy regex would
            // stop at the first inner </div> and leave broken markup behind.
            const pbStripLive = (html) => {
                if (! html || html.indexOf('data-pb-live') === -1) { return html || ''; }
                let out = '';
                let i = 0;
                const n = html.length;
                while (i < n) {
                    // Find the next opening of a data-pb-live div
                    const marker = html.indexOf('<div', i);
                    if (marker === -1) { out += html.slice(i); break; }
                    // Check whether this <div … has data-pb-live in its opening tag
                    const tagEnd = html.indexOf('>', marker);
                    if (tagEnd === -1) { out += html.slice(i); break; }
                    const openTag = html.slice(marker, tagEnd + 1);
                    if (openTag.indexOf('data-pb-live') === -1) {
                        // Not a live container — copy up to and including this tag and move on
                        out += html.slice(i, tagEnd + 1);
                        i = tagEnd + 1;
                        continue;
                    }
                    // It IS a live container. Copy everything before it.
                    out += html.slice(i, marker);
                    // Skip past the live container using div-depth counting.
                    let depth = 1;
                    let j = tagEnd + 1;
                    while (j < n && depth > 0) {
                        const nextOpen = html.indexOf('<div', j);
                        const nextClose = html.indexOf('</div>', j);
                        if (nextClose === -1) { j = n; break; } // malformed — bail
                        if (nextOpen !== -1 && nextOpen < nextClose) {
                            depth++;
                            j = nextOpen + 4; // skip past '<div'
                        } else {
                            depth--;
                            j = nextClose + 6; // skip past '</div>'
                        }
                    }
                    i = j; // resume after the closing </div> of the live container
                }
                return out;
            };

            // Escape HTML for safe text insertion.
            const pbEsc = (s) => String(s == null ? '' : s)
                .replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

            // Humanise a field key ("first_name" → "First Name").
            const pbHum = (k) => k.replace(/_id$/, '').replace(/_/g, ' ')
                .replace(/\b\w/g, (m) => m.toUpperCase());

            // Render a single cell value given its field type and the full row
            // (mirrors renderCell in the render page's pbTable Alpine component,
            // but as a plain function — read-only, no sort/filter/bulk wiring).
            const pbRenderCell = (val, type, colKey, row, schema) => {
                if (type === 'relation') {
                    const sibKey = /^(.+)_id$/.test(colKey) ? colKey.replace(/_id$/, '') : colKey;
                    const sib = row[sibKey];
                    if (sib && typeof sib === 'object') {
                        const relInfo = schema && schema.relations && schema.relations[colKey];
                        const dKey = relInfo ? relInfo.display_field : 'id';
                        const label = sib[dKey];
                        return label != null ? pbEsc(String(label)) : pbEsc(String(sib.id || ''));
                    }
                    return val != null ? pbEsc(String(val)) : '';
                }
                if (val === null || val === undefined) { return ''; }
                if (type === 'image') {
                    if (typeof val !== 'string' || val === '') { return ''; }
                    return '<img src="' + pbEsc(val) + '" alt="" style="height:2rem;width:2rem;object-fit:cover;border-radius:.3rem;border:1px solid #e2e8f0;">';
                }
                if (type === 'boolean') { return val ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#dc2626;">✗</span>'; }
                if (type === 'date') { try { return pbEsc(new Date(val).toLocaleDateString()); } catch (e) { return pbEsc(String(val)); } }
                if (type === 'datetime') { try { return pbEsc(new Date(val).toLocaleString()); } catch (e) { return pbEsc(String(val)); } }
                if (type === 'json') { return '<code style="font-size:.75rem;color:#475569;">' + pbEsc(typeof val === 'object' ? JSON.stringify(val) : String(val)) + '</code>'; }
                if (typeof val === 'object') { return pbEsc(JSON.stringify(val)); }
                return pbEsc(String(val));
            };

            // Build a small read-only preview table HTML string from schema + rows.
            const pbBuildTable = (schema, rows) => {
                const SYSTEM = ['created_at', 'updated_at', 'deleted_at'];
                const cols = schema && schema.fields && schema.fields.length
                    ? schema.fields
                        .filter((f) => SYSTEM.indexOf(f.key) === -1)
                        .map((f) => ({ key: f.key, header: f.label || pbHum(f.key), type: f.type }))
                    : (rows.length ? Object.keys(rows[0]).filter((k) => SYSTEM.indexOf(k) === -1).map((k) => ({ key: k, header: pbHum(k), type: null })) : []);
                if (! cols.length) { return ''; }
                const thS = 'padding:.4rem .6rem;text-align:left;border-bottom:1px solid #e2e8f0;font-size:.7rem;letter-spacing:.04em;text-transform:uppercase;color:#64748b;white-space:nowrap;';
                const tdS = 'padding:.4rem .6rem;color:#0f172a;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:.82rem;';
                let thead = '<thead style="background:#f8fafc;"><tr>' + cols.map((c) => '<th style="' + thS + '">' + pbEsc(c.header) + '</th>').join('') + '</tr></thead>';
                let tbody;
                if (! rows.length) {
                    tbody = '<tbody><tr><td colspan="' + cols.length + '" style="' + tdS + 'color:#94a3b8;">No records.</td></tr></tbody>';
                } else {
                    tbody = '<tbody>' + rows.map((row) =>
                        '<tr>' + cols.map((c) => '<td style="' + tdS + '">' + pbRenderCell(row[c.key], c.type, c.key, row, schema) + '</td>').join('') + '</tr>'
                    ).join('') + '</tbody>';
                }
                return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-family:inherit;font-size:.85rem;background:#fff;">' + thead + tbody + '</table></div>';
            };

            // Inject (or refresh) the live-data overlay for one block element.
            // el: the canvas DOM element with [data-pb-block].
            // Removes any existing [data-pb-live] child first (idempotent).
            // Signature of an element's live-relevant config. An overlay is stamped
            // with it so an unchanged block is SKIPPED on re-run (no refetch/re-render
            // churn = no flicker); a CHANGED config re-renders. Must include EVERY
            // attribute the preview depends on — the data_table's collection lives in
            // x-data="pbTable('key')" and a list's source in x-for, NOT in a data-pb-*
            // attr, so include x-data / x-for or changing the collection wouldn't
            // re-render (the reported bug).
            const pbSig = (el) => el.getAttribute('data-pb-block') + '|'
                + 'xd=' + (el.getAttribute('x-data') || '') + '&xf=' + (el.getAttribute('x-for') || '') + '&'
                + (el.getAttributeNames()
                    .filter((n) => n.indexOf('data-pb-') === 0 && n !== 'data-pb-live' && n !== 'data-pb-sig')
                    .sort().map((n) => n + '=' + el.getAttribute(n)).join('&'));

            const pbInjectLive = (el, innerHtml) => {
                // Remove stale overlay first
                Array.from(el.children).forEach((c) => { if (c.hasAttribute('data-pb-live')) { c.remove(); } });
                if (! innerHtml) { return; }
                const wrap = el.ownerDocument.createElement('div');
                wrap.setAttribute('data-pb-sig', pbSig(el));
                // data-pb-live marks this as a preview container (stripped on export).
                // Rendered in NORMAL FLOW (not absolute) so it takes real height and is
                // VISIBLE — a data block is often an empty shell in the editor, so an
                // absolute/inset:0 overlay would collapse to height 0 and show nothing
                // (the reported "no data in the editor"). pointer-events:none keeps
                // clicks passing through to the block element so GrapesJS click-to-select
                // still works on the underlying component.
                wrap.setAttribute('data-pb-live', '');
                wrap.style.cssText = 'pointer-events:none;overflow-x:auto;';
                wrap.innerHTML = innerHtml;
                el.appendChild(wrap);
            };

            // Fetch and render live preview for all data blocks in the canvas doc.
            const pbLivePreview = (editor) => {
                const apiBase = window.__pbEditorApiBase || '/api/pb';
                let doc;
                try { doc = editor.Canvas.getDocument(); } catch (e) { return; }
                if (! doc) { return; }

                // ORPHAN sweep only (NOT a wipe-all — wiping + refetching on every
                // component:update caused visible flicker). Inside-block overlays are
                // removed automatically with their block; the only overlays that can
                // orphan are the select ones (appended to an offsetParent OUTSIDE the
                // block) — remove those whose <select> is no longer in the document.
                // This clears the "ghost" left by deleting/duplicating a component
                // without churning still-valid overlays.
                doc.querySelectorAll('[data-pb-live="select"]').forEach((o) => {
                    if (! o.__pbForSel || ! doc.contains(o.__pbForSel)) { o.remove(); }
                });

                const blocks = doc.querySelectorAll('[data-pb-block]');
                blocks.forEach((el) => {
                    const blockType = el.getAttribute('data-pb-block');

                    // Skip if this block already has a current overlay (unchanged config).
                    const existingOverlay = el.querySelector(':scope > [data-pb-live]');
                    if (existingOverlay && existingOverlay.getAttribute('data-pb-sig') === pbSig(el)) { return; }

                    // ── data_table ───────────────────────────────────────────
                    if (blockType === 'data_table') {
                        // data-pb-state tables are client-state driven; skip live fetch
                        // (there's nothing to fetch — it's populated by flows at runtime).
                        const stateKey = el.getAttribute('data-pb-state') || '';
                        if (stateKey) { return; }

                        // Resolve the collection key from x-data="pbTable('<key>')" or
                        // data-pb-collection attribute (the trait also writes it there).
                        let collection = (el.getAttribute('data-pb-collection') || '').trim();
                        if (! collection) {
                            const xdata = el.getAttribute('x-data') || '';
                            const m = xdata.match(/pbTable\(\s*['"]([^'"]+)['"]\s*\)/);
                            collection = m ? m[1] : '';
                        }
                        if (! collection) { return; }

                        // Fetch schema + first 5 rows concurrently.
                        Promise.all([
                            fetch(apiBase + '/' + collection + '/schema', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                                .then((r) => r.ok ? r.json() : null).catch(() => null),
                            fetch(apiBase + '/' + collection + '?per_page=5&expand=*', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                                .then((r) => r.ok ? r.json() : null).catch(() => null),
                        ]).then(([schema, data]) => {
                            try {
                                const rows = (data && data.data) || [];
                                const html = pbBuildTable(schema, rows);
                                pbInjectLive(el, html);
                            } catch (e) { /* leave static template */ }
                        });
                    }

                    // ── kpi ──────────────────────────────────────────────────
                    else if (blockType === 'kpi') {
                        const collection = (el.getAttribute('data-pb-collection') || '').trim();
                        if (! collection) { return; }
                        const metric = el.getAttribute('data-pb-metric') || 'count';
                        const field = el.getAttribute('data-pb-field') || '';
                        let qs = 'metric=' + encodeURIComponent(metric);
                        if (field) { qs += '&field=' + encodeURIComponent(field); }
                        fetch(apiBase + '/' + collection + '/aggregate?' + qs, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                            .then((r) => r.ok ? r.json() : null).catch(() => null)
                            .then((d) => {
                                try {
                                    if (d == null) { return; }
                                    const total = d.total != null ? d.total : 0;
                                    const fmt = (n) => {
                                        n = Number(n) || 0;
                                        return n % 1 === 0 ? n.toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 2 });
                                    };
                                    // Try to update the [data-pb-kpi-value] element if it
                                    // exists in the block's template (non-destructive) first.
                                    const kpiEl = el.querySelector('[data-pb-kpi-value]');
                                    if (kpiEl) {
                                        kpiEl.textContent = fmt(total);
                                    } else {
                                        // No designated value element: inject an overlay.
                                        const html = '<div style="display:flex;align-items:center;justify-content:center;height:100%;min-height:3rem;font-size:2rem;font-weight:700;color:#1e293b;font-family:inherit;">' + pbEsc(fmt(total)) + '</div>';
                                        pbInjectLive(el, html);
                                    }
                                } catch (e) { /* leave static template */ }
                            });
                    }

                    // ── record_picker ─────────────────────────────────────────
                    else if (blockType === 'record_picker') {
                        const collection = (el.getAttribute('data-pb-collection') || '').trim();
                        if (! collection) { return; }
                        const labelField = (el.getAttribute('data-pb-label-field') || '').trim();
                        const imageField = (el.getAttribute('data-pb-image-field') || '').trim();
                        const extraField = (el.getAttribute('data-pb-extra-field') || '').trim();
                        fetch(apiBase + '/' + collection + '?per_page=5', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                            .then((r) => r.ok ? r.json() : null).catch(() => null)
                            .then((d) => {
                                try {
                                    const rows = (d && d.data) || [];
                                    if (! rows.length) { return; }
                                    // Build read-only tile grid (no click handler — pointer-events:none).
                                    const tiles = rows.map((row) => {
                                        const label = labelField && row[labelField] != null ? String(row[labelField]) : ('#' + row.id);
                                        const imgSrc = imageField ? (row[imageField] || '') : '';
                                        const extra = extraField && row[extraField] != null ? String(row[extraField]) : '';
                                        return '<div style="display:flex;flex-direction:column;align-items:center;gap:.35rem;padding:.5rem .6rem;border:1px solid #e2e8f0;border-radius:.5rem;background:#f8fafc;min-width:5rem;max-width:7rem;">'
                                            + (imgSrc ? '<img src="' + pbEsc(imgSrc) + '" alt="" style="width:2.5rem;height:2.5rem;object-fit:cover;border-radius:.35rem;">' : '')
                                            + '<span style="font-size:.78rem;font-weight:600;color:#0f172a;text-align:center;word-break:break-word;">' + pbEsc(label) + '</span>'
                                            + (extra ? '<span style="font-size:.7rem;color:#64748b;text-align:center;">' + pbEsc(extra) + '</span>' : '')
                                            + '</div>';
                                    }).join('');
                                    const html = '<div style="display:flex;flex-wrap:wrap;gap:.5rem;padding:.5rem;">' + tiles + '</div>';
                                    pbInjectLive(el, html);
                                } catch (e) { /* leave static template */ }
                            });
                    }

                    // ── chart ─────────────────────────────────────────────────
                    // Fetches aggregate data and renders a lightweight inline SVG/CSS
                    // chart with NO external library dependency (no Chart.js in the
                    // editor canvas). Bar/line/area → horizontal bar chart in SVG;
                    // donut/pie → a compact legend+values list.
                    else if (blockType === 'chart') {
                        const collection = (el.getAttribute('data-pb-collection') || '').trim();
                        if (! collection) { return; }
                        const metric = el.getAttribute('data-pb-metric') || 'count';
                        const field = el.getAttribute('data-pb-field') || '';
                        const group = el.getAttribute('data-pb-group') || '';
                        const dateBucket = el.getAttribute('data-pb-date-bucket') || '';
                        const chartType = (el.getAttribute('data-pb-chart-type') || 'bar').toLowerCase();
                        let qs = 'metric=' + encodeURIComponent(metric);
                        if (field) { qs += '&field=' + encodeURIComponent(field); }
                        if (group) { qs += '&group_by=' + encodeURIComponent(group); }
                        if (dateBucket) { qs += '&date_bucket=' + encodeURIComponent(dateBucket); }
                        fetch(apiBase + '/' + collection + '/aggregate?' + qs, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                            .then((r) => r.ok ? r.json() : null).catch(() => null)
                            .then((d) => {
                                try {
                                    const rows = (d && d.rows) || [];
                                    if (! rows.length) { return; }
                                    const palette = ['#6366f1', '#22d3ee', '#fbbf24', '#34d399', '#f472b6', '#60a5fa', '#f87171', '#a78bfa'];
                                    // Format a number for display
                                    const fmtN = (n) => {
                                        n = Number(n) || 0;
                                        return n % 1 === 0 ? n.toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 2 });
                                    };
                                    let html = '';
                                    if (chartType === 'donut' || chartType === 'pie') {
                                        // Donut/pie: compact legend + value list (no SVG arcs needed)
                                        const total = rows.reduce((s, r) => s + (Number(r.value) || 0), 0);
                                        const items = rows.slice(0, 8).map((r, i) => {
                                            const color = palette[i % palette.length];
                                            const pct = total > 0 ? Math.round((Number(r.value) || 0) / total * 100) : 0;
                                            const label = r.label == null ? '—' : String(r.label);
                                            return '<div style="display:flex;align-items:center;gap:.5rem;padding:.25rem 0;">'
                                                + '<span style="flex-shrink:0;width:.75rem;height:.75rem;border-radius:50%;background:' + color + ';"></span>'
                                                + '<span style="flex:1;font-size:.8rem;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + pbEsc(label) + '</span>'
                                                + '<span style="font-size:.8rem;font-weight:600;color:#1e293b;white-space:nowrap;">' + pbEsc(fmtN(r.value)) + ' <span style="font-weight:400;color:#94a3b8;">(' + pct + '%)</span></span>'
                                                + '</div>';
                                        }).join('');
                                        html = '<div style="padding:.75rem 1rem;">'
                                            + '<div style="font-size:.7rem;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8;margin-bottom:.5rem;">' + pbEsc(metric) + (group ? ' by ' + pbEsc(group) : '') + '</div>'
                                            + items
                                            + (rows.length > 8 ? '<div style="font-size:.72rem;color:#94a3b8;margin-top:.25rem;">+ ' + (rows.length - 8) + ' more</div>' : '')
                                            + '</div>';
                                    } else {
                                        // Bar/line/area → horizontal CSS bar chart in SVG
                                        // Cap at 8 bars to keep the preview compact.
                                        const display = rows.slice(0, 8);
                                        const maxVal = display.reduce((m, r) => Math.max(m, Number(r.value) || 0), 0) || 1;
                                        const barH = 18;
                                        const labelW = 90;
                                        const valW = 52;
                                        const barAreaW = 160;
                                        const rowGap = 8;
                                        const padT = 8; const padB = 8; const padL = 8; const padR = 8;
                                        const svgW = padL + labelW + 8 + barAreaW + 6 + valW + padR;
                                        const svgH = padT + display.length * (barH + rowGap) - rowGap + padB;
                                        let bars = '';
                                        display.forEach((r, i) => {
                                            const color = palette[i % palette.length];
                                            const label = r.label == null ? '—' : String(r.label);
                                            const val = Number(r.value) || 0;
                                            const bw = Math.max(2, Math.round((val / maxVal) * barAreaW));
                                            const y = padT + i * (barH + rowGap);
                                            const cy = y + barH / 2;
                                            // Label (right-aligned, truncated via clip-path workaround: just clip text)
                                            bars += '<text x="' + (padL + labelW) + '" y="' + (cy + 4) + '" text-anchor="end" font-size="11" fill="#475569" font-family="ui-sans-serif,system-ui,sans-serif">'
                                                + pbEsc(label.length > 14 ? label.slice(0, 13) + '…' : label) + '</text>';
                                            // Bar
                                            bars += '<rect x="' + (padL + labelW + 8) + '" y="' + y + '" width="' + bw + '" height="' + barH + '" rx="3" fill="' + color + '"></rect>';
                                            // Value label
                                            bars += '<text x="' + (padL + labelW + 8 + bw + 5) + '" y="' + (cy + 4) + '" font-size="11" fill="#1e293b" font-family="ui-sans-serif,system-ui,sans-serif">'
                                                + pbEsc(fmtN(val)) + '</text>';
                                        });
                                        html = '<div style="padding:.5rem;">'
                                            + '<div style="font-size:.7rem;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8;margin-bottom:.35rem;">' + pbEsc(metric) + (group ? ' by ' + pbEsc(group) : '') + (dateBucket ? ' / ' + pbEsc(dateBucket) : '') + '</div>'
                                            + '<svg xmlns="http://www.w3.org/2000/svg" width="' + svgW + '" height="' + svgH + '" style="display:block;max-width:100%;">' + bars + '</svg>'
                                            + (rows.length > 8 ? '<div style="font-size:.72rem;color:#94a3b8;margin-top:.2rem;">+ ' + (rows.length - 8) + ' more</div>' : '')
                                            + '</div>';
                                    }
                                    pbInjectLive(el, html);
                                } catch (e) { /* leave static template */ }
                            });
                    }

                    // ── list (collection-bound) ───────────────────────────────
                    // Renders the first few items' display-field text when the list
                    // has data-pb-collection (server data). State-bound lists
                    // (x-for over $store.app.*) have no server data in the editor —
                    // skip those (the existing state hint on the trait is enough).
                    else if (blockType === 'list') {
                        const collection = (el.getAttribute('data-pb-collection') || '').trim();
                        if (! collection) { return; }
                        // Fetch schema to find display_field, then rows.
                        Promise.all([
                            fetch(apiBase + '/' + collection + '/schema', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                                .then((r) => r.ok ? r.json() : null).catch(() => null),
                            fetch(apiBase + '/' + collection + '?per_page=5', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                                .then((r) => r.ok ? r.json() : null).catch(() => null),
                        ]).then(([schema, data]) => {
                            try {
                                const rows = (data && data.data) || [];
                                if (! rows.length) { return; }
                                const dispField = (schema && schema.display_field) || 'name';
                                const items = rows.map((row) => {
                                    const label = row[dispField] != null ? String(row[dispField]) : ('#' + row.id);
                                    return '<li style="padding:.3rem .1rem;color:#1e293b;font-size:.85rem;border-bottom:1px solid #f1f5f9;">' + pbEsc(label) + '</li>';
                                }).join('');
                                const html = '<ul style="list-style:none;margin:0;padding:.5rem .75rem;">' + items + '</ul>';
                                pbInjectLive(el, html);
                            } catch (e) { /* leave static template */ }
                        });
                    }

                    // ── autocomplete (collection-bound) ───────────────────────
                    // Shows a static list of a few real suggestion labels beneath
                    // the input (read-only, no typeahead JS — pointer-events:none
                    // keeps the block selectable via GrapesJS).
                    else if (blockType === 'autocomplete') {
                        const collection = (el.getAttribute('data-pb-collection') || '').trim();
                        if (! collection) { return; }
                        const labelField = (el.getAttribute('data-pb-label-field') || '').trim();
                        // Fetch schema for display_field fallback, then first few rows.
                        Promise.all([
                            fetch(apiBase + '/' + collection + '/schema', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                                .then((r) => r.ok ? r.json() : null).catch(() => null),
                            fetch(apiBase + '/' + collection + '?per_page=5', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                                .then((r) => r.ok ? r.json() : null).catch(() => null),
                        ]).then(([schema, data]) => {
                            try {
                                const rows = (data && data.data) || [];
                                if (! rows.length) { return; }
                                const dispField = labelField || (schema && schema.display_field) || 'name';
                                const items = rows.map((row) => {
                                    const label = row[dispField] != null ? String(row[dispField]) : ('#' + row.id);
                                    return '<div style="padding:.3rem .65rem;font-size:.82rem;color:#1e293b;border-bottom:1px solid #f1f5f9;">' + pbEsc(label) + '</div>';
                                }).join('');
                                const html = '<div style="border:1px solid #e2e8f0;border-radius:.375rem;background:#fff;overflow:hidden;margin-top:.25rem;">'
                                    + '<div style="padding:.25rem .65rem;font-size:.7rem;color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;background:#f8fafc;">Sample suggestions</div>'
                                    + items + '</div>';
                                pbInjectLive(el, html);
                            } catch (e) { /* leave static template */ }
                        });
                    }
                });

                // ── select[data-pb-options] ───────────────────────────────────
                // Populates a preview of the real <select> options from the bound
                // collection. These elements carry data-pb-options (not data-pb-block)
                // so they are handled after the main block loop.
                doc.querySelectorAll('select[data-pb-options]').forEach((selEl) => {
                    try {
                        const collection = (selEl.getAttribute('data-pb-options') || '').trim();
                        if (! collection) { return; }
                        const labelField = (selEl.getAttribute('data-pb-label-field') || 'name').trim();
                        const parent = selEl.parentNode;
                        if (! parent) { return; }
                        const host = selEl.offsetParent || parent;
                        // Skip if this select already has a current overlay (unchanged
                        // config) — no refetch/re-render, so no flicker.
                        const sig = pbSig(selEl);
                        const existingSel = Array.from(host.children).find((c) => c.__pbForSel === selEl && c.getAttribute && c.getAttribute('data-pb-live') === 'select');
                        if (existingSel && existingSel.getAttribute('data-pb-sig') === sig) { return; }
                        if (existingSel) { existingSel.remove(); }
                        fetch(apiBase + '/' + collection + '?per_page=5', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                            .then((r) => r.ok ? r.json() : null).catch(() => null)
                            .then((d) => {
                                try {
                                    const rows = (d && d.data) || [];
                                    if (! rows.length) { return; }
                                    // Show a CLOSED dropdown look (first real option + ▾) overlaid on
                                    // the select — NOT an open option list (which read as an always-open
                                    // dropdown). Read-only + non-persisted: a data-pb-live div (stripped on
                                    // export) positioned over the select; the <select> itself is untouched,
                                    // pointer-events:none so a click still selects the block in GrapesJS.
                                    const oh = selEl.offsetParent; if (! oh) { return; }
                                    const first = rows[0][labelField] != null ? String(rows[0][labelField]) : ('#' + rows[0].id);
                                    const wrap = selEl.ownerDocument.createElement('div');
                                    wrap.setAttribute('data-pb-live', 'select');
                                    wrap.setAttribute('data-pb-sig', sig);
                                    wrap.__pbForSel = selEl;
                                    wrap.style.cssText = 'position:absolute;pointer-events:none;box-sizing:border-box;z-index:1;'
                                        + 'left:' + selEl.offsetLeft + 'px;top:' + selEl.offsetTop + 'px;'
                                        + 'width:' + selEl.offsetWidth + 'px;height:' + selEl.offsetHeight + 'px;'
                                        + 'display:flex;align-items:center;justify-content:space-between;gap:.5rem;'
                                        + 'padding:0 .65rem;background:#fff;border:1px solid #cbd5e1;border-radius:.375rem;'
                                        + 'font:inherit;font-size:.9rem;color:#1e293b;overflow:hidden;';
                                    wrap.innerHTML = '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + pbEsc(first) + '</span><span style="color:#94a3b8;">&#9662;</span>';
                                    oh.appendChild(wrap);
                                } catch (e) { /* leave static template */ }
                            });
                    } catch (e) { /* leave static template */ }
                });
            };

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

                // Livewire path of the sibling `custom_css` form field. Filament
                // nests form state under a wrapper (e.g. `data.project_data` for the
                // editor field), so custom_css lives at the same prefix
                // (`data.custom_css`) — NOT the bare name. Replacing the last
                // dotted segment derives it from the editor's own state path.
                cssStatePath() {
                    const p = config.statePath || '';
                    return p.indexOf('.') !== -1 ? p.replace(/[^.]+$/, 'custom_css') : 'custom_css';
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

                    // Custom trait type: a checkbox group that persists a
                    // comma-separated attribute value. Used for "Visible to roles"
                    // so component visibility is picked from real roles (tick the
                    // ones that may see it), never free-typed slugs. Reads/writes the
                    // plain attribute the runtime already understands
                    // (e.g. data-pb-roles="admin,manager").
                    editor.TraitManager.addType('pb-checkboxes', {
                        createInput({ trait }) {
                            const wrap = document.createElement('div');
                            wrap.style.cssText = 'display:flex;flex-direction:column;gap:5px;padding:2px 0;';
                            const opts = trait.get('options') || [];
                            if (! opts.length) {
                                wrap.style.cssText += 'font-size:11px;color:#94a3b8;';
                                wrap.textContent = 'No roles defined yet.';
                                return wrap;
                            }
                            opts.forEach((o) => {
                                const label = document.createElement('label');
                                label.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;';
                                const cb = document.createElement('input');
                                cb.type = 'checkbox';
                                cb.value = o.id;
                                // GrapesJS's trait CSS forces inputs to width:100%,
                                // which stretches a native checkbox into a bar that
                                // reads like a broken multi-select. Pin it to a real
                                // checkbox box (inline beats the stylesheet).
                                cb.style.cssText = 'width:15px;height:15px;min-width:15px;flex:0 0 auto;margin:0;padding:0;accent-color:#6366f1;cursor:pointer;appearance:auto;-webkit-appearance:checkbox;';
                                label.appendChild(cb);
                                const span = document.createElement('span');
                                span.textContent = o.name;
                                label.appendChild(span);
                                wrap.appendChild(label);
                            });
                            return wrap;
                        },
                        onEvent({ elInput, component, trait }) {
                            const name = trait.get('name');
                            const vals = Array.from(elInput.querySelectorAll('input[type=checkbox]'))
                                .filter((c) => c.checked).map((c) => c.value).filter(Boolean);
                            if (vals.length) { component.addAttributes({ [name]: vals.join(',') }); }
                            else { component.removeAttributes(name); }
                        },
                        onUpdate({ elInput, component, trait }) {
                            const name = trait.get('name');
                            const cur = String(component.getAttributes()[name] || '').split(',').map((s) => s.trim()).filter(Boolean);
                            Array.from(elInput.querySelectorAll('input[type=checkbox]')).forEach((c) => { c.checked = cur.includes(c.value); });
                        },
                    });

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
                            // Base reset + THEME FONT on the canvas body, matching the
                            // rendered page. Injected after GrapesJS's own canvas CSS so
                            // the theme font (var(--pb-font)) wins over GrapesJS's default
                            // — otherwise the editor previews in the wrong typeface.
                            s.innerHTML = 'html,body{font-family:var(--pb-font,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif)}body{margin:0}'
                                + '[x-cloak]{display:none !important}[data-pb-block]{position:relative}[data-pb-block]::after{content:"";position:absolute;inset:0;background:var(--pb-overlay,transparent);pointer-events:none;z-index:0}[data-pb-block]>*{position:relative;z-index:1}';
                            doc.head.appendChild(s);
                            // Inject the page's custom_css into the canvas so the
                            // visual editor matches the real rendered page (WYSIWYG).
                            // A dedicated <style id="pb-custom-css"> is appended last
                            // so it wins over component rules, matching render order.
                            const cs = doc.createElement('style');
                            cs.id = 'pb-custom-css';
                            cs.textContent = this.$wire.get(this.cssStatePath()) || '';
                            doc.head.appendChild(cs);
                        } catch (e) { /* no-op */ }

                        // Live update: watch the custom_css Livewire state and push
                        // changes into the canvas <style> without a full reload.
                        // $wire.$watch fires whenever Livewire syncs the property,
                        // which happens ~300 ms after the Ace editor's change event
                        // calls $wire.set('custom_css', …, false). The debounce here
                        // guards against rapid keystrokes arriving before Livewire's
                        // own deferred set has settled.
                        let pbCustomCssT = null;
                        try {
                            this.$wire.$watch(this.cssStatePath(), (val) => {
                                clearTimeout(pbCustomCssT);
                                pbCustomCssT = setTimeout(() => {
                                    try {
                                        const doc = editor.Canvas.getDocument();
                                        let el = doc.getElementById('pb-custom-css');
                                        if (! el) {
                                            el = doc.createElement('style');
                                            el.id = 'pb-custom-css';
                                            doc.head.appendChild(el);
                                        }
                                        el.textContent = val || '';
                                    } catch (e) { /* canvas not ready */ }
                                }, 300);
                            });
                        } catch (e) { /* $wire.$watch unavailable in this context */ }

                        // ── Live data preview ────────────────────────────────
                        // For each [data-pb-block] element in the canvas that is
                        // a data block (data_table / kpi / record_picker), fetch
                        // real data from the same-origin API and render it into a
                        // dedicated <div data-pb-live> child. That child is:
                        //  • pointer-events:none  → never intercepts editor clicks
                        //  • NOT a GrapesJS component → not serialized by getHtml()
                        //  • also stripped in getHtml() export path via pbToAlpine
                        //    (see stripping below) — belt-and-suspenders
                        // The block element's own template content is left untouched;
                        // the live container is appended as a sibling overlay so
                        // GrapesJS still tracks the block as one selectable/draggable
                        // unit (clicks bubble up through the pointer-events:none overlay
                        // to the underlying block element → GrapesJS picks it up).
                        pbLivePreview(editor);
                    });

                    // Entrance-animation trait, offered on every selected component
                    // (sets data-pb-anim; the rendered page animates it on scroll).
                    const PB_ANIMS = [['', 'None'], ['fade', 'Fade'], ['fade-up', 'Fade up'], ['fade-down', 'Fade down'], ['fade-left', 'Fade left'], ['fade-right', 'Fade right'], ['zoom-in', 'Zoom in']];
                    // DOM events a component can trigger a flow on.
                    const PB_EVENTS = [['click', 'Click'], ['dblclick', 'Double click'], ['mouseenter', 'Mouse enter (hover)'], ['mouseleave', 'Mouse leave'], ['mouseover', 'Mouse over'], ['focus', 'Focus'], ['blur', 'Blur'], ['keydown', 'Key down'], ['keyup', 'Key up'], ['change', 'Change'], ['input', 'Input'], ['submit', 'Submit']];
                    const addAnimTraits = (cmp) => {
                        const names = cmp.getTraits().map((t) => t.get('name'));
                        if (! names.includes('data-pb-anim')) {
                            cmp.addTrait({ type: 'select', name: 'data-pb-anim', category: 'Appearance', label: 'Animation', options: PB_ANIMS.map(([id, name]) => ({ id, name })) });
                            cmp.addTrait({ type: 'text', name: 'data-pb-anim-delay', category: 'Appearance', label: 'Anim delay (ms)', placeholder: '0' });
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
                            cmp.addTrait({ type: 'select', name: 'data-pb-flow', category: 'Actions', label: 'Run flow', options: flowOptions });
                            cmp.addTrait({
                                type: 'select',
                                name: 'data-pb-flow-event', category: 'Actions',
                                label: 'On event',
                                options: PB_EVENTS.map(([id, name]) => ({ id, name })),
                            });
                            cmp.addTrait({ type: 'select', name: 'data-pb-page', category: 'Actions', label: 'Link to page', options: pageOptions });
                            // Log the end-user out on click (ends the pb session,
                            // returns to the login page) — works on any element.
                            cmp.addTrait({
                                type: 'select',
                                name: 'data-pb-logout', category: 'Actions',
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
                            cmp.addTrait({ type: 'select', name: 'x-text', category: 'Data', label: 'Bind text → State', options: stateOptions });
                            cmp.addTrait({ type: 'select', name: 'x-show', category: 'Data', label: 'Show when (State)', options: stateOptions });
                            cmp.addTrait({ type: 'select', name: 'x-model', category: 'Data', label: 'Two-way input ↔ State', options: stateOptions });
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
                            cmp.addTrait({ type: 'select', name: 'data-pb-record', category: 'Data', label: 'On submit → create record in', options: collectionOptions });
                        }

                        // Form controls — the standard field configuration that was
                        // missing (only generic traits showed before). A trait whose
                        // `name` matches an HTML attribute is synced by GrapesJS
                        // straight to that attribute on the selected input/textarea/
                        // select. Placeholder is input/textarea only; Input type is
                        // <input> only. Sanitizer keeps these attributes (defaults to
                        // allow), so they persist to the published page.
                        const pbTag = String(cmp.get('tagName') || '').toLowerCase();
                        if ((pbTag === 'input' || pbTag === 'textarea' || pbTag === 'select') && ! names.includes('required')) {
                            if (pbTag !== 'select') {
                                cmp.addTrait({ type: 'text', name: 'placeholder', category: 'Content', label: 'Placeholder' });
                            }
                            cmp.addTrait({ type: 'text', name: 'name', category: 'Content', label: 'Field name' });
                            cmp.addTrait({ type: 'checkbox', name: 'required', category: 'Validation', label: 'Required' });
                            if (pbTag === 'input') {
                                cmp.addTrait({ type: 'select', name: 'type', category: 'Validation', label: 'Input type', options: [
                                    { id: 'text', name: 'Text' }, { id: 'email', name: 'Email' }, { id: 'number', name: 'Number' },
                                    { id: 'tel', name: 'Phone' }, { id: 'url', name: 'URL' }, { id: 'password', name: 'Password' },
                                    { id: 'date', name: 'Date' }, { id: 'time', name: 'Time' }, { id: 'search', name: 'Search' },
                                ] });
                            }
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
                            cmp.addTrait({ type: 'select', name: 'pb-collection', category: 'Data', label: 'Collection', changeProp: true, options: collectionOptions });
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
                            cmp.addTrait({ type: 'select', name: 'pb-list-source', category: 'Data', label: 'List source (State)', changeProp: true, options: stateArrayOptions });
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
                            component.addTrait({ type: 'select', name: traitName, label: label, category: 'Data', options: options }, { at: idx });
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
                            cmp.addTrait({ type: 'select', name: 'data-pb-collection', category: 'Data', label: 'Collection', options: collectionOptions });
                            cmp.addTrait({ type: 'select', name: 'data-pb-metric', category: 'Data', label: 'Metric', options: ['count', 'sum', 'avg', 'min', 'max'].map((m) => ({ id: m, name: m })) });
                            cmp.addTrait({ type: 'select', name: 'data-pb-field', category: 'Data', label: 'Field (sum/avg/min/max)', options: pbFieldOptions(curCollection, 'column', { numericOnly: true }) });
                            if (pbBlock === 'chart') {
                                cmp.addTrait({ type: 'select', name: 'data-pb-group', category: 'Data', label: 'Group by (field)', options: pbFieldOptions(curCollection, 'column') });
                                cmp.addTrait({ type: 'select', name: 'data-pb-date-bucket', category: 'Data', label: 'Date bucket', options: [{ id: '', name: '— none —' }, { id: 'day', name: 'Day' }, { id: 'week', name: 'Week' }, { id: 'month', name: 'Month' }, { id: 'year', name: 'Year' }] });
                                cmp.addTrait({ type: 'select', name: 'data-pb-chart-type', category: 'Data', label: 'Chart type', options: ['bar', 'line', 'area', 'donut', 'pie'].map((t) => ({ id: t, name: t })) });
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
                            cmp.addTrait({ type: 'text', name: 'data-pb-embed-url', category: 'Content', label: 'Embed URL (YouTube, Vimeo, Maps, any page)' });
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
                            cmp.addTrait({ type: 'select', name: 'data-pb-collection', category: 'Data', label: 'Collection', options: acCollections });
                            cmp.addTrait({ type: 'select', name: 'data-pb-label-field', category: 'Data', label: 'Label field', options: pbFieldOptions(acCollection, 'name', { emptyLabel: '— name (default) —' }) });
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

                        // ── data_table extra config traits ────────────────────
                        // These are plain data-pb-* attributes — the runtime reads
                        // them directly from the DOM element, so no changeProp rewrite
                        // is needed. Guard on data-pb-block=data_table AND on a
                        // sentinel trait name so they are added only once.
                        if (pbBlock === 'data_table' && ! names.includes('data-pb-columns')) {
                            cmp.addTrait({ type: 'text', name: 'data-pb-columns', category: 'Data', label: 'Columns (key or key:Header, comma-sep; blank = all)' });
                            cmp.addTrait({ type: 'text', name: 'data-pb-hide', category: 'Data', label: 'Hide columns (comma-sep)' });
                            cmp.addTrait({
                                type: 'select', name: 'data-pb-sortable', category: 'Data', label: 'Sortable headers',
                                options: [{ id: '', name: 'Yes (default)' }, { id: 'false', name: 'No' }],
                            });
                            cmp.addTrait({
                                type: 'select', name: 'data-pb-searchable', category: 'Data', label: 'Search box',
                                options: [{ id: '', name: 'No (default)' }, { id: 'true', name: 'Yes' }],
                            });
                            cmp.addTrait({ type: 'text', name: 'data-pb-filters', category: 'Data', label: 'Filter fields (comma-sep)' });
                            cmp.addTrait({
                                type: 'select', name: 'data-pb-selectable', category: 'Data', label: 'Row select + bulk',
                                options: [{ id: '', name: 'No (default)' }, { id: 'true', name: 'Yes' }],
                            });
                            cmp.addTrait({ type: 'text', name: 'data-pb-bulk', category: 'Data', label: 'Bulk actions (action:Label, comma-sep)' });
                            cmp.addTrait({ type: 'text', name: 'data-pb-per-page', category: 'Data', label: 'Rows per page', placeholder: '20' });
                        }

                        // ── select — bind options from a collection ───────────
                        // data-pb-options  : which collection to fetch options from.
                        // data-pb-label-field : which field of that collection to use
                        //   as the option label — populated as a dependent dropdown
                        //   (reuses pbFieldOptions + pbReaddSelect, same pattern as
                        //   autocomplete above). Sentinel: 'data-pb-options'.
                        // The select BLOCK is a <label data-pb-block="select"> wrapping
                        // a <select> child; the runtime reads data-pb-options from the
                        // INNER <select>, so the trait must write there — not on the
                        // wrapper (the old bug: trait set the attr on the label, which
                        // the runtime never reads → "select has no configuration"). Also
                        // covers a bare AI-generated <select> (no wrapper).
                        const pbSelInner = (cmp.get('tagName') === 'select') ? cmp : (cmp.find('select')[0] || null);
                        if (pbSelInner && (pbBlock === 'select' || cmp.get('tagName') === 'select') && ! names.includes('pb-opt-collection')) {
                            const selCollections = [{ id: '', name: '— none —' }].concat(
                                (window.__pbCollections || []).map((c) => ({ id: c.key, name: c.name + ' (' + c.key + ')' }))
                            );
                            const curCol = pbSelInner.getAttributes()['data-pb-options'] || '';
                            const curLbl = pbSelInner.getAttributes()['data-pb-label-field'] || '';
                            cmp.set('pb-opt-collection', curCol);
                            cmp.set('pb-opt-label', curLbl);
                            cmp.addTrait({ type: 'select', name: 'pb-opt-collection', category: 'Data', label: 'Options from collection', changeProp: true, options: selCollections });
                            cmp.addTrait({ type: 'select', name: 'pb-opt-label', category: 'Data', label: 'Label field', changeProp: true, options: pbFieldOptions(curCol, 'name', { emptyLabel: '— name (default) —' }) });
                            cmp.on('change:pb-opt-collection', () => {
                                const col = cmp.get('pb-opt-collection') || '';
                                const s = (cmp.get('tagName') === 'select') ? cmp : cmp.find('select')[0];
                                if (s) { s.addAttributes({ 'data-pb-options': col }); }
                                pbReaddSelect(cmp, 'pb-opt-label', 'Label field', pbFieldOptions(col, 'name', { emptyLabel: '— name (default) —' }));
                            });
                            cmp.on('change:pb-opt-label', () => {
                                const s = (cmp.get('tagName') === 'select') ? cmp : cmp.find('select')[0];
                                if (s) { s.addAttributes({ 'data-pb-label-field': cmp.get('pb-opt-label') || 'name' }); }
                            });
                        }

                        // ── record_picker — search a collection, add picks to state
                        // Sentinel: 'data-pb-collection' (on this block type).
                        if (pbBlock === 'record_picker' && ! names.includes('data-pb-collection')) {
                            const rpCollections = [{ id: '', name: '— none —' }].concat(
                                (window.__pbCollections || []).map((c) => ({ id: c.key, name: c.name + ' (' + c.key + ')' }))
                            );
                            const rpCollection = cmp.getAttributes()['data-pb-collection'] || '';
                            // data-pb-target: the state key to push picked records into.
                            // Offered as a dropdown of all state variables (any type —
                            // the runtime pushes the record into the named array).
                            const rpStateOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbStates || []).map((s) => ({ id: s.key, name: s.key + ' · ' + (s.type || 'any') }))
                            );
                            cmp.addTrait({ type: 'select', name: 'data-pb-collection', category: 'Data', label: 'Search collection', options: rpCollections });
                            cmp.addTrait({ type: 'select', name: 'data-pb-target', category: 'Data', label: 'Add picks to state', options: rpStateOptions });
                            cmp.addTrait({
                                type: 'select', name: 'data-pb-label-field', category: 'Data', label: 'Label field',
                                options: pbFieldOptions(rpCollection, 'name', { emptyLabel: '— name (default) —' }),
                            });
                            cmp.addTrait({
                                type: 'select', name: 'data-pb-image-field', category: 'Data', label: 'Image field (optional)',
                                options: pbFieldOptions(rpCollection, 'name', { emptyLabel: '— none —' }),
                            });
                            cmp.addTrait({
                                type: 'select', name: 'data-pb-extra-field', category: 'Data', label: 'Extra field (optional)',
                                options: pbFieldOptions(rpCollection, 'name', { emptyLabel: '— none —' }),
                            });
                            // Re-populate all three field selects when the collection
                            // changes (same change:attributes guard pattern).
                            let rpLastCollection = rpCollection;
                            cmp.on('change:attributes', () => {
                                const col = cmp.getAttributes()['data-pb-collection'] || '';
                                if (col === rpLastCollection) { return; }
                                rpLastCollection = col;
                                pbReaddSelect(cmp, 'data-pb-label-field', 'Label field', pbFieldOptions(col, 'name', { emptyLabel: '— name (default) —' }));
                                pbReaddSelect(cmp, 'data-pb-image-field', 'Image field (optional)', pbFieldOptions(col, 'name', { emptyLabel: '— none —' }));
                                pbReaddSelect(cmp, 'data-pb-extra-field', 'Extra field (optional)', pbFieldOptions(col, 'name', { emptyLabel: '— none —' }));
                            });
                        }

                        // ── stepper — numeric increment/decrement bound to state ─
                        // Sentinel: 'data-pb-state' (on stepper block type).
                        if (pbBlock === 'stepper' && ! names.includes('data-pb-state')) {
                            const stepStateOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbStates || []).map((s) => ({ id: s.key, name: s.key + ' · ' + (s.type || 'any') }))
                            );
                            cmp.addTrait({ type: 'select', name: 'data-pb-state', category: 'Data', label: 'State key', options: stepStateOptions });
                            cmp.addTrait({ type: 'text', name: 'data-pb-min', category: 'Data', label: 'Min value', placeholder: '0' });
                            cmp.addTrait({ type: 'text', name: 'data-pb-max', category: 'Data', label: 'Max value', placeholder: '' });
                            cmp.addTrait({ type: 'text', name: 'data-pb-step', category: 'Data', label: 'Step', placeholder: '1' });
                        }

                        // ── editable_grid — tabular editor bound to a state array ─
                        // Sentinel: 'data-pb-state' (on editable_grid block type).
                        if (pbBlock === 'editable_grid' && ! names.includes('data-pb-state')) {
                            const egStateOptions = [{ id: '', name: '— none —' }].concat(
                                (window.__pbStates || [])
                                    .filter((s) => ! s.type || s.type === 'array' || s.type === 'json')
                                    .map((s) => ({ id: s.key, name: s.key + ' · ' + (s.type || 'array') }))
                            );
                            cmp.addTrait({ type: 'select', name: 'data-pb-state', category: 'Data', label: 'State array', options: egStateOptions });
                            // Qty / price / max are field references or plain numbers;
                            // offered as text inputs (v1) — the runtime reads them as
                            // column keys or literal numeric strings.
                            cmp.addTrait({ type: 'text', name: 'data-pb-qty', category: 'Data', label: 'Qty field / key', placeholder: 'qty' });
                            cmp.addTrait({ type: 'text', name: 'data-pb-price', category: 'Data', label: 'Price field / key', placeholder: 'price' });
                            cmp.addTrait({ type: 'text', name: 'data-pb-max', category: 'Data', label: 'Max rows', placeholder: '' });
                        }

                        // ── Auth visibility — shown on ANY component (general pass) ─
                        // data-pb-auth  : restrict the element to logged-in users.
                        // data-pb-roles : further restrict to specific role slugs.
                        // Sentinel: 'data-pb-auth'.
                        if (! names.includes('data-pb-auth')) {
                            cmp.addTrait({
                                type: 'select', name: 'data-pb-auth', category: 'Access', label: 'Only when logged in',
                                options: [{ id: '', name: 'No (show always)' }, { id: '1', name: 'Yes — authenticated only' }],
                            });
                            cmp.addTrait({
                                type: 'pb-checkboxes', name: 'data-pb-roles', category: 'Access', label: 'Visible to roles',
                                options: (window.__pbRoles || []).map((r) => ({ id: r.slug, name: r.name + ' (' + r.slug + ')' })),
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
                        // restore real Alpine for the published snapshot, then strip
                        // any [data-pb-live] overlay nodes that leaked into the
                        // serialised html (belt-and-suspenders for constraint #1 —
                        // GrapesJS does not track these nodes but this guarantees
                        // clean output even if a future GrapesJS version did).
                        html: pbStripLive(pbToAlpine(editor.getHtml())),
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

                    // Re-run live data preview when GrapesJS mounts or updates a
                    // component. GrapesJS re-renders a component's DOM element when
                    // it receives attribute/style changes — this wipes any injected
                    // [data-pb-live] child DOM — so we re-inject after each mount/
                    // update. Debounced to avoid a flood during bulk re-renders
                    // (e.g. on initial load where all components mount in sequence).
                    let pbLiveT = null;
                    const pbLiveSoon = () => {
                        clearTimeout(pbLiveT);
                        pbLiveT = setTimeout(() => { try { pbLivePreview(editor); } catch (e) { /* no-op */ } }, 400);
                    };
                    // component:remove included so deleting/duplicating a block wipes +
                    // rebuilds overlays (no orphaned "ghost" preview lingering).
                    editor.on('component:mount component:update component:remove', pbLiveSoon);

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
