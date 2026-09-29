<div class="w-full overflow-x-auto py-1">
    <div class="flex min-w-[760px] items-start gap-0">
        @foreach ($steps as $index => $step)
            <div class="flex min-w-0 flex-1 items-start">
                <div class="flex min-w-[88px] flex-col items-center text-center">
                    <div @class([
                        'flex h-8 w-8 items-center justify-center rounded-full border text-xs font-semibold',
                        'border-success-500 bg-success-500 text-white' => $step['state'] === 'done',
                        'border-primary-500 bg-primary-500 text-white ring-4 ring-primary-500/15' => $step['state'] === 'current',
                        'border-gray-300 bg-white text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400' => $step['state'] === 'pending',
                    ])>
                        @if ($step['state'] === 'done')
                            ✓
                        @else
                            {{ $index + 1 }}
                        @endif
                    </div>
                    <div @class([
                        'mt-2 text-xs font-medium',
                        'text-success-600 dark:text-success-400' => $step['state'] === 'done',
                        'text-primary-600 dark:text-primary-400' => $step['state'] === 'current',
                        'text-gray-500 dark:text-gray-400' => $step['state'] === 'pending',
                    ])>
                        {{ $step['label'] }}
                    </div>
                </div>
                @if (! $loop->last)
                    <div @class([
                        'mt-4 h-0.5 flex-1',
                        'bg-success-500' => $step['state'] === 'done',
                        'bg-gray-200 dark:bg-gray-700' => $step['state'] !== 'done',
                    ])></div>
                @endif
            </div>
        @endforeach
    </div>
</div>
