<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Demo;

use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Models\PbPermission;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Andre\AiPageBuilder\Services\Data\SchemaSynchronizer;
use Andre\AiPageBuilder\Services\Data\VariableStore;

/**
 * A full, role-gated **Inventory** CRUD app built entirely from the package's
 * own primitives — collections (real tables + REST), a reactive dashboard page
 * (stat cards, a live data table, an "add product" modal), persistent States, a
 * Function, an end-user Role/permission, and a branching/fan-out Flow that
 * reacts to stock movements. Owner-authored (trusted) HTML — full Alpine.
 */
class InventoryDemo
{
    public function __construct(
        private readonly SchemaSynchronizer $schema,
        private readonly RecordQuery $records,
        private readonly VariableStore $states,
    ) {}

    public function build(): void
    {
        $products = $this->collections();
        $this->seed($products);
        $this->statesAndFunction();
        $this->roles();
        $this->flow();
        $this->dashboardPage();
    }

    private function collection(string $key, string $name, array $fields): PbModel
    {
        /** @var PbModel $model */
        $model = PbModel::query()->updateOrCreate(
            ['key' => $key],
            ['name' => $name, 'table_name' => PbModel::physicalTableName($key), 'has_timestamps' => true],
        );
        $model->fields()->delete();
        foreach (array_values($fields) as $i => $f) {
            $model->fields()->create($f + ['sort' => $i]);
        }
        $this->schema->sync($model->fresh());

        return $model->fresh();
    }

    private function collections(): PbModel
    {
        $this->collection('categories', 'Categories', [
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
        ]);
        $this->collection('suppliers', 'Suppliers', [
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
            ['key' => 'email', 'label' => 'Email', 'type' => 'string'],
        ]);
        // Stock movements drive the on-stock-movement flow (collection trigger).
        $this->collection('stock_movements', 'Stock movements', [
            ['key' => 'product_sku', 'label' => 'Product SKU', 'type' => 'string'],
            ['key' => 'change', 'label' => 'Change', 'type' => 'integer'],
            ['key' => 'reason', 'label' => 'Reason', 'type' => 'string'],
            ['key' => 'supplier_email', 'label' => 'Supplier email', 'type' => 'string'],
        ]);

        return $this->collection('products', 'Products', [
            ['key' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['required' => true]],
            ['key' => 'sku', 'label' => 'SKU', 'type' => 'string', 'options' => ['required' => true]],
            ['key' => 'category', 'label' => 'Category', 'type' => 'string'],
            ['key' => 'price', 'label' => 'Unit price', 'type' => 'decimal'],
            ['key' => 'quantity', 'label' => 'In stock', 'type' => 'integer'],
            ['key' => 'reorder_level', 'label' => 'Reorder at', 'type' => 'integer'],
            ['key' => 'supplier_email', 'label' => 'Supplier email', 'type' => 'string'],
        ]);
    }

    private function seed(PbModel $products): void
    {
        if ($this->records->list($products, [])->total() > 0) {
            return;
        }

        $rows = [
            ['Aurora Desk Lamp', 'LMP-001', 'Lighting', 49.00, 38, 10, 'sales@lumico.test'],
            ['Nimbus Office Chair', 'CHR-014', 'Furniture', 219.00, 6, 8, 'orders@seatwell.test'],
            ['Halo Monitor 27"', 'MON-027', 'Electronics', 329.00, 21, 6, 'b2b@viewpoint.test'],
            ['Drift Standing Desk', 'DSK-200', 'Furniture', 540.00, 3, 5, 'orders@seatwell.test'],
            ['Pulse Mechanical Keyboard', 'KBD-061', 'Electronics', 119.00, 54, 15, 'b2b@viewpoint.test'],
            ['Cove Desk Organizer', 'ORG-009', 'Accessories', 24.00, 0, 12, 'sales@lumico.test'],
            ['Beacon LED Strip', 'LMP-118', 'Lighting', 32.00, 87, 20, 'sales@lumico.test'],
            ['Atlas Laptop Stand', 'STD-044', 'Accessories', 59.00, 9, 10, 'b2b@viewpoint.test'],
        ];
        foreach ($rows as [$name, $sku, $cat, $price, $qty, $reorder, $supplier]) {
            $this->records->create($products, [
                'name' => $name, 'sku' => $sku, 'category' => $cat, 'price' => $price,
                'quantity' => $qty, 'reorder_level' => $reorder, 'supplier_email' => $supplier,
            ]);
        }
    }

