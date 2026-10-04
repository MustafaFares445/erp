<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ $reportLabel }}</x-slot>
            <x-slot name="description">{{ $reportDescription }}</x-slot>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,24rem)_1fr] lg:items-end">
                <div>
                    <label class="ierp-label" for="purchasing-report-type">{{ __('Report') }}</label>
                    <x-filament::input.wrapper class="mt-2">
                        <x-filament::input.select id="purchasing-report-type" wire:model.live="reportType">
                            @foreach ($this->reportOptions() as $value => $option)
                                <option value="{{ $value }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('The export action always uses the report currently shown on this page.') }}
                </div>
            </div>
        </x-filament::section>

        <div wire:loading.delay>
            <div class="ierp-card flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>{{ __('reporting.states.loading') }}</span>
            </div>
        </div>

        <div wire:loading.remove class="space-y-6">
            @if ($summary !== [])
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($summary as $metric)
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ $metric['label'] }}</div>
                            <div class="ierp-metric-value">{{ $metric['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            <x-filament::section>
                <div class="ierp-section-bleed overflow-x-auto">
                    <table class="ierp-table ierp-table-band">
                        @switch($reportKey)
                            @case('receiving_performance')
                                <thead><tr>
                                    <th>{{ __('admin.purchasing.fields.supplier') }}</th>
                                    <th>{{ __('Confirmed promises') }}</th>
                                    <th>{{ __('On time') }}</th>
                                    <th>{{ __('admin.purchasing.reports.on_time_rate') }}</th>
                                </tr></thead>
                                <tbody>
                                    @forelse ($rows as $row)
                                        <tr>
                                            <td class="font-medium">{{ $row['supplier'] }}</td>
                                            <td>{{ $row['promised'] }}</td>
                                            <td>{{ $row['on_time'] }}</td>
                                            <td class="font-semibold">{{ number_format($row['on_time_rate'], 1) }}%</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="py-10 text-center text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_data') }}</td></tr>
                                    @endforelse
                                </tbody>
                                @break

                            @case('cost_variance')
                                <thead><tr>
                                    <th>{{ __('admin.purchasing.fields.purchase_order_number') }}</th>
                                    <th>{{ __('admin.purchasing.fields.supplier') }}</th>
                                    <th>{{ __('admin.purchasing.fields.product_variant') }}</th>
                                    <th>{{ __('admin.purchasing.fields.unit_cost') }}</th>
                                    <th>{{ __('admin.purchasing.fields.last_received_unit_cost') }}</th>
                                    <th>{{ __('admin.purchasing.fields.cost_variance') }}</th>
                                </tr></thead>
                                <tbody>
                                    @forelse ($rows as $row)
                                        <tr>
                                            <td class="font-medium">{{ $row['purchase_order_number'] }}</td>
                                            <td>{{ $row['supplier'] }}</td>
                                            <td>{{ $row['variant'] }}</td>
                                            <td class="tabular-nums">{{ $row['currency_code'] }} {{ number_format($row['ordered_cost'], 2) }}</td>
                                            <td class="tabular-nums">{{ $row['currency_code'] }} {{ number_format($row['received_cost'], 2) }}</td>
                                            <td @class([
                                                'font-semibold tabular-nums',
                                                'text-danger-600 dark:text-danger-400' => $row['variance'] > 0,
                                                'text-success-600 dark:text-success-400' => $row['variance'] < 0,
                                            ])>{{ $row['currency_code'] }} {{ number_format($row['variance'], 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="py-10 text-center text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_data') }}</td></tr>
                                    @endforelse
                                </tbody>
                                @break

                            @case('duplicate_reference_attempts')
                                <thead><tr>
                                    <th>{{ __('admin.purchasing.reports.attempted_at') }}</th>
                                    <th>{{ __('admin.purchasing.fields.supplier') }}</th>
                                    <th>{{ __('admin.purchasing.reports.supplier_reference') }}</th>
                                    <th>{{ __('admin.purchasing.reports.attempted_by') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                </tr></thead>
                                <tbody>
                                    @forelse ($rows as $row)
                                        <tr>
                                            <td>{{ $row['attempted_at'] }}</td>
                                            <td>{{ $row['supplier'] }}</td>
                                            <td class="font-medium">{{ $row['supplier_reference'] }}</td>
                                            <td>{{ $row['attempted_by'] }}</td>
                                            <td>{{ $row['message'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="py-10 text-center text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_data') }}</td></tr>
                                    @endforelse
                                </tbody>
                                @break

                            @default
                                <thead><tr>
                                    <th>{{ __('admin.purchasing.fields.supplier') }}</th>
                                    <th>{{ __('admin.purchasing.fields.currency_code') }}</th>
                                    <th>{{ __('admin.purchasing.reports.orders') }}</th>
                                    <th>{{ __('admin.purchasing.reports.ordered_value') }}</th>
                                    <th>{{ __('admin.purchasing.reports.received_value') }}</th>
                                    <th>{{ __('admin.purchasing.reports.outstanding_value') }}</th>
                                </tr></thead>
                                <tbody>
                                    @forelse ($rows as $row)
                                        <tr>
                                            <td class="font-medium">{{ $row['supplier'] }}</td>
                                            <td>{{ $row['currency_code'] }}</td>
                                            <td>{{ $row['orders'] }}</td>
                                            <td class="tabular-nums">{{ $row['currency_code'] }} {{ number_format($row['ordered_value'], 2) }}</td>
                                            <td class="tabular-nums">{{ $row['currency_code'] }} {{ number_format($row['received_value'], 2) }}</td>
                                            <td class="font-semibold tabular-nums">{{ $row['currency_code'] }} {{ number_format($row['outstanding_value'], 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="py-10 text-center text-gray-500 dark:text-gray-400">{{ __('reporting.states.no_data') }}</td></tr>
                                    @endforelse
                                </tbody>
                        @endswitch
                    </table>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
