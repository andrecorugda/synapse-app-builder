@php
    /** @var array<int,array<string,mixed>> $steps */
    $steps = (array) ($getState() ?? []);
@endphp

@if (empty($steps))
    <div class="text-sm text-gray-500 dark:text-gray-400">No steps were recorded for this run.</div>
@else
    <ol class="space-y-2">
        @foreach ($steps as $i => $step)
            @php
                $status = $step['status'] ?? null;
                $dot = match ($status) {
                    'ok' => 'bg-green-500',
                    'error' => 'bg-red-500',
                    'skipped' => 'bg-gray-400',
                    default => 'bg-gray-300 dark:bg-gray-600',
                };
            @endphp
            <li class="flex items-start gap-3 rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2">
                <span class="mt-1 inline-flex h-2.5 w-2.5 shrink-0 rounded-full {{ $dot }}"></span>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-mono text-gray-400 dark:text-gray-500">#{{ $i + 1 }}</span>

                        <span class="font-medium text-gray-900 dark:text-gray-100 break-all">
                            {{ $step['node'] ?? '(unknown node)' }}
                        </span>

                        @if (! empty($step['type']))
                            <span class="rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5 text-xs font-mono text-gray-600 dark:text-gray-300">
                                {{ $step['type'] }}
                            </span>
                        @endif

                        @if ($status !== null)
                            <span @class([
                                'rounded px-1.5 py-0.5 text-xs font-medium',
                                'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300' => $status === 'ok',
                                'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300' => $status === 'error',
                                'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => ! in_array($status, ['ok', 'error'], true),
                            ])>
                                {{ $status }}
                            </span>
                        @endif

                        @if (isset($step['attempt']))
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                attempt {{ $step['attempt'] }}
                            </span>
                        @endif
                    </div>

                    @if (! empty($step['error']))
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400 break-words">
                            {{ $step['error'] }}
                        </p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
