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

    <x-filament::section>
        <x-slot name="heading">Service margin</x-slot>
        <x-slot name="description">Cost, revenue, and margin per billed job in the chosen period (WP-2.9). Warranty-covered work shows its real cost against zero revenue.</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total cost</p>
                <p class="text-xl font-semibold">{{ number_format($serviceMargin['total_cost_minor'] / 100, 2) }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total revenue</p>
                <p class="text-xl font-semibold">{{ number_format($serviceMargin['total_revenue_minor'] / 100, 2) }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total margin</p>
                <p class="text-xl font-semibold">{{ number_format($serviceMargin['total_margin_minor'] / 100, 2) }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Warranty cost</p>
                <p class="text-xl font-semibold">{{ number_format($serviceMargin['warranty_cost_minor'] / 100, 2) }}</p>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="pr-4">Job</th>
                        <th class="pr-4">Customer</th>
                        <th class="pr-4">Equipment</th>
                        <th class="pr-4">Billing</th>
                        <th class="pr-4">Cost</th>
                        <th class="pr-4">Revenue</th>
                        <th>Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($serviceMargin['jobs'] as $job)
                        <tr>
                            <td class="pr-4">#{{ $job['maintenance_record_id'] }}</td>
                            <td class="pr-4">{{ $job['customer'] ?? '—' }}</td>
                            <td class="pr-4">{{ $job['equipment'] ?? '—' }}</td>
                            <td class="pr-4">{{ str($job['billing_type'])->headline() }}</td>
                            <td class="pr-4">{{ number_format($job['cost_minor'] / 100, 2) }}</td>
                            <td class="pr-4">{{ number_format($job['revenue_minor'] / 100, 2) }}</td>
                            <td>{{ number_format($job['margin_minor'] / 100, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-gray-400">No billed jobs in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Preventive maintenance compliance</x-slot>
        <x-slot name="description">Due/raised/completed/missed preventive-service occurrences in the chosen period (WP-3.6, MT-07), by customer and equipment.</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-5">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total due</p>
                <p class="text-xl font-semibold">{{ $preventiveCompliance['total_due'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Raised</p>
                <p class="text-xl font-semibold">{{ $preventiveCompliance['raised'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Completed</p>
                <p class="text-xl font-semibold">{{ $preventiveCompliance['completed'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Missed</p>
                <p class="text-xl font-semibold">{{ $preventiveCompliance['missed'] }}</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Skipped</p>
                <p class="text-xl font-semibold">{{ $preventiveCompliance['skipped'] }}</p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">By customer</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @forelse ($preventiveCompliance['by_customer'] as $row)
                        <li class="flex items-center justify-between gap-2">
                            <span>{{ $row['customer'] ?? '—' }}</span>
                            <span>{{ $row['completed'] }}/{{ $row['due'] }} ({{ $row['missed'] }} missed)</span>
                        </li>
                    @empty
                        <li class="text-gray-400">None</li>
                    @endforelse
                </ul>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">By equipment</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @forelse ($preventiveCompliance['by_equipment'] as $row)
                        <li class="flex items-center justify-between gap-2">
                            <span>{{ $row['equipment'] ?? '—' }}</span>
                            <span>{{ $row['completed'] }}/{{ $row['due'] }} ({{ $row['missed'] }} missed)</span>
                        </li>
                    @empty
                        <li class="text-gray-400">None</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
