<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ $sectionLabel }}</x-slot>
            <x-slot name="description">{{ $this->sectionDescription() }}</x-slot>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,24rem)_1fr] lg:items-end">
                <div>
                    <label class="ierp-label" for="support-report-section">{{ __('Report area') }}</label>
                    <x-filament::input.wrapper class="mt-2">
                        <x-filament::input.select id="support-report-section" wire:model.live="section">
                            @foreach ($this->sectionOptions() as $value => $option)
                                <option value="{{ $value }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                @if ($sectionKey === 'workload')
                    <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                        <x-filament::badge color="gray">{{ __('Current snapshot') }}</x-filament::badge>
                        <span>{{ __('This area reflects the unresolved workload right now and is not limited by a reporting period.') }}</span>
                    </div>
                @endif
            </div>

            @if ($this->usesPeriod())
                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:max-w-3xl">
                    <div>
                        <label class="ierp-label" for="support-report-from">{{ __('From') }}</label>
                        <x-filament::input.wrapper class="mt-2">
                            <x-filament::input id="support-report-from" type="date" wire:model.live="from" />
                        </x-filament::input.wrapper>
                    </div>
                    <div>
                        <label class="ierp-label" for="support-report-until">{{ __('Until') }}</label>
                        <x-filament::input.wrapper class="mt-2">
                            <x-filament::input id="support-report-until" type="date" wire:model.live="until" />
                        </x-filament::input.wrapper>
                    </div>
                </div>

                @if ($from || $until)
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($from)<x-filament::badge color="gray">{{ __('From') }}: {{ $from }}</x-filament::badge>@endif
                        @if ($until)<x-filament::badge color="gray">{{ __('Until') }}: {{ $until }}</x-filament::badge>@endif
                        <button type="button" wire:click="clearPeriod" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                            {{ __('reporting.actions.clear_filters') }}
                        </button>
                    </div>
                @endif
            @endif
        </x-filament::section>

        <div wire:loading.delay>
            <div class="ierp-card flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>{{ __('reporting.states.loading') }}</span>
            </div>
        </div>

        <div wire:loading.remove class="space-y-6">
            @switch($sectionKey)
                @case('service_desk')
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('Average first response') }}</div>
                            <div class="ierp-metric-value">{{ $responseTime['average_minutes'] !== null ? __(':minutes min', ['minutes' => $responseTime['average_minutes']]) : '—' }}</div>
                            <div class="ierp-metric-helper">{{ __(':count responded tickets', ['count' => $responseTime['count']]) }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('Average resolution') }}</div>
                            <div class="ierp-metric-value">{{ $resolutionTime['average_minutes'] !== null ? __(':minutes min', ['minutes' => $resolutionTime['average_minutes']]) : '—' }}</div>
                            <div class="ierp-metric-helper">{{ __(':count resolved tickets', ['count' => $resolutionTime['count']]) }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('SLA compliance') }}</div>
                            <div class="ierp-metric-value">{{ $slaCompliance['compliance_percent'] !== null ? $slaCompliance['compliance_percent'].'%' : '—' }}</div>
                            <div class="ierp-metric-helper">{{ __(':compliant/:completed completed milestones', ['compliant' => $slaCompliance['compliant'], 'completed' => $slaCompliance['completed']]) }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('CSAT') }}</div>
                            <div class="ierp-metric-value">{{ $customerSatisfaction['average_rating'] !== null ? __(':rating / 5', ['rating' => $customerSatisfaction['average_rating']]) : '—' }}</div>
                            <div class="ierp-metric-helper">{{ __(':count responses', ['count' => $customerSatisfaction['responses']]) }}</div>
                        </div>
                    </div>

                    <x-filament::section>
                        <x-slot name="heading">{{ __('SLA & lifecycle detail') }}</x-slot>
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="ierp-tile">
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Response breaches') }}</p>
                                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $sla['response_breaches'] }}</p>
                            </div>
                            <div class="ierp-tile">
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Resolution breaches') }}</p>
                                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $sla['resolution_breaches'] }}</p>
                            </div>
                            <div class="ierp-tile">
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Reopen rate') }}</p>
                                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $reopenRate['reopen_rate_percent'] !== null ? $reopenRate['reopen_rate_percent'].'%' : '—' }}</p>
                            </div>
                            <div class="ierp-tile">
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('SLA breached milestones') }}</p>
                                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $slaCompliance['breached'] }}</p>
                            </div>
                        </div>
                    </x-filament::section>
                    @break

                @case('workload')
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('Open tickets') }}</div>
                            <div class="ierp-metric-value">{{ $workload['total_open'] }}</div>
                            <div class="ierp-metric-helper">{{ __('Current snapshot') }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('Over 7 days') }}</div>
                            <div class="ierp-metric-value">{{ $backlogAging['over_seven_days'] }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('1–7 days') }}</div>
                            <div class="ierp-metric-value">{{ $backlogAging['one_to_three_days'] + $backlogAging['four_to_seven_days'] }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('Under 24 hours') }}</div>
                            <div class="ierp-metric-value">{{ $backlogAging['under_24h'] }}</div>
                        </div>
                    </div>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <x-filament::section>
                            <x-slot name="heading">{{ __('Workload breakdown') }}</x-slot>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('By status') }}</p>
                                    <ul class="mt-2 space-y-2 text-sm">
                                        @forelse ($workload['by_status'] as $status => $count)
                                            <li class="flex items-center justify-between gap-3">
                                                <span>{{ \App\Enums\TicketStatus::tryFrom((string) $status)?->label() ?? str($status)->headline() }}</span>
                                                <x-filament::badge>{{ $count }}</x-filament::badge>
                                            </li>
                                        @empty
                                            <li class="text-gray-400">{{ __('None') }}</li>
                                        @endforelse
                                    </ul>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('By priority') }}</p>
                                    <ul class="mt-2 space-y-2 text-sm">
                                        @forelse ($workload['by_priority'] as $priority => $count)
                                            <li class="flex items-center justify-between gap-3">
                                                <span>{{ \App\Enums\TicketPriority::tryFrom((string) $priority)?->label() ?? str($priority)->headline() }}</span>
                                                <x-filament::badge>{{ $count }}</x-filament::badge>
                                            </li>
                                        @empty
                                            <li class="text-gray-400">{{ __('None') }}</li>
                                        @endforelse
                                    </ul>
                                </div>
                            </div>
                        </x-filament::section>

                        <x-filament::section>
                            <x-slot name="heading">{{ __('Assignment load') }}</x-slot>
                            <div class="overflow-x-auto">
                                <table class="ierp-table">
                                    <thead><tr><th>{{ __('Assignee') }}</th><th>{{ __('Active tickets') }}</th></tr></thead>
                                    <tbody>
                                        @forelse ($assignmentLoad as $row)
                                            <tr><td>{{ $row['name'] }}</td><td class="tabular-nums">{{ $row['count'] }}</td></tr>
                                        @empty
                                            <tr><td colspan="2" class="py-8 text-center text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_data') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </x-filament::section>
                    </div>
                    @break

                @case('field_service')
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Open maintenance requests') }}</div><div class="ierp-metric-value">{{ $maintenance['open_requests'] }}</div></div>
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Overdue service records') }}</div><div class="ierp-metric-value">{{ $maintenance['overdue_service_records'] }}</div></div>
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Parts consumed') }}</div><div class="ierp-metric-value">{{ $maintenance['parts_consumed'] }}</div></div>
                    </div>
                    <x-filament::section>
                        <x-slot name="heading">{{ __('Technician workload') }}</x-slot>
                        <x-slot name="description">{{ __('Scheduled and actual on-site time. No utilization percentage is shown until an authoritative capacity calendar exists.') }}</x-slot>
                        <div class="ierp-section-bleed overflow-x-auto">
                            <table class="ierp-table ierp-table-band">
                                <thead><tr><th>{{ __('Technician') }}</th><th>{{ __('Appointments') }}</th><th>{{ __('Scheduled time') }}</th><th>{{ __('Actual on-site time') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($technicianUtilization as $row)
                                        <tr>
                                            <td>{{ $row['name'] }}</td>
                                            <td>{{ $row['appointment_count'] }}</td>
                                            <td>{{ __(':minutes min', ['minutes' => $row['scheduled_minutes']]) }}</td>
                                            <td>{{ __(':minutes min', ['minutes' => $row['actual_on_site_minutes']]) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="py-10 text-center text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_results') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>
                    @break

                @case('reliability_warranty')
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Recovery claims') }}</div><div class="ierp-metric-value">{{ $warrantyRecoveryPerformance['claims'] }}</div></div>
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Claimed') }}</div><div class="ierp-metric-value">{{ $currency }} {{ number_format($warrantyRecoveryPerformance['claimed_minor'] / 100, 2) }}</div></div>
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Received') }}</div><div class="ierp-metric-value">{{ $currency }} {{ number_format($warrantyRecoveryPerformance['received_minor'] / 100, 2) }}</div></div>
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Recovery rate') }}</div><div class="ierp-metric-value">{{ $warrantyRecoveryPerformance['recovery_percent'] !== null ? $warrantyRecoveryPerformance['recovery_percent'].'%' : '—' }}</div></div>
                    </div>
                    <x-filament::section>
                        <x-slot name="heading">{{ __('Repeat failures') }}</x-slot>
                        <div class="ierp-section-bleed overflow-x-auto">
                            <table class="ierp-table ierp-table-band">
                                <thead><tr><th>{{ __('Equipment unit') }}</th><th>{{ __('Failure category') }}</th><th>{{ __('Occurrences') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($repeatFailures as $row)
                                        <tr>
                                            <td>
                                                @if ($url = $this->equipmentUrl((int) $row['serialized_inventory_unit_id']))
                                                    <a href="{{ $url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">#{{ $row['serialized_inventory_unit_id'] }}</a>
                                                @else
                                                    #{{ $row['serialized_inventory_unit_id'] }}
                                                @endif
                                            </td>
                                            <td>{{ \App\Enums\WarrantyFailureCategory::tryFrom((string) $row['failure_category'])?->label() ?? str($row['failure_category'])->headline() }}</td>
                                            <td>{{ $row['count'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="py-10 text-center text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_results') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>
                    @break

                @case('financial')
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Total cost') }}</div><div class="ierp-metric-value">{{ $currency }} {{ number_format($serviceMargin['total_cost_minor'] / 100, 2) }}</div></div>
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Total revenue') }}</div><div class="ierp-metric-value">{{ $currency }} {{ number_format($serviceMargin['total_revenue_minor'] / 100, 2) }}</div></div>
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Total margin') }}</div><div class="ierp-metric-value">{{ $currency }} {{ number_format($serviceMargin['total_margin_minor'] / 100, 2) }}</div></div>
                        <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ __('Warranty cost') }}</div><div class="ierp-metric-value">{{ $currency }} {{ number_format($serviceMargin['warranty_cost_minor'] / 100, 2) }}</div></div>
                    </div>
                    <x-filament::section>
                        <x-slot name="heading">{{ __('Billed service jobs') }}</x-slot>
                        <div class="ierp-section-bleed overflow-x-auto">
                            <table class="ierp-table ierp-table-band">
                                <thead><tr><th>{{ __('Job') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Equipment') }}</th><th>{{ __('Billing') }}</th><th>{{ __('Cost') }}</th><th>{{ __('Revenue') }}</th><th>{{ __('Margin') }}</th></tr></thead>
                                <tbody>
                                    @forelse ($serviceMargin['jobs'] as $job)
                                        <tr>
                                            <td>
                                                @if ($url = $this->maintenanceUrl((int) $job['maintenance_record_id']))
                                                    <a href="{{ $url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">#{{ $job['maintenance_record_id'] }}</a>
                                                @else
                                                    #{{ $job['maintenance_record_id'] }}
                                                @endif
                                            </td>
                                            <td>{{ $job['customer'] ?? '—' }}</td>
                                            <td>{{ $job['equipment'] ?? '—' }}</td>
                                            <td>{{ \App\Enums\MaintenanceBillingType::tryFrom((string) $job['billing_type'])?->label() ?? str($job['billing_type'])->headline() }}</td>
                                            <td>{{ $currency }} {{ number_format($job['cost_minor'] / 100, 2) }}</td>
                                            <td>{{ $currency }} {{ number_format($job['revenue_minor'] / 100, 2) }}</td>
                                            <td>{{ $currency }} {{ number_format($job['margin_minor'] / 100, 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="py-10 text-center text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_results') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-filament::section>
                    @break

                @case('preventive')
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        @foreach ([
                            __('Total due') => $preventiveCompliance['total_due'],
                            __('Raised') => $preventiveCompliance['raised'],
                            __('Completed') => $preventiveCompliance['completed'],
                            __('Missed') => $preventiveCompliance['missed'],
                            __('Skipped') => $preventiveCompliance['skipped'],
                        ] as $label => $value)
                            <div class="ierp-card ierp-metric"><div class="ierp-metric-label">{{ $label }}</div><div class="ierp-metric-value">{{ $value }}</div></div>
                        @endforeach
                    </div>
                    <div class="grid gap-6 xl:grid-cols-2">
                        @foreach (['by_customer' => __('By customer'), 'by_equipment' => __('By equipment')] as $key => $heading)
                            <x-filament::section>
                                <x-slot name="heading">{{ $heading }}</x-slot>
                                <div class="space-y-2">
                                    @forelse ($preventiveCompliance[$key] as $row)
                                        <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-white/10">
                                            <span>{{ $row[$key === 'by_customer' ? 'customer' : 'equipment'] ?? '—' }}</span>
                                            <span class="tabular-nums">{{ $row['completed'] }}/{{ $row['due'] }} · {{ __(':count missed', ['count' => $row['missed']]) }}</span>
                                        </div>
                                    @empty
                                        <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_results') }}</div>
                                    @endforelse
                                </div>
                            </x-filament::section>
                        @endforeach
                    </div>
                    @break

                @default
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('Open tickets') }}</div>
                            <div class="ierp-metric-value">{{ $workload['total_open'] }}</div>
                            <div class="ierp-metric-helper">{{ __('Current snapshot') }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('SLA compliance') }}</div>
                            <div class="ierp-metric-value">{{ $slaCompliance['compliance_percent'] !== null ? $slaCompliance['compliance_percent'].'%' : '—' }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('Average first response') }}</div>
                            <div class="ierp-metric-value">{{ $responseTime['average_minutes'] !== null ? __(':minutes min', ['minutes' => $responseTime['average_minutes']]) : '—' }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('Average resolution') }}</div>
                            <div class="ierp-metric-value">{{ $resolutionTime['average_minutes'] !== null ? __(':minutes min', ['minutes' => $resolutionTime['average_minutes']]) : '—' }}</div>
                        </div>
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ __('CSAT') }}</div>
                            <div class="ierp-metric-value">{{ $customerSatisfaction['average_rating'] !== null ? __(':rating / 5', ['rating' => $customerSatisfaction['average_rating']]) : '—' }}</div>
                        </div>
                    </div>

                    <x-filament::section>
                        <x-slot name="heading">{{ __('How to use these reports') }}</x-slot>
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($this->sectionOptions() as $key => $option)
                                @continue($key === 'overview')
                                <button
                                    type="button"
                                    wire:click="$set('section', '{{ $key }}')"
                                    class="ierp-card text-start transition hover:border-primary-400 hover:bg-primary-50/50 dark:hover:border-primary-500 dark:hover:bg-primary-500/5"
                                >
                                    <span class="block text-sm font-semibold text-gray-950 dark:text-white">{{ $option['label'] }}</span>
                                    <span class="mt-1 block text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $option['description'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </x-filament::section>
            @endswitch
        </div>
    </div>
</x-filament-panels::page>
