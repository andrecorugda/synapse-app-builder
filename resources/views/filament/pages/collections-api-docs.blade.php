@php
    $base = $this->baseUrl();
    $collections = $this->collections();
    // First collection's key powers the generic examples; fall back to a token.
    $sampleKey = $collections->first()['key'] ?? 'articles';
@endphp

<x-filament-panels::page>
    {{-- Inline styles only — this page must read well even where the host app's
         Tailwind build does not include these utilities. --}}
    <style>
        .pb-docs { font-size: 0.9rem; line-height: 1.6; color: #1f2937; }
        .dark .pb-docs { color: #e5e7eb; }
        .pb-docs h2 { font-size: 1.25rem; font-weight: 700; margin: 2rem 0 0.75rem; }
        .pb-docs h3 { font-size: 1.05rem; font-weight: 600; margin: 1.5rem 0 0.5rem; }
        .pb-docs p { margin: 0.5rem 0; }
        .pb-docs code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85em; background: rgba(148,163,184,0.18); padding: 0.1rem 0.35rem; border-radius: 0.3rem; }
        .pb-docs pre { background: #0f172a; color: #e2e8f0; padding: 0.9rem 1rem; border-radius: 0.6rem; overflow-x: auto; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.82rem; line-height: 1.5; margin: 0.5rem 0; }
        .pb-docs pre code { background: none; padding: 0; color: inherit; }
        .pb-docs table { width: 100%; border-collapse: collapse; margin: 0.5rem 0; display: block; overflow-x: auto; }
        .pb-docs th, .pb-docs td { text-align: left; padding: 0.45rem 0.7rem; border-bottom: 1px solid rgba(148,163,184,0.25); white-space: nowrap; }
        .pb-docs th { font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; color: #64748b; }
        .pb-card { border: 1px solid rgba(148,163,184,0.3); border-radius: 0.75rem; padding: 1rem 1.25rem; margin: 1rem 0; background: rgba(255,255,255,0.5); }
        .dark .pb-card { background: rgba(30,41,59,0.4); }
        .pb-method { display: inline-block; font-weight: 700; font-size: 0.72rem; padding: 0.12rem 0.5rem; border-radius: 0.35rem; margin-right: 0.5rem; color: #fff; }
        .pb-get { background: #2563eb; } .pb-post { background: #16a34a; }
        .pb-patch { background: #d97706; } .pb-delete { background: #dc2626; }
        .pb-req { color: #dc2626; font-weight: 600; }
        .pb-muted { color: #64748b; font-size: 0.85rem; }
    </style>

    <div class="pb-docs">
        <p class="pb-muted">
            A Directus-style auto REST API over your collections. Every collection is exposed
            under the base URL below and resolved by its key. Permissions and row-level rules
            are enforced per request based on the calling token's owner.
        </p>

        <h2>Base URL</h2>
        <pre><code>{{ $base }}</code></pre>

        <h2>Authentication</h2>
        <p>
            Pass a token as a <code>Bearer</code> credential. Mint tokens under
            <strong>API Tokens</strong>; the plaintext is shown once at creation.
            A token tied to an app user scopes the API to that user's permissions and
            row-level rules; an ownerless token has full access. Same-origin requests from a
            logged-in app user are also authenticated via session.
        </p>
        <pre><code>Authorization: Bearer &lt;your-token&gt;
Accept: application/json
Content-Type: application/json</code></pre>

        <h2>Endpoints</h2>
        <div class="pb-card"><span class="pb-method pb-get">GET</span><code>/{collection}</code>
            <p class="pb-muted">List records (paginated). Supports the query params below.</p></div>
        <div class="pb-card"><span class="pb-method pb-get">GET</span><code>/{collection}/{id}</code>
            <p class="pb-muted">Fetch a single record. Honours <code>fields</code> and <code>expand</code>.</p></div>
        <div class="pb-card"><span class="pb-method pb-get">GET</span><code>/{collection}/aggregate</code>
            <p class="pb-muted">Server-side aggregation (count/sum/avg/min/max), optionally grouped — for charts &amp; KPIs.</p></div>
        <div class="pb-card"><span class="pb-method pb-post">POST</span><code>/{collection}</code>
            <p class="pb-muted">Create a record. JSON body keyed by field key or column name.</p></div>
        <div class="pb-card"><span class="pb-method pb-patch">PATCH</span> / <span class="pb-method pb-patch">PUT</span><code>/{collection}/{id}</code>
            <p class="pb-muted">Update a record (partial). Only the fields you send are changed.</p></div>
        <div class="pb-card"><span class="pb-method pb-delete">DELETE</span><code>/{collection}/{id}</code>
            <p class="pb-muted">Delete a record. Returns <code>204 No Content</code>.</p></div>

        <h2>Query parameters</h2>
        <table>
            <thead><tr><th>Param</th><th>Example</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>filter[field][op]</code></td><td><code>filter[status][eq]=active</code></td>
                    <td>Filter by column. Ops: <code>eq neq gt gte lt lte like in nin null nnull between</code>.</td></tr>
                <tr><td><code>sort</code></td><td><code>sort=-created_at,name</code></td>
                    <td>Comma-separated columns; leading <code>-</code> = descending.</td></tr>
                <tr><td><code>fields</code></td><td><code>fields=id,name,email</code></td>
                    <td>Column projection (<code>id</code> always included).</td></tr>
                <tr><td><code>search</code></td><td><code>search=acme</code></td>
                    <td><code>LIKE</code> across all text/string/select fields.</td></tr>
                <tr><td><code>expand</code></td><td><code>expand=manager,tasks</code></td>
                    <td>Inline related rows — a <code>relation</code> field (belongs-to) or another collection that points back (has-many).</td></tr>
                <tr><td><code>page</code> / <code>per_page</code></td><td><code>page=2&amp;per_page=50</code></td>
                    <td>Pagination (<code>per_page</code> clamped to the configured max).</td></tr>
            </tbody>
        </table>

        <h3>Aggregate parameters</h3>
        <table>
            <thead><tr><th>Param</th><th>Example</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>metric</code></td><td><code>metric=sum</code></td><td><code>count</code> (default), <code>sum</code>, <code>avg</code>, <code>min</code>, <code>max</code>.</td></tr>
                <tr><td><code>field</code></td><td><code>field=amount</code></td><td>Column to aggregate (required for sum/avg/min/max).</td></tr>
                <tr><td><code>group_by</code></td><td><code>group_by=status</code></td><td>Column to group by (omit for a single KPI number).</td></tr>
                <tr><td><code>date_bucket</code></td><td><code>date_bucket=month</code></td><td>Bucket a date column: <code>day week month year</code>.</td></tr>
            </tbody>
        </table>

        <h3>Generic examples</h3>
        <pre><code># List, filtered &amp; sorted
curl -H "Authorization: Bearer $TOKEN" \
  "{{ $base }}/{{ $sampleKey }}?filter[status][eq]=active&sort=-created_at&per_page=20"

# Monthly totals (aggregate)
curl -H "Authorization: Bearer $TOKEN" \
  "{{ $base }}/{{ $sampleKey }}/aggregate?metric=count&group_by=status"</code></pre>

        <h2>Collections</h2>
        @if ($collections->isEmpty())
            <p class="pb-muted">No collections defined yet. Create one under <strong>Collections</strong>, then its
                endpoints and examples will appear here.</p>
        @else
            @foreach ($collections as $collection)
                <h3><code>{{ $collection['key'] }}</code> &mdash; {{ $collection['name'] }}</h3>
                @if ($collection['description'])
                    <p class="pb-muted">{{ $collection['description'] }}</p>
                @endif

                @if ($collection['fields'] === [])
                    <p class="pb-muted">No fields defined.</p>
                @else
                    <table>
                        <thead><tr><th>Field</th><th>Type</th><th>Required</th></tr></thead>
                        <tbody>
                            @foreach ($collection['fields'] as $field)
                                <tr>
                                    <td><code>{{ $field['key'] }}</code></td>
                                    <td>{{ $field['type'] }}</td>
                                    <td>@if ($field['required'])<span class="pb-req">yes</span>@else<span class="pb-muted">no</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <pre><code># List {{ $collection['key'] }}
curl -H "Authorization: Bearer $TOKEN" \
  "{{ $base }}/{{ $collection['key'] }}"

# Create a {{ $collection['key'] }} record
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "{{ $base }}/{{ $collection['key'] }}" \
  -d '{{ $this->exampleBody($collection['fields']) }}'</code></pre>
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
