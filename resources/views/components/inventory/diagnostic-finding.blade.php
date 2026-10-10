@props(['finding', 'compact' => false])

<li class="ierp-alert" data-tone="{{ $finding->severity->tone() }}" data-finding="{{ $finding->code }}">
    <x-filament::icon :icon="$finding->severity->icon()" class="ierp-alert-icon" />
    <div class="grid min-w-0 flex-1 gap-2">
        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <span class="font-semibold">{{ $finding->title }}</span>
            <x-inventory.term-help :text="$finding->explanation" />
            @unless ($compact)
                <span class="ierp-status" data-tone="{{ $finding->severity->tone() }}">{{ $finding->severity->label() }}</span>
            @endunless
        </div>
        @unless ($compact)
            <p class="text-sm">{{ $finding->explanation }}</p>
        @endunless
        @if ($finding->action !== null)
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('inventory_view_ux.next') }}</span>
                @if ($finding->action->url !== null)
                    <x-filament::link :href="$finding->action->url" size="sm" weight="semibold">{{ $finding->action->label }}</x-filament::link>
                @else
                    <span class="font-medium">{{ $finding->action->label }}</span>
                @endif
            </div>
            @if ($finding->action->deniedReason !== null)
                <span class="text-xs text-gray-600 dark:text-gray-300">{{ $finding->action->deniedReason }}</span>
            @endif
        @endif
        @if ($finding->facts !== [])
            <details class="text-xs text-gray-600 dark:text-gray-300" data-finding-evidence>
                <summary class="cursor-pointer">{{ __('inventory_view_explanations.evidence') }} ({{ count($finding->facts) }})</summary>
                <ul class="mt-1 list-disc space-y-1 ps-5">
                    @foreach ($finding->facts as $fact)
                        <li><bdi>{{ $fact }}</bdi></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
</li>
