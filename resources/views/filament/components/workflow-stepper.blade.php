@php
    $steps = $getSteps();
@endphp

<div {{ $getExtraAttributeBag()->class(['fi-ierp-workflow-stepper']) }}>
    <ol class="grid gap-3 md:grid-flow-col md:auto-cols-fr" role="list">
        @foreach ($steps as $key => $label)
            @php($status = $statusFor((string) $key))
            <li @class([
                'rounded-xl border p-3 transition',
                'border-success-500 bg-success-50 dark:bg-success-950/20' => $status === 'completed',
                'border-primary-500 bg-primary-50 ring-1 ring-primary-500 dark:bg-primary-950/20' => $status === 'current',
                'border-danger-500 bg-danger-50 ring-1 ring-danger-500 dark:bg-danger-950/20' => $status === 'terminal',
                'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => $status === 'upcoming',
            ])>
                <div class="flex items-center gap-2">
                    <span @class([
                        'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-success-600 text-white' => $status === 'completed',
                        'bg-primary-600 text-white' => $status === 'current',
                        'bg-danger-600 text-white' => $status === 'terminal',
                        'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300' => $status === 'upcoming',
                    ])>
                        @if ($status === 'completed')
                            ✓
                        @elseif ($status === 'terminal')
                            ×
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>
                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $label }}</span>
                </div>
            </li>
        @endforeach
    </ol>
</div>
