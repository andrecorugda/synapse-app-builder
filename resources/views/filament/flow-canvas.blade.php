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

            {{-- Insert a {{ states.key }} reference into the focused node field;
                 each option shows the State's data type. --}}
            <select class="ai-pb-state-picker"
                title="Insert a State reference into the focused field"
                @change="insertState($event.target.value); $event.target.value = ''">
                <option value="">⎘ Insert state…</option>
                <template x-for="s in (window.__pbVariables || [])" :key="s.key">
                    <option :value="s.key" x-text="s.key + ' · ' + (s.type || 'string')"></option>
                </template>
            </select>

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
                                        <span class="ai-pb-tile-icon" x-text="iconGlyph(def.icon)"></span>
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
