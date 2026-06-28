@php
    $statePath = $getStatePath();
    $language = $getLanguage();
    $height = $getHeight();
    $lintUrl = ($language === 'php' && \Illuminate\Support\Facades\Route::has('ai-page-builder.lint.php'))
        ? route('ai-page-builder.lint.php')
        : null;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- The aiPbCode Alpine component + Monaco are loaded in the panel layout
         (monaco-assets.blade via render hook). This view only mounts an editor. --}}
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
        style="border:1px solid rgb(0 0 0 / 0.1);border-radius:0.5rem;overflow:hidden;background:#fff;"
    >
        <div x-ref="editor" style="width:100%;"></div>
    </div>
</x-dynamic-component>
