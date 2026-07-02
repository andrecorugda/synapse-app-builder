{{-- The aiPbFlow Alpine component is defined in the panel layout
     (flow-assets.blade via render hook). This view only mounts it with
     this field's statePath. wire:ignore keeps Livewire from morphing the
     Drawflow DOM during Livewire re-renders. --}}
@php
    // Serialise the registered node capabilities for the drawer. The drawer
    // groups + searches over these instead of a hardcoded palette, so any
    // node registered through NodeRegistry appears automatically.
    $nodeDefs = collect(app(\Andre\AiPageBuilder\Flow\NodeRegistry::class)->definitions())
        ->map(fn ($d) => $d->toArray())
        ->values()
        ->all();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="aiPbFlow({ statePath: @js($getStatePath()), nodeDefs: @js($nodeDefs), currentFlowSlug: @js($getRecord()?->slug) })"
        x-init="boot()"
        :class="{ 'ai-pb-flow-fullscreen': fullscreen }"
        @keydown.escape.window="fullscreen = false; drawerOpen = false"
        class="ai-pb-flow-wrap"
        style="border-radius:0.75rem;overflow:hidden;"
    >
        {{-- Toolbar: drawer toggle + State picker + fullscreen.
             The old flat palette of per-node buttons is replaced by the
             "+ Add node" drawer below, which scales as nodes grow. --}}
        <div class="ai-pb-palette">
            <button type="button" class="ai-pb-add-node-btn" @click="drawerOpen = ! drawerOpen"
                x-text="drawerOpen ? '✕ Close' : '+ Add node'"></button>

            <span class="ai-pb-palette-spacer"></span>

            {{-- Insert a {{ states.key }} reference (or a nested Object path,
                 {{ states.key.address.city }}) into the focused node field.
                 The option value is exactly the dotted ref after "states." — since
                 state keys can't contain dots, insertState needs no path handling. --}}
            <select class="ai-pb-state-picker"
                title="Insert a State reference into the focused field"
                @change="insertState($event.target.value); $event.target.value = ''">
                <option value="">⎘ Insert state…</option>
                <template x-for="opt in stateInsertOptions()" :key="opt.value">
                    <option :value="opt.value" x-text="opt.label"></option>
                </template>
            </select>

            {{-- Zoom controls --}}
            <div class="ai-pb-zoom">
                <button type="button" class="ai-pb-zoom-btn" @click="zoomOut()" title="Zoom out"><svg style="width:16px;height:16px;vertical-align:middle;position:static;fill:#cbd5e1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M192,112a80,80,0,1,1-80-80A80,80,0,0,1,192,112Z" opacity="0.2"/><path d="M229.66,218.34,179.6,168.28a88.21,88.21,0,1,0-11.32,11.31l50.06,50.07a8,8,0,0,0,11.32-11.32ZM40,112a72,72,0,1,1,72,72A72.08,72.08,0,0,1,40,112Zm112,0a8,8,0,0,1-8,8H80a8,8,0,0,1,0-16h64A8,8,0,0,1,152,112Z"/></svg></button>
                <button type="button" class="ai-pb-zoom-btn" @click="zoomReset()" title="Reset zoom (100%)"><svg style="width:16px;height:16px;vertical-align:middle;position:static;fill:#cbd5e1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,128a96,96,0,1,1-96-96A96,96,0,0,1,224,128Z" opacity="0.2"/><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm8,191.63V184a8,8,0,0,0-16,0v31.63A88.13,88.13,0,0,1,40.37,136H72a8,8,0,0,0,0-16H40.37A88.13,88.13,0,0,1,120,40.37V72a8,8,0,0,0,16,0V40.37A88.13,88.13,0,0,1,215.63,120H184a8,8,0,0,0,0,16h31.63A88.13,88.13,0,0,1,136,215.63Z"/></svg></button>
                <button type="button" class="ai-pb-zoom-btn" @click="zoomIn()" title="Zoom in"><svg style="width:16px;height:16px;vertical-align:middle;position:static;fill:#cbd5e1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M192,112a80,80,0,1,1-80-80A80,80,0,0,1,192,112Z" opacity="0.2"/><path d="M229.66,218.34,179.6,168.28a88.21,88.21,0,1,0-11.32,11.31l50.06,50.07a8,8,0,0,0,11.32-11.32ZM40,112a72,72,0,1,1,72,72A72.08,72.08,0,0,1,40,112Zm112,0a8,8,0,0,1-8,8H120v24a8,8,0,0,1-16,0V120H80a8,8,0,0,1,0-16h24V80a8,8,0,0,1,16,0v24h24A8,8,0,0,1,152,112Z"/></svg></button>
                <button type="button" class="ai-pb-zoom-btn" @click="zoomFit()" title="Fit to screen"><svg style="width:16px;height:16px;vertical-align:middle;position:static;fill:#cbd5e1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256"><path d="M224,56V200a8,8,0,0,1-8,8H40a8,8,0,0,1-8-8V56a8,8,0,0,1,8-8H216A8,8,0,0,1,224,56Z" opacity="0.2"/><path d="M200,80v32a8,8,0,0,1-16,0V88H160a8,8,0,0,1,0-16h32A8,8,0,0,1,200,80ZM96,168H72V144a8,8,0,0,0-16,0v32a8,8,0,0,0,8,8H96a8,8,0,0,0,0-16ZM232,56V200a16,16,0,0,1-16,16H40a16,16,0,0,1-16-16V56A16,16,0,0,1,40,40H216A16,16,0,0,1,232,56ZM216,200V56H40V200H216Z"/></svg></button>
            </div>

            <button type="button" class="ai-pb-fullscreen-btn" @click="toggleFullscreen()"
                x-text="fullscreen ? '✕ Exit fullscreen' : '⛶ Fullscreen'"></button>
        </div>

        {{-- Canvas area holds the drawf­low surface AND the slide-over drawer,
             so the drawer is positioned relative to the canvas (and overlays it
             in fullscreen too). --}}
        <div class="ai-pb-canvas-area" :style="fullscreen ? 'height: calc(100vh - 49px);' : 'height:600px;'">
            {{-- Drawflow canvas — grows to fill the viewport in fullscreen mode --}}
            <div x-ref="canvas" style="height:100%; width:100%;"></div>

            {{-- ── Node drawer (GrapesJS block-manager style) ── --}}
            {{-- Backdrop closes the drawer on outside click. --}}
            <div class="ai-pb-drawer-backdrop" x-show="drawerOpen" x-transition.opacity
                @click="drawerOpen = false" style="display:none;"></div>

            <aside class="ai-pb-drawer" :class="{ 'ai-pb-drawer--open': drawerOpen }">
                <div class="ai-pb-drawer-head">
                    <span class="ai-pb-drawer-title">Add a node</span>
                    <button type="button" class="ai-pb-drawer-close" @click="drawerOpen = false" title="Close">✕</button>
                </div>

                <div class="ai-pb-drawer-search">
                    <input type="text" x-model="nodeSearch" placeholder="Search nodes…"
                        @keydown.escape.stop="nodeSearch ? (nodeSearch = '') : (drawerOpen = false)" />
                </div>

                <div class="ai-pb-drawer-body">
                    <template x-for="group in grouped()" :key="group.category">
                        <div class="ai-pb-drawer-group">
                            <div class="ai-pb-drawer-group-title" x-text="group.label"></div>
                            <div class="ai-pb-drawer-grid">
                                <template x-for="def in group.nodes" :key="def.key">
                                    <button type="button" class="ai-pb-tile"
                                        :disabled="def.key === 'trigger' && hasTrigger()"
                                        :class="{ 'ai-pb-tile--disabled': def.key === 'trigger' && hasTrigger() }"
                                        :title="(def.key === 'trigger' && hasTrigger()) ? 'This flow already has a Trigger (one entry point per flow)' : (def.description || def.label)"
                                        @click="if (! (def.key === 'trigger' && hasTrigger())) { addNode(def.key); drawerOpen = false; }">
                                        <span class="ai-pb-tile-icon" x-html="(window.__pbNodeIcons && window.__pbNodeIcons[def.key]) || iconGlyph(def.icon)"></span>
                                        <span class="ai-pb-tile-label" x-text="def.label"></span>
                                        <span class="ai-pb-tile-desc" x-text="def.description" x-show="def.description"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div class="ai-pb-drawer-empty" x-show="! grouped().length">
                        <span x-text="'No nodes match “' + nodeSearch + '”.'"></span>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-dynamic-component>
