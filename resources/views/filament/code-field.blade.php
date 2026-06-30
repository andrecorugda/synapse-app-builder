@php
    $statePath = $getStatePath();
    $language = $getLanguage();
    $height = $getHeight();
    $lintUrl = ($language === 'php' && \Illuminate\Support\Facades\Route::has('ai-page-builder.lint.php'))
        ? route('ai-page-builder.lint.php')
        : null;

    // Function-helper catalogue — injected ONLY when this code field opted in
    // via ->helpers() (the Function body editor). Page/partial CSS/JS fields
    // get an empty list, so the dropdown never renders for them.
    $helperDefs = ($field->hasHelpers())
        ? collect(app(\Andre\AiPageBuilder\Capabilities\HelperRegistry::class)->definitions())
            ->map(fn ($d) => $d->toArray())
            ->values()
            ->all()
        : [];
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
            helperDefs: @js($helperDefs),
        })"
        x-init="boot()"
        style="border:1px solid rgb(255 255 255 / 0.1);border-radius:0.5rem;overflow:visible;background:#1e1e1e;position:relative;"
    >
        @if (in_array($language, ['php', 'javascript'], true))
            {{-- Insert a State reference (state('key') / $states['key']) at the
                 cursor; each option shows the State's data type. --}}
            <div style="display:flex;justify-content:flex-end;gap:.4rem;padding:.3rem .4rem;border-bottom:1px solid rgb(255 255 255 / 0.08);background:#181f33;border-radius:0.5rem 0.5rem 0 0;">
                {{-- Function-helper dropdown: pick a helper to insert its usage
                     snippet at the cursor. Rendered only when helperDefs is
                     non-empty (the Function body editor opted in). --}}
                <div x-show="(helperDefs || []).length" style="position:relative;" @keydown.escape.stop="helpersOpen = false">
                    <button type="button"
                        @click="helpersOpen = ! helpersOpen"
                        title="Insert a function helper at the cursor"
                        style="background:#0f172a;color:#c7d2fe;border:1px solid #6366f166;border-radius:.3rem;font-size:.72rem;padding:.15rem .55rem;cursor:pointer;"
                        x-text="helpersOpen ? '✕ Helpers' : 'ƒ Insert helper…'"></button>
                    <div x-show="helpersOpen" x-transition.opacity @click.outside="helpersOpen = false" style="display:none;position:absolute;right:0;top:calc(100% + .25rem);z-index:1000;width:340px;max-height:460px;overflow-y:auto;background:#0f172a;border:1px solid #334155;border-radius:.5rem;box-shadow:0 18px 48px rgba(0,0,0,.6);padding:.4rem;">
                        <input type="text" x-model="helperSearch" placeholder="Search helpers…"
                            style="width:100%;box-sizing:border-box;background:#1e293b;border:1px solid #334155;border-radius:.35rem;padding:.3rem .5rem;font-size:.74rem;color:#e2e8f0;outline:none;margin-bottom:.4rem;" />
                        <template x-for="group in helperGroups()" :key="group.category">
                            <div style="margin-bottom:.45rem;">
                                <div style="font-size:.62rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin:.15rem .25rem .25rem;" x-text="group.label"></div>
                                <template x-for="def in group.helpers" :key="def.key">
                                    <button type="button"
                                        @click="insertHelper(def); helpersOpen = false"
                                        :title="def.description + '\n\n' + def.usage"
                                        style="display:block;width:100%;text-align:left;background:transparent;border:0;border-radius:.35rem;padding:.3rem .4rem;cursor:pointer;color:#e2e8f0;"
                                        @mouseenter="$el.style.background='#312e81'" @mouseleave="$el.style.background='transparent'">
                                        <span style="font-size:.74rem;font-weight:600;color:#a5b4fc;" x-text="def.label"></span>
                                        <span style="display:block;font-size:.64rem;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="def.description"></span>
                                        <code style="display:block;font-size:.64rem;color:#5eead4;font-family:ui-monospace,monospace;margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="def.usage"></code>
                                    </button>
                                </template>
                            </div>
                        </template>
                        <div x-show="! helperGroups().length" style="padding:.75rem;text-align:center;font-size:.72rem;color:#64748b;">
                            <span x-text="'No helpers match “' + helperSearch + '”.'"></span>
                        </div>
                    </div>
                </div>

                <select
                    title="Insert a State reference at the cursor"
                    @change="insertState($event.target.value); $event.target.value = ''"
                    style="background:#0f172a;color:#5eead4;border:1px solid #2dd4bf66;border-radius:.3rem;font-size:.72rem;padding:.15rem .4rem;max-width:220px;"
                >
                    <option value="">⎘ Insert state…</option>
                    <template x-for="s in (window.__pbStates || [])" :key="s.key">
                        <option :value="s.key" x-text="s.key + ' · ' + (s.type || 'string')"></option>
                    </template>
                </select>
            </div>
        @endif
        @php $editorRadius = in_array($language, ['php', 'javascript'], true) ? '0 0 0.5rem 0.5rem' : '0.5rem'; @endphp
        <div x-ref="editor" style="width:100%;height:{{ (int) $height }}px;border-radius:{{ $editorRadius }};overflow:hidden;"></div>
    </div>
</x-dynamic-component>
