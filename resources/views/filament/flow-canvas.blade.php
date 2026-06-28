{{-- The aiPbFlow Alpine component is defined in the panel layout
     (flow-assets.blade via render hook). This view only mounts it with
     this field's statePath. wire:ignore keeps Livewire from morphing the
     Drawflow DOM during Livewire re-renders. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="aiPbFlow({ statePath: @js($getStatePath()) })"
        x-init="boot()"
        class="ai-pb-flow-wrap"
        style="border-radius:0.75rem;overflow:hidden;"
    >
        {{-- Node palette --}}
        <div class="ai-pb-palette">
            <button type="button" @click="addNode('trigger')">&#9654; Trigger</button>
            <button type="button" @click="addNode('ai_invoke')">&#10024; AI Invoke</button>
            <button type="button" @click="addNode('http_request')">&#127760; HTTP Request</button>
            <button type="button" @click="addNode('function')">&#402; Function</button>
            <button type="button" @click="addNode('condition')">&#10067; Condition</button>
            <button type="button" @click="addNode('result')">&#9632; Result</button>
        </div>

        {{-- Drawflow canvas --}}
        <div
            x-ref="canvas"
            style="height:600px;width:100%;"
        ></div>
    </div>
</x-dynamic-component>
