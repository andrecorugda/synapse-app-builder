@php
    /** @var \Illuminate\Database\Eloquent\Model $record */
    $steps = is_array($record->steps) ? $record->steps : [];
    $result = is_array($record->result) ? $record->result : [];
    $input = is_array($record->input) ? $record->input : [];
    $pretty = static fn ($v): string => (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp
<div class="space-y-4 text-sm">
    <div class="flex flex-wrap gap-x-6 gap-y-1">
        <div><span class="text-gray-500 dark:text-gray-400">Status</span>
            <span class="font-medium {{ $record->status === 'error' ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ $record->status }}</span></div>
        <div><span class="text-gray-500 dark:text-gray-400">Trigger</span> <span class="font-mono">{{ $record->trigger_type }}</span></div>
        <div><span class="text-gray-500 dark:text-gray-400">Duration</span> {{ $record->duration_ms }} ms</div>
        <div><span class="text-gray-500 dark:text-gray-400">When</span> {{ $record->created_at }}</div>
    </div>

    @if (! empty($record->error))
        <p class="rounded bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-300 px-3 py-2 break-words">{{ $record->error }}</p>
    @endif

    <div>
        <div class="font-medium mb-2">Steps</div>
        @if (empty($steps))
            <div class="text-gray-500 dark:text-gray-400">No steps recorded for this run.</div>
        @else
            <ol class="space-y-2">
                @foreach ($steps as $i => $step)
                    @php $st = $step['status'] ?? null; @endphp
                    <li class="flex items-start gap-3 rounded-lg border border-gray-200 dark:border-white/10 px-3 py-2">
                        <span class="mt-1 inline-flex h-2.5 w-2.5 shrink-0 rounded-full {{ $st === 'ok' ? 'bg-green-500' : ($st === 'error' ? 'bg-red-500' : 'bg-gray-400') }}"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs text-gray-400">#{{ $i + 1 }}</span>
                                <span class="font-medium break-all">{{ $step['node'] ?? '(node)' }}</span>
                                @if (! empty($step['type']))<span class="rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5 text-xs font-mono">{{ $step['type'] }}</span>@endif
                                @if ($st)<span class="text-xs">{{ $st }}</span>@endif
                                @if (isset($step['attempt']))<span class="text-xs text-gray-500 dark:text-gray-400">attempt {{ $step['attempt'] }}</span>@endif
                            </div>
                            @if (! empty($step['error']))<p class="mt-1 text-red-600 dark:text-red-400 break-words">{{ $step['error'] }}</p>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    @foreach (['Result actions' => ($result['actions'] ?? []), 'State (vars)' => ($result['vars'] ?? []), 'Input' => $input] as $label => $data)
        @if (! empty($data))
            <div>
                <div class="font-medium mb-1">{{ $label }}</div>
                <pre class="overflow-x-auto rounded bg-gray-50 dark:bg-white/5 p-3 text-xs">{{ $pretty($data) }}</pre>
            </div>
        @endif
    @endforeach
</div>
