<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Workload</x-slot>
        <x-slot name="description">Open tickets right now, by status, priority, and assignee.</x-slot>

        <p class="text-2xl font-semibold">{{ $workload['total_open'] }} open</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">By status</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @forelse ($workload['by_status'] as $status => $count)
                        <li class="flex items-center justify-between gap-2">
                            <span>{{ str($status)->headline() }}</span>
                            <x-filament::badge>{{ $count }}</x-filament::badge>
                        </li>
                    @empty
                        <li class="text-gray-400">None</li>
                    @endforelse
                </ul>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">By priority</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @forelse ($workload['by_priority'] as $priority => $count)
                        <li class="flex items-center justify-between gap-2">
                            <span>{{ str($priority)->headline() }}</span>
                            <x-filament::badge>{{ $count }}</x-filament::badge>
                        </li>
                    @empty
                        <li class="text-gray-400">None</li>
                    @endforelse
                </ul>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">By assignee</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @forelse ($workload['by_assignee'] as $assignee)
                        <li class="flex items-center justify-between gap-2">
                            <span>{{ $assignee['name'] }}</span>
                            <x-filament::badge>{{ $assignee['count'] }}</x-filament::badge>
                        </li>
                    @empty
                        <li class="text-gray-400">None assigned</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Period filter</x-slot>
        <x-slot name="description">Applies to the SLA and Maintenance sections below. Leave blank for all time.</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-filament::input.wrapper label="From">
                <x-filament::input type="date" wire:model.live="from" />
            </x-filament::input.wrapper>
            <x-filament::input.wrapper label="Until">
                <x-filament::input type="date" wire:model.live="until" />
            </x-filament::input.wrapper>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">SLA</x-slot>
        <x-slot name="description">Breach counts and average resolution time for tickets whose clock started in the chosen period.</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Response breaches</p>
                <p class="text-xl font-semibold">{{ $sla['response_breaches'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Resolution breaches</p>
                <p class="text-xl font-semibold">{{ $sla['resolution_breaches'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Average resolution time</p>
                <p class="text-xl font-semibold">
                    {{ $sla['average_resolution_minutes'] !== null ? $sla['average_resolution_minutes'].' min' : '—' }}
                </p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Maintenance</x-slot>
        <x-slot name="description">Open requests right now, overdue service records right now, and parts consumed in the chosen period.</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Open requests</p>
                <p class="text-xl font-semibold">{{ $maintenance['open_requests'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Overdue service records</p>
                <p class="text-xl font-semibold">{{ $maintenance['overdue_service_records'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Parts consumed</p>
                <p class="text-xl font-semibold">{{ $maintenance['parts_consumed'] }}</p>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
