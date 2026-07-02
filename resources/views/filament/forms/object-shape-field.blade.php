{{-- Recursive, nestable schema builder for an Object State's `shape`.
     Mirrors the flow step-list pattern: an imperative DOM builder synced to the
     Livewire field state as a plain JSON array. Each field row picks a type; an
     `object` field nests its own list; a `state` field reuses another state.

     The whole component lives inline in x-data (not a separate <script>): this
     partial is injected by Livewire when the "Object" type is selected, and a
     <script> added by Livewire's DOM-diff would NOT execute — but Alpine does
     initialise x-data on morphed-in nodes, so inlining keeps it reliable. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            state: $wire.$entangle('{{ $getStatePath() }}'),
            states: @js($getStateOptions()),
            data: [],

            init() {
                this.data = this.normalize(this.state);
                this.render();
            },

            normalize(value) {
                if (typeof value === 'string') { try { value = JSON.parse(value); } catch (e) { value = []; } }
                if (!Array.isArray(value)) return [];
                return value.map((f) => this.normalizeField(f)).filter(Boolean);
            },
            normalizeField(f) {
                if (!f || typeof f !== 'object') return null;
                const type = ['string','number','boolean','object','state'].includes(f.type) ? f.type : 'string';
                const out = { name: typeof f.name === 'string' ? f.name : '', type };
                if (type === 'object') out.fields = Array.isArray(f.fields) ? f.fields.map((c) => this.normalizeField(c)).filter(Boolean) : [];
                if (type === 'state') out.ref = typeof f.ref === 'string' ? f.ref : '';
                return out;
            },

            sync() { this.state = JSON.parse(JSON.stringify(this.data)); },

            render() {
                const root = this.$refs.root;
                root.innerHTML = '';
                root.appendChild(this.renderList(this.data));
            },

            renderList(fields) {
                const wrap = document.createElement('div');
                wrap.className = 'pb-object-shape__list';
                if (fields.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'pb-object-shape__empty';
                    empty.textContent = 'No fields yet.';
                    wrap.appendChild(empty);
                }
                fields.forEach((field, i) => wrap.appendChild(this.renderField(field, fields, i)));
                const add = document.createElement('button');
                add.type = 'button';
                add.className = 'pb-object-shape__btn pb-object-shape__btn--add';
                add.textContent = '+ Add field';
                add.addEventListener('click', () => { fields.push({ name: '', type: 'string' }); this.sync(); this.render(); });
                wrap.appendChild(add);
                return wrap;
            },

            renderField(field, siblings, index) {
                const box = document.createElement('div');
                const row = document.createElement('div');
                row.className = 'pb-object-shape__row';

                const name = document.createElement('input');
                name.type = 'text';
                name.className = 'pb-object-shape__name';
                name.placeholder = 'fieldName';
                name.value = field.name || '';
                name.addEventListener('input', () => { field.name = name.value; this.sync(); });
                row.appendChild(name);

                const type = document.createElement('select');
                type.className = 'pb-object-shape__type';
                [['string','String'],['number','Number'],['boolean','Boolean'],['object','Object'],['state','State (reuse)']]
                    .forEach(([val, label]) => {
                        const o = document.createElement('option');
                        o.value = val; o.textContent = label;
                        if (field.type === val) o.selected = true;
                        type.appendChild(o);
                    });
                type.addEventListener('change', () => {
                    field.type = type.value;
                    if (field.type === 'object') { if (!Array.isArray(field.fields)) field.fields = []; delete field.ref; }
                    else if (field.type === 'state') { field.ref = field.ref || ''; delete field.fields; }
                    else { delete field.fields; delete field.ref; }
                    this.sync(); this.render();
                });
                row.appendChild(type);

                if (field.type === 'state') {
                    const ref = document.createElement('select');
                    ref.className = 'pb-object-shape__ref';
                    const ph = document.createElement('option');
                    ph.value = ''; ph.textContent = 'Pick a state…';
                    ref.appendChild(ph);
                    this.states.forEach((s) => {
                        const o = document.createElement('option');
                        o.value = s.key; o.textContent = s.label;
                        if (field.ref === s.key) o.selected = true;
                        ref.appendChild(o);
                    });
                    ref.addEventListener('change', () => { field.ref = ref.value; this.sync(); });
                    row.appendChild(ref);
                }

                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'pb-object-shape__btn pb-object-shape__btn--del';
                del.textContent = '✕';
                del.title = 'Remove field';
                del.addEventListener('click', () => { siblings.splice(index, 1); this.sync(); this.render(); });
                row.appendChild(del);

                box.appendChild(row);

                if (field.type === 'object') {
                    if (!Array.isArray(field.fields)) field.fields = [];
                    const nested = document.createElement('div');
                    nested.className = 'pb-object-shape__nested';
                    nested.appendChild(this.renderList(field.fields));
                    box.appendChild(nested);
                }
                return box;
            },
        }"
        x-init="init()"
        class="pb-object-shape"
    >
        <div x-ref="root" class="pb-object-shape__root"></div>

        <div class="pb-object-shape__hint">
            Fields become dotted paths (<code>address</code>, <code>address.city</code>) that pages
            and flow nodes can bind to. A <strong>State</strong> field reuses another Object state's shape.
        </div>
    </div>

    <style>
        .pb-object-shape__root { display:flex; flex-direction:column; gap:.4rem; }
        .pb-object-shape__list { display:flex; flex-direction:column; gap:.4rem; }
        .pb-object-shape__nested {
            margin-left:1.1rem; padding-left:.7rem; border-left:2px solid rgba(99,102,241,.35);
            margin-top:.4rem; display:flex; flex-direction:column; gap:.4rem;
        }
        .pb-object-shape__row { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; }
        .pb-object-shape__row input[type=text],
        .pb-object-shape__row select {
            font-size:.8rem; line-height:1.2; padding:.3rem .45rem;
            border:1px solid rgba(148,163,184,.5); border-radius:.375rem;
            background:var(--pb-input-bg, #fff); color:inherit;
        }
        .dark .pb-object-shape__row input[type=text],
        .dark .pb-object-shape__row select { background:rgba(30,41,59,.6); border-color:rgba(71,85,105,.7); }
        .pb-object-shape__name { flex:1 1 9rem; min-width:7rem; }
        .pb-object-shape__type { flex:0 0 auto; }
        .pb-object-shape__ref { flex:0 0 auto; }
        .pb-object-shape__btn {
            font-size:.75rem; padding:.28rem .55rem; border-radius:.375rem; cursor:pointer;
            border:1px solid rgba(148,163,184,.5); background:transparent; color:inherit;
        }
        .pb-object-shape__btn--add { color:#6366F1; border-color:rgba(99,102,241,.5); }
        .pb-object-shape__btn--del { color:#ef4444; border-color:rgba(239,68,68,.4); line-height:1; }
        .pb-object-shape__hint { margin-top:.55rem; font-size:.72rem; opacity:.7; }
        .pb-object-shape__hint code { background:rgba(148,163,184,.18); padding:0 .2rem; border-radius:.2rem; }
        .pb-object-shape__empty { font-size:.75rem; opacity:.6; font-style:italic; }
    </style>
</x-dynamic-component>
