{{-- The aiPbFlow Alpine component is defined in the panel layout
     (flow-assets.blade via render hook). This view only mounts it with
     this field's statePath. wire:ignore keeps Livewire from morphing the
     Drawflow DOM during Livewire re-renders. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="aiPbFlow({ statePath: @js($getStatePath()) })"
        x-init="boot()"
        :class="{ 'ai-pb-flow-fullscreen': fullscreen }"
        @keydown.escape.window="fullscreen = false"
        class="ai-pb-flow-wrap"
        style="border-radius:0.75rem;overflow:hidden;"
    >
        {{-- Node palette --}}
        <div class="ai-pb-palette">
            <button type="button" @click="addNode('trigger')">&#9654; Trigger</button>
            <button type="button" @click="addNode('ai_invoke')">&#10024; AI Invoke</button>
            <button type="button" @click="addNode('http_request')">&#127760; HTTP Request</button>
            <button type="button" @click="addNode('function')">&#402; Function</button>
            <button type="button" @click="addNode('record')">&#128451; Collection</button>
            <button type="button" @click="addNode('set_variable')">&#128190; Set State</button>
            <button type="button" @click="addNode('condition')">&#10067; Condition</button>
            <button type="button" @click="addNode('result')">&#9632; Result</button>

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

        {{-- Drawflow canvas — grows to fill the viewport in fullscreen mode --}}
        <div
            x-ref="canvas"
            :style="fullscreen ? 'height: calc(100vh - 49px); width:100%;' : 'height:600px; width:100%;'"
        ></div>
    </div>
</x-dynamic-component>
