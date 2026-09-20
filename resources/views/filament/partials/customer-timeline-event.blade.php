@php
    /** @var \App\Data\Crm\TimelineEvent $event */
@endphp

<div class="flex gap-3">
    <div class="flex flex-col items-center">
        <span @class([
            'flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
            'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => $event->isSystem,
            'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => ! $event->isSystem,
        ])>
            <x-filament::icon :icon="$event->icon" class="h-4 w-4" />
        </span>
        <span class="mt-1 w-px flex-1 bg-gray-200 dark:bg-white/10"></span>
    </div>

    <div @class(['flex-1 pb-6', 'opacity-75' => $event->isSystem])>
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <div class="flex flex-wrap items-center gap-2">
                @if ($event->link)
                    <a href="{{ $event->link }}" @class(['font-medium hover:underline', 'text-sm' => $event->isSystem])>{{ $event->title }}</a>
                @else
                    <span @class(['font-medium', 'text-sm' => $event->isSystem])>{{ $event->title }}</span>
                @endif

                @if ($event->statusLabel)
                    <x-filament::badge :color="$event->statusColor ?? 'gray'" size="sm">{{ $event->statusLabel }}</x-filament::badge>
                @endif
            </div>

            @if ($event->amountMinor !== null)
                <span class="font-medium tabular-nums">{{ \App\Support\MoneyFormatter::format($event->amountMinor, $event->currency) }}</span>
            @endif
        </div>

        @if ($event->detail)
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $event->detail }}</p>
        @endif

        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400 dark:text-gray-500">
            @if ($event->actorName)
                <span>{{ $event->actorName }}</span>
            @endif

            <span title="{{ $event->occurredAt->toDayDateTimeString() }}">
                {{ $event->occurredAtIsDateOnly ? $event->occurredAt->toFormattedDateString() : $event->occurredAt->diffForHumans() }}
            </span>

            @foreach ($event->relatedLinks as $relatedLink)
                <a href="{{ $relatedLink['url'] }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $relatedLink['label'] }}</a>
            @endforeach
        </div>
    </div>
</div>
