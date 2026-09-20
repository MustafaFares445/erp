@php
    use App\Support\MoneyFormatter;

    $aging = $summary['outstanding']['buckets'] ?? [];
    $agingTotal = max(1, array_sum($aging));
    $groupedEvents = collect($timeline->items())->groupBy(function (\App\Data\Crm\TimelineEvent $event): string {
        return match (true) {
            $event->occurredAt->isToday() => 'Today',
            $event->occurredAt->isYesterday() => 'Yesterday',
            default => $event->occurredAt->format('D j M Y'),
        };
    });
@endphp

<x-filament-panels::page>
    <div class="app-customer-timeline space-y-6">
        <x-filament::section>
            <x-slot name="heading">Summary</x-slot>
            <x-slot name="description">Lifetime value, outstanding position, and activity at a glance (CR-05).</x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Lifetime invoiced</p>
                    <p class="text-xl font-semibold">{{ MoneyFormatter::format($summary['lifetime_invoiced_minor']) }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Lifetime collected</p>
                    <p class="text-xl font-semibold">{{ MoneyFormatter::format($summary['lifetime_collected_minor']) }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Outstanding</p>
                    <p class="text-xl font-semibold">{{ MoneyFormatter::format($summary['outstanding']['outstanding_minor']) }}</p>
                    @if ($summary['overdue_documents_count'] > 0)
                        <p class="text-xs text-gray-400">{{ $summary['overdue_documents_count'] }} overdue</p>
                    @endif
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">In flight</p>
                    <p class="text-xl font-semibold">{{ MoneyFormatter::format($summary['open_quotations_value_minor'] + $summary['open_orders_value_minor']) }}</p>
                    <p class="text-xs text-gray-400">Open quotations &amp; orders</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Open tickets</p>
                    <p class="text-xl font-semibold">{{ $summary['open_tickets'] }}</p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Last interaction</p>
                    <p class="text-xl font-semibold" title="{{ $summary['last_interaction_at']?->toDayDateTimeString() }}">
                        {{ $summary['last_interaction_at']?->diffForHumans() ?? '—' }}
                    </p>
                </div>
            </div>

            @if (array_sum($aging) > 0)
                <div class="mt-4">
                    <div class="flex h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="bg-success-400" style="width: {{ ($aging['current'] ?? 0) / $agingTotal * 100 }}%"></div>
                        <div class="bg-warning-300" style="width: {{ ($aging['1_30'] ?? 0) / $agingTotal * 100 }}%"></div>
                        <div class="bg-warning-500" style="width: {{ ($aging['31_60'] ?? 0) / $agingTotal * 100 }}%"></div>
                        <div class="bg-danger-400" style="width: {{ ($aging['61_90'] ?? 0) / $agingTotal * 100 }}%"></div>
                        <div class="bg-danger-600" style="width: {{ ($aging['over_90'] ?? 0) / $agingTotal * 100 }}%"></div>
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <span>Current: {{ MoneyFormatter::format($aging['current'] ?? 0) }}</span>
                        <span>1-30: {{ MoneyFormatter::format($aging['1_30'] ?? 0) }}</span>
                        <span>31-60: {{ MoneyFormatter::format($aging['31_60'] ?? 0) }}</span>
                        <span>61-90: {{ MoneyFormatter::format($aging['61_90'] ?? 0) }}</span>
                        <span>90+: {{ MoneyFormatter::format($aging['over_90'] ?? 0) }}</span>
                    </div>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Filters</x-slot>

            <div class="flex flex-wrap items-end gap-4">
                <x-filament::input.wrapper label="Range">
                    <x-filament::input.select wire:model.live="range">
                        <option value="all">All time</option>
                        <option value="30d">Last 30 days</option>
                        <option value="90d">Last 90 days</option>
                        <option value="ytd">Year to date</option>
                        <option value="custom">Custom</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                @if ($range === 'custom')
                    <x-filament::input.wrapper label="From">
                        <x-filament::input type="date" wire:model.live="from" />
                    </x-filament::input.wrapper>
                    <x-filament::input.wrapper label="Until">
                        <x-filament::input type="date" wire:model.live="until" />
                    </x-filament::input.wrapper>
                @endif

                <x-filament::input.wrapper label="Search" class="min-w-[12rem] flex-1">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Reference number…" />
                </x-filament::input.wrapper>

                <label class="flex items-center gap-2 pb-2 text-sm">
                    <x-filament::input.checkbox wire:model.live="showActivity" />
                    Status changes
                </label>

                <x-filament::button color="gray" size="sm" wire:click="clearFilters">
                    Clear filters
                </x-filament::button>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($availableTypes as $type)
                    <label @class([
                        'flex cursor-pointer items-center gap-1 rounded-full border px-3 py-1 text-sm',
                        'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $types === [] || in_array($type, $types, true),
                        'border-gray-200 text-gray-500 dark:border-white/10 dark:text-gray-400' => $types !== [] && ! in_array($type, $types, true),
                    ])>
                        <input type="checkbox" class="sr-only" wire:model.live="types" value="{{ $type }}" />
                        {{ str($type)->replace('_', ' ')->headline() }}
                    </label>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Stream</x-slot>
            <x-slot name="description">Reverse-chronological across every source this account manager may see.</x-slot>

            @forelse ($groupedEvents as $dateLabel => $events)
                <div class="mb-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $dateLabel }}</p>

                    @foreach ($events as $event)
                        @include('filament.partials.customer-timeline-event', ['event' => $event])
                    @endforeach
                </div>
            @empty
                <p class="py-3 text-gray-400">Nothing to show in this range.</p>
            @endforelse

            <div class="mt-4">
                {{ $timeline->links() }}
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
