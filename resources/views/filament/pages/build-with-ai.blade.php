<x-filament-panels::page>
    @unless ($this->aiAvailable())
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon
                    icon="heroicon-o-exclamation-triangle"
                    class="h-6 w-6 flex-shrink-0 text-warning-500"
                />
                <div>
                    <p class="font-medium text-gray-950 dark:text-white">
                        AI app generation is unavailable
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        AI app generation requires the AI OpenRouter Gateway. You can still build manually
                        from the other pages in this section.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @endunless

    {{-- Input form (brief + business guidelines). --}}
    <x-filament::section>
        <x-slot name="heading">Describe what to build</x-slot>
        <x-slot name="description">
            Write what you want in plain language. The AI returns a Build Plan you review before anything is created.
        </x-slot>

        {{ $this->form }}
    </x-filament::section>

    {{-- Plan review (only when a plan exists). --}}
    @if (! empty($this->plan))
        @php
            $summary = $this->planSummary();
            $sectionLabels = [
                'collections' => 'Collections',
                'states' => 'States',
                'functions' => 'Functions',
                'flows' => 'Flows',
                'pages' => 'Pages',
            ];
        @endphp

        {{-- Validation errors block — prominent; Apply is disabled while present. --}}
        @if (! empty($this->planErrors))
            <x-filament::section>
                <x-slot name="heading">
                    <span class="text-danger-600 dark:text-danger-400">
                        {{ count($this->planErrors) }} issue(s) — cannot apply
                    </span>
                </x-slot>
                <x-slot name="description">
                    Resolve these by refining your brief and regenerating. Apply is disabled until the plan is clean.
                </x-slot>

                <ul class="list-disc space-y-1 ps-5 text-sm text-danger-600 dark:text-danger-400">
                    @foreach ($this->planErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Build plan</x-slot>
            <x-slot name="description">
                Review what the AI proposes. Nothing is created until you press Apply.
            </x-slot>

            {{-- At-a-glance counts. --}}
            <div class="flex flex-wrap gap-2">
                @foreach ($sectionLabels as $key => $label)
                    @php $count = count($summary[$key] ?? []); @endphp
                    @if ($count > 0)
                        <x-filament::badge color="primary">
                            {{ $count }} {{ \Illuminate\Support\Str::of($label)->lower() }}
                        </x-filament::badge>
                    @endif
                @endforeach
            </div>

            <div class="mt-4 space-y-5">
                {{-- Collections (with their fields). --}}
                @if (! empty($summary['collections']))
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Collections</h3>
                        <div class="mt-2 space-y-3">
                            @foreach ($summary['collections'] as $collection)
                                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-950 dark:text-white">
                                            {{ $collection['name'] ?? ($collection['key'] ?? 'collection') }}
                                        </span>
                                        @if (! empty($collection['key']))
                                            <x-filament::badge color="gray">{{ $collection['key'] }}</x-filament::badge>
                                        @endif
                                    </div>
                                    @if (! empty($collection['fields']) && is_array($collection['fields']))
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @foreach ($collection['fields'] as $field)
                                                @if (is_array($field))
                                                    <span class="inline-flex items-center gap-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">
                                                        <span class="font-mono">{{ $field['key'] ?? '?' }}</span>
                                                        <span class="text-gray-400">·</span>
                                                        <span>{{ $field['type'] ?? 'string' }}</span>
                                                    </span>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- States. --}}
                @if (! empty($summary['states']))
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">States</h3>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($summary['states'] as $state)
                                <span class="inline-flex items-center gap-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">
                                    <span class="font-mono">{{ $state['key'] ?? '?' }}</span>
                                    @if (! empty($state['type']))
                                        <span class="text-gray-400">·</span><span>{{ $state['type'] }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Functions. --}}
                @if (! empty($summary['functions']))
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Functions</h3>
                        <div class="mt-2 space-y-1.5">
                            @foreach ($summary['functions'] as $fn)
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium text-gray-950 dark:text-white">
                                        {{ $fn['name'] ?? ($fn['slug'] ?? 'function') }}
                                    </span>
                                    @if (! empty($fn['slug']))
                                        <x-filament::badge color="gray">{{ $fn['slug'] }}</x-filament::badge>
                                    @endif
                                    @if (! empty($fn['runtime']))
                                        <span class="text-xs text-gray-500">({{ $fn['runtime'] }})</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Flows. --}}
                @if (! empty($summary['flows']))
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Flows</h3>
                        <div class="mt-2 space-y-1.5">
                            @foreach ($summary['flows'] as $flow)
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium text-gray-950 dark:text-white">
                                        {{ $flow['name'] ?? ($flow['slug'] ?? 'flow') }}
                                    </span>
                                    @if (! empty($flow['slug']))
                                        <x-filament::badge color="gray">{{ $flow['slug'] }}</x-filament::badge>
                                    @endif
                                    @if (! empty($flow['trigger_type']))
                                        <span class="text-xs text-gray-500">trigger: {{ $flow['trigger_type'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Pages. --}}
                @if (! empty($summary['pages']))
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Pages</h3>
                        <div class="mt-2 space-y-1.5">
                            @foreach ($summary['pages'] as $page)
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="font-medium text-gray-950 dark:text-white">
                                        {{ $page['title'] ?? ($page['slug'] ?? 'page') }}
                                    </span>
                                    @if (! empty($page['slug']))
                                        <x-filament::badge color="gray">/{{ $page['slug'] }}</x-filament::badge>
                                    @endif
                                    @if (! empty($page['status']))
                                        <span class="text-xs text-gray-500">{{ $page['status'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Raw plan JSON, collapsed, for transparency. --}}
            <div class="mt-5">
                <details class="group rounded-lg border border-gray-200 dark:border-white/10">
                    <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                        Raw plan JSON
                    </summary>
                    <pre class="overflow-x-auto border-t border-gray-200 px-3 py-2 text-xs text-gray-700 dark:border-white/10 dark:text-gray-300"><code>{{ $this->planJson() }}</code></pre>
                </details>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