    private function statesAndFunction(): void
    {
        $this->states->set('low_stock_count', 3, 'number');
        $this->states->set('last_activity', 'Seeded 8 products', 'string');

        FlowFunction::query()->updateOrCreate(
            ['slug' => 'line-value'],
            [
                'name' => 'Line value',
                'description' => 'Inventory value of a stock line (quantity × unit price).',
                'runtime' => 'expression',
                'body' => 'quantity * price',
            ],
        );

        // Called by the on-stock-movement flow to suggest a reorder quantity
        // from the (negative) movement size.
        FlowFunction::query()->updateOrCreate(
            ['slug' => 'restock-suggestion'],
            [
                'name' => 'Restock suggestion',
                'description' => 'Suggested reorder units from a stock movement.',
                'runtime' => 'expression',
                'body' => 'change * -2',
            ],
        );
    }

    private function roles(): void
    {
        $manager = PbRole::query()->updateOrCreate(['slug' => 'manager'], ['name' => 'Inventory Manager', 'is_admin' => true]);
        $staff = PbRole::query()->updateOrCreate(['slug' => 'staff'], ['name' => 'Warehouse Staff', 'is_admin' => false]);

        // Staff may only READ products (manager is_admin bypasses everything),
        // which also marks the products collection restricted.
        PbPermission::query()->updateOrCreate(
            ['role_id' => $staff->id, 'resource_type' => 'collection', 'resource_key' => 'products', 'action' => 'read'],
            ['rule' => null],
        );

        PbUser::query()->updateOrCreate(
            ['email' => 'manager@nimbus.test'],
            ['name' => 'Mara Okonkwo', 'password' => 'password', 'role_id' => $manager->id, 'is_active' => true],
        );
        PbUser::query()->updateOrCreate(
            ['email' => 'staff@nimbus.test'],
            ['name' => 'Theo Park', 'password' => 'password', 'role_id' => $staff->id, 'is_active' => true],
        );
    }

    /**
     * A branching, fan-out flow that fires when a stock movement is recorded:
     *   trigger ─┬─► log (set last_activity state)
     *            └─► low? (qty change < 0) ─true─┬─► email supplier
     *                                            └─► notify "reorder"
     * Demonstrates fan-out at the trigger AND parallel branches off a condition.
     */
    private function flow(): void
    {
        Flow::query()->updateOrCreate(
            ['slug' => 'on-stock-movement'],
            [
                'name' => 'On stock movement',
                'trigger_type' => 'collection',
                'trigger_config' => ['collection' => 'stock_movements', 'events' => ['created']],
                'is_active' => true,
                'definition' => [
                    'start' => 't',
                    'nodes' => [
                        't' => ['type' => 'trigger', 'next' => ['log', 'low']],
                        'log' => ['type' => 'set_variable', 'config' => [
                            'key' => 'last_activity', 'type' => 'string',
                            'value' => '{{ input.record.reason }} ({{ input.record.change }})',
                        ]],
                        'low' => ['type' => 'condition', 'config' => [
                            'left' => '{{ input.record.change }}', 'op' => 'lt', 'right' => '0',
                        ], 'next_true' => ['suggest'], 'next_false' => []],
                        // Call a Function to compute a reorder quantity, then fan
                        // out to email the supplier AND notify in-app.
                        'suggest' => ['type' => 'function', 'config' => [
                            'function' => 'restock-suggestion',
                            'args' => ['change' => '{{ input.record.change }}'],
                            'output' => 'units',
                        ], 'next' => ['email', 'notify']],
                        'email' => ['type' => 'send_email', 'config' => [
                            'to' => '{{ input.record.supplier_email }}',
                            'subject' => 'Reorder needed: {{ input.record.product_sku }}',
                            'body' => '<p>Stock for <b>{{ input.record.product_sku }}</b> moved by {{ input.record.change }}. Suggested reorder: <b>{{ vars.units }}</b> units.</p>',
                            'output' => 'mail',
                        ]],
                        'notify' => ['type' => 'result', 'config' => ['actions' => [
                            ['type' => 'setState', 'key' => 'last_activity', 'value' => 'Reorder {{ vars.units }} × {{ input.record.product_sku }}'],
                            ['type' => 'notify', 'message' => 'Reorder ~{{ vars.units }} units of {{ input.record.product_sku }}', 'level' => 'warning'],
                        ]]],
                    ],
                ],
            ],
        );
    }

