@php
    $statePath = $getStatePath();
    $language = $getLanguage();
    $height = $getHeight();
    $lintUrl = ($language === 'php' && \Illuminate\Support\Facades\Route::has('ai-page-builder.lint.php'))
        ? route('ai-page-builder.lint.php')
        : null;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- The aiPbCode Alpine component + Ace are loaded in the panel layout
         (codeeditor-assets.blade via render hook). This view only mounts an editor. --}}
    {{-- Distinct wire:key per (statePath, language). The expression / callable /
         php variants of a Function body all share the SAME statePath, so without
         this Livewire's morph reuses one editor's DOM node (kept alive by
         wire:ignore) for another runtime — the new editor inherits the stale
         language/theme and never re-styles. The key forces a clean replace. --}}
    <div
        wire:ignore
        wire:key="apb-code-{{ $statePath }}-{{ $language }}"
        class="ai-pb-code"
        x-data="aiPbCode({
            statePath: @js($statePath),
            language: @js($language),
            lintUrl: @js($lintUrl),
            height: {{ (int) $height }},
            csrf: @js(csrf_token()),
        })"
        x-init="boot()"
        style="border:1px solid rgb(255 255 255 / 0.1);border-radius:0.5rem;overflow:hidden;background:#1e1e1e;"
    >
        <div x-ref="editor" style="width:100%;height:{{ (int) $height }}px;"></div>
    </div>
</x-dynamic-component>
