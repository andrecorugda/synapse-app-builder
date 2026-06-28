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
    <div
        wire:ignore
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
