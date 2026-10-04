@php
    $stateClasses = [
        'done' => 'bg-success-500 text-white',
        'current' => 'bg-primary-500 text-white',
        'failed' => 'bg-danger-500 text-white',
        'upcoming' => 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    ];
    $stateIcons = ['done' => '✓', 'current' => '●', 'failed' => '✕', 'upcoming' => '○'];
@endphp
<div class="space-y-4" data-testid="installation-progress">
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::badge :color="$progress['color']" data-testid="installation-status">{{ $progress['status'] }}</x-filament::badge>
        <span class="text-sm text-gray-600 dark:text-gray-300" data-testid="installation-next-action">
            <span class="font-medium">{{ __('Next action') }}:</span> {{ $progress['next_action'] }}
        </span>
        @if($progress['blocker'])
            <span class="text-sm text-danger-600" data-testid="installation-blocker">
                <span class="font-medium">{{ __('Blocker') }}:</span> {{ $progress['blocker'] }}
            </span>
        @endif
    </div>
    <ol class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6">
        @foreach($progress['steps'] as $step)
            <li class="flex items-start gap-2" data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs {{ $stateClasses[$step['state']] }}">{{ $stateIcons[$step['state']] }}</span>
                <span class="min-w-0">
                    <span class="block text-sm font-medium">{{ $step['label'] }}</span>
                    @if($step['detail'])
                        <span class="block truncate text-xs text-gray-500">{{ $step['detail'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ol>
</div>
