@php
    $statePath = $getStatePath();
    $blocks = $getBlocks();
    $height = $getHeight();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- The aiPageBuilderEditor Alpine component is defined in the panel layout
         (grapesjs-assets.blade via render hook); here we only mount it with this
         field's config. wire:ignore keeps Livewire from morphing the editor DOM. --}}
    <div
        wire:ignore
        x-data="aiPageBuilderEditor({
            statePath: @js($statePath),
            blocks: @js($blocks),
            height: {{ (int) $height }},
        })"
        x-init="boot()"
        style="border:1px solid rgb(0 0 0 / 0.1);border-radius:0.75rem;overflow:hidden;background:#fff;"
    >
        <div style="display:flex;align-items:stretch;min-height:{{ (int) $height }}px;">
            {{-- Block palette --}}
            <div x-ref="blocks" style="width:13rem;flex:0 0 13rem;border-right:1px solid rgb(0 0 0 / 0.08);overflow:auto;background:#f8fafc;"></div>
            {{-- Editor canvas --}}
            <div style="flex:1 1 auto;min-width:0;">
                <div x-ref="canvas"></div>
            </div>
        </div>
    </div>
</x-dynamic-component>