    private function dashboardPage(): void
    {
        Page::query()->updateOrCreate(
            ['slug' => 'inventory'],
            [
                'title' => 'Nimbus Inventory',
                'kind' => 'page',
                'status' => 'published',
                'requires_auth' => true,
                'html' => $this->dashboardHtml(),
                'css' => $this->dashboardCss(),
            ],
        );
    }

    private function dashboardHtml(): string
    {
        return <<<'HTML'
        <div class="inv" x-data="inventoryApp()" x-init="load()">
          <header class="inv-top">
            <div class="inv-brand"><span class="inv-logo">◆</span> Nimbus <span class="inv-brand-sub">Inventory</span></div>
            <div class="inv-top-actions">
              <span class="inv-who" x-show="$store.app.$user" x-cloak>Signed in as <b x-text="$store.app.$user?.name"></b></span>
              <form method="POST" action="/pb-logout" class="inv-logout">
                <button type="submit" class="inv-btn inv-btn-ghost">Sign out</button>
              </form>
            </div>
          </header>

          <main class="inv-main">
            <div class="inv-head">
              <div>
                <h1>Stock overview</h1>
                <p class="inv-muted" x-text="'Last activity — ' + ($store.app.last_activity || '—')"></p>
              </div>
              <button class="inv-btn inv-btn-primary" @click="openCreate()">+ Add product</button>
            </div>

            <section class="inv-stats">
              <div class="inv-card inv-stat">
                <span class="inv-stat-label">Total SKUs</span>
                <span class="inv-stat-value" x-text="rows.length"></span>
              </div>
              <div class="inv-card inv-stat">
                <span class="inv-stat-label">Low / out of stock</span>
                <span class="inv-stat-value inv-warn" x-text="lowCount"></span>
              </div>
              <div class="inv-card inv-stat">
                <span class="inv-stat-label">Inventory value</span>
                <span class="inv-stat-value" x-text="money(totalValue)"></span>
              </div>
              <div class="inv-card inv-stat">
                <span class="inv-stat-label">Categories</span>
                <span class="inv-stat-value" x-text="categories.length"></span>
              </div>
            </section>

            <section class="inv-card inv-table-wrap">
              <div class="inv-toolbar">
                <input class="inv-input" type="search" placeholder="Search name or SKU…" x-model="search">
                <select class="inv-input inv-select" x-model="cat">
                  <option value="">All categories</option>
                  <template x-for="c in categories" :key="c"><option :value="c" x-text="c"></option></template>
                </select>
              </div>
              <div class="inv-table-scroll">
                <table class="inv-table">
                  <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th class="inv-r">Price</th><th class="inv-r">In stock</th><th>Status</th></tr></thead>
                  <tbody>
                    <template x-for="(r, i) in filtered" :key="r.id">
                      <tr class="inv-row" :class="status(r)==='out' ? 'inv-row-out' : (status(r)==='low' ? 'inv-row-low' : '')" :style="'animation-delay:'+(i*28)+'ms'">
                        <td class="inv-name" x-text="r.name"></td>
                        <td class="inv-mono" x-text="r.sku"></td>
                        <td x-text="r.category || '—'"></td>
                        <td class="inv-r" x-text="money(r.price)"></td>
                        <td class="inv-r" x-text="r.quantity"></td>
                        <td><span class="inv-badge" :class="'inv-badge-'+status(r)" x-text="statusLabel(r)"></span></td>
                      </tr>
                    </template>
                    <tr x-show="!loading && filtered.length===0"><td colspan="6" class="inv-empty">No products match your filters.</td></tr>
                    <tr x-show="loading"><td colspan="6" class="inv-empty">Loading…</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </main>

          <!-- Add product modal -->
          <div class="inv-modal" x-show="modal" x-cloak x-transition.opacity @keydown.escape.window="modal=false">
            <div class="inv-modal-backdrop" @click="modal=false"></div>
            <div class="inv-modal-card" x-transition>
              <div class="inv-modal-head"><h2>Add product</h2><button class="inv-x" @click="modal=false">✕</button></div>
              <div class="inv-form">
                <label>Name<input class="inv-input" x-model="form.name" placeholder="Aurora Desk Lamp"></label>
                <div class="inv-form-row">
                  <label>SKU<input class="inv-input" x-model="form.sku" placeholder="LMP-002"></label>
                  <label>Category<input class="inv-input" x-model="form.category" placeholder="Lighting"></label>
                </div>
                <div class="inv-form-row">
                  <label>Unit price<input class="inv-input" type="number" step="0.01" x-model="form.price" placeholder="49.00"></label>
                  <label>In stock<input class="inv-input" type="number" x-model="form.quantity" placeholder="25"></label>
                  <label>Reorder at<input class="inv-input" type="number" x-model="form.reorder_level" placeholder="10"></label>
                </div>
                <p class="inv-error" x-show="error" x-text="error" x-cloak></p>
              </div>
              <div class="inv-modal-foot">
                <button class="inv-btn inv-btn-ghost" @click="modal=false">Cancel</button>
                <button class="inv-btn inv-btn-primary" :disabled="saving" @click="save()" x-text="saving ? 'Saving…' : 'Save product'"></button>
              </div>
            </div>
          </div>
        </div>

        <script>
          window.inventoryApp = function () {
            return {
              rows: [], loading: true, search: '', cat: '',
              modal: false, saving: false, error: '',
              form: { name:'', sku:'', category:'', price:'', quantity:'', reorder_level:'' },
              api: (window.__pbApiBase || '/api/pb') + '/products',
              load() {
                this.loading = true;
                fetch(this.api, { headers: { Accept:'application/json' }, credentials:'same-origin' })
                  .then(r => r.json()).then(d => { this.rows = d.data || []; })
                  .catch(() => {}).finally(() => { this.loading = false; });
              },
              get categories() { return [...new Set(this.rows.map(r => r.category).filter(Boolean))].sort(); },
              get filtered() {
                const q = this.search.toLowerCase();
                return this.rows.filter(r =>
                  (!this.cat || r.category === this.cat) &&
                  (!q || (r.name||'').toLowerCase().includes(q) || (r.sku||'').toLowerCase().includes(q)));
              },
              get lowCount() { return this.rows.filter(r => this.status(r) !== 'in').length; },
              get totalValue() { return this.rows.reduce((s, r) => s + (Number(r.price)||0) * (Number(r.quantity)||0), 0); },
              status(r) { const q = Number(r.quantity)||0; if (q <= 0) return 'out'; if (q <= (Number(r.reorder_level)||0)) return 'low'; return 'in'; },
              statusLabel(r) { return { out:'Out of stock', low:'Low', in:'In stock' }[this.status(r)]; },
              money(n) { return '$' + (Number(n)||0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
              openCreate() { this.error=''; this.form = { name:'', sku:'', category:'', price:'', quantity:'', reorder_level:'' }; this.modal = true; },
              save() {
                if (!this.form.name || !this.form.sku) { this.error = 'Name and SKU are required.'; return; }
                this.saving = true; this.error = '';
                fetch(this.api, { method:'POST', headers:{ 'Content-Type':'application/json', Accept:'application/json' }, credentials:'same-origin', body: JSON.stringify(this.form) })
                  .then(async r => { if (!r.ok) throw new Error((await r.json()).message || 'Could not save'); return r.json(); })
                  .then(() => { this.modal = false; this.load(); })
                  .catch(e => { this.error = e.message; })
                  .finally(() => { this.saving = false; });
              },
            };
          };
        </script>
        HTML;
    }

    private function dashboardCss(): string
    {
        return <<<'CSS'
        [x-cloak]{display:none!important}
        .inv{min-height:100vh;background:radial-gradient(1100px 600px at 88% -8%,rgba(34,211,238,.10),transparent 60%),radial-gradient(900px 600px at -5% 108%,rgba(99,102,241,.14),transparent 60%),#0b1020;color:#e7ecf6;font-family:ui-sans-serif,system-ui,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
        .inv *{box-sizing:border-box}
        .inv-top{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.6rem;border-bottom:1px solid rgba(148,163,184,.14);position:sticky;top:0;background:rgba(11,16,32,.72);backdrop-filter:blur(10px);z-index:5}
        .inv-brand{font-weight:800;letter-spacing:-.01em;font-size:1.1rem;display:flex;align-items:center;gap:.5rem}
        .inv-logo{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;background:linear-gradient(135deg,#6366f1,#22d3ee);color:#06121f}
        .inv-brand-sub{color:#94a3b8;font-weight:600}
        .inv-top-actions{display:flex;align-items:center;gap:1rem}
        .inv-who{color:#94a3b8;font-size:.88rem}
        .inv-logout{margin:0}
        .inv-main{max-width:1120px;margin:0 auto;padding:2rem 1.6rem 4rem}
        .inv-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap}
        .inv-head h1{font-size:1.9rem;letter-spacing:-.02em;margin:0 0 .25rem}
        .inv-muted{color:#94a3b8;margin:0;font-size:.92rem}
        .inv-card{background:rgba(17,24,39,.66);border:1px solid rgba(148,163,184,.14);border-radius:16px;box-shadow:0 18px 50px -28px rgba(0,0,0,.7)}
        .inv-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.5rem}
        .inv-stat{padding:1.1rem 1.25rem;display:flex;flex-direction:column;gap:.4rem;transition:transform .15s,border-color .15s}
        .inv-stat:hover{transform:translateY(-2px);border-color:rgba(99,102,241,.4)}
        .inv-stat-label{color:#94a3b8;font-size:.8rem;font-weight:600;letter-spacing:.02em;text-transform:uppercase}
        .inv-stat-value{font-size:1.9rem;font-weight:800;letter-spacing:-.02em}
        .inv-warn{color:#fbbf24}
        .inv-table-wrap{padding:0;overflow:hidden}
        .inv-toolbar{display:flex;gap:.75rem;padding:1rem 1.1rem;border-bottom:1px solid rgba(148,163,184,.12);flex-wrap:wrap}
        .inv-input{background:rgba(2,6,23,.6);border:1px solid rgba(148,163,184,.22);color:#f1f5f9;border-radius:10px;padding:.55rem .75rem;font-size:.92rem;outline:none;transition:border-color .15s,box-shadow .15s}
        .inv-input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.25)}
        .inv-toolbar .inv-input{flex:1;min-width:180px}
        .inv-select{flex:0 0 auto;min-width:180px}
        .inv-table-scroll{overflow-x:auto}
        .inv-table{width:100%;border-collapse:collapse;font-size:.92rem}
        .inv-table th{text-align:left;color:#94a3b8;font-size:.74rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:.7rem 1.1rem;border-bottom:1px solid rgba(148,163,184,.12)}
        .inv-table td{padding:.8rem 1.1rem;border-bottom:1px solid rgba(148,163,184,.07)}
        .inv-r{text-align:right}
        .inv-name{font-weight:600}
        .inv-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#a5b4fc;font-size:.85rem}
        .inv-row{animation:invIn .4s both}
        .inv-row:hover{background:rgba(99,102,241,.06)}
        .inv-row-low{background:rgba(251,191,36,.05)}
        .inv-row-out{background:rgba(239,68,68,.06)}
        @keyframes invIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
        @media (prefers-reduced-motion:reduce){.inv-row{animation:none}}
        .inv-badge{font-size:.72rem;font-weight:700;padding:.25rem .55rem;border-radius:999px;border:1px solid transparent}
        .inv-badge-in{color:#6ee7b7;background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.3)}
        .inv-badge-low{color:#fcd34d;background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.32)}
        .inv-badge-out{color:#fca5a5;background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.32)}
        .inv-empty{text-align:center;color:#64748b;padding:2rem}
        .inv-btn{border:0;border-radius:11px;padding:.6rem 1rem;font-size:.9rem;font-weight:700;cursor:pointer;transition:transform .08s,filter .15s}
        .inv-btn:active{transform:translateY(1px)}
        .inv-btn-primary{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff}
        .inv-btn-primary:hover{filter:brightness(1.08)}
        .inv-btn-ghost{background:rgba(148,163,184,.1);color:#cbd5e1;border:1px solid rgba(148,163,184,.2)}
        .inv-modal{position:fixed;inset:0;display:grid;place-items:center;z-index:50;padding:1rem}
        .inv-modal-backdrop{position:absolute;inset:0;background:rgba(2,6,23,.66);backdrop-filter:blur(3px)}
        .inv-modal-card{position:relative;width:100%;max-width:480px;background:#0f1629;border:1px solid rgba(148,163,184,.18);border-radius:18px;padding:1.4rem;box-shadow:0 40px 90px -30px rgba(0,0,0,.8)}
        .inv-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
        .inv-modal-head h2{margin:0;font-size:1.2rem}
        .inv-x{background:none;border:0;color:#94a3b8;font-size:1.1rem;cursor:pointer}
        .inv-form{display:flex;flex-direction:column;gap:.8rem}
        .inv-form label{display:flex;flex-direction:column;gap:.35rem;font-size:.82rem;font-weight:600;color:#cbd5e1;flex:1}
        .inv-form-row{display:flex;gap:.7rem;flex-wrap:wrap}
        .inv-error{color:#fca5a5;font-size:.85rem;margin:0}
        .inv-modal-foot{display:flex;justify-content:flex-end;gap:.6rem;margin-top:1.2rem}
        CSS;
    }
}
