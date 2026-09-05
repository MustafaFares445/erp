<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ __('admin.purchasing.reports.open_commitments') }}</x-slot>
        <x-slot name="description">What is still owed to each supplier: ordered value less what has already been received. Drafts and finished orders are excluded.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.supplier') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.reports.orders') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.reports.ordered_value') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.reports.received_value') }}</th>
                        <th class="py-2">{{ __('admin.purchasing.reports.outstanding_value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($openCommitments as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-2 pr-4">{{ $row['supplier'] }}</td>
                            <td class="py-2 pr-4">{{ $row['orders'] }}</td>
                            <td class="py-2 pr-4">{{ number_format($row['ordered_value'], 2) }}</td>
                            <td class="py-2 pr-4">{{ number_format($row['received_value'], 2) }}</td>
                            <td class="py-2 font-semibold">{{ number_format($row['outstanding_value'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-gray-400">No open commitments.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.purchasing.reports.receiving_performance') }}</x-slot>
        <x-slot name="description">Measured against the date each supplier promised, not the date the buyer hoped for. Orders with no confirmed promise are not counted either way.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.supplier') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.promised_at') }}</th>
                        <th class="py-2">{{ __('admin.purchasing.reports.on_time_rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receivingPerformance as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-2 pr-4">{{ $row['supplier'] }}</td>
                            <td class="py-2 pr-4">{{ $row['on_time'] }} / {{ $row['promised'] }}</td>
                            <td class="py-2 font-semibold">{{ number_format($row['on_time_rate'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-3 text-gray-400">No confirmed promises have come due yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.purchasing.reports.cost_variance') }}</x-slot>
        <x-slot name="description">Lines where the price actually paid differed from the price ordered. A negative variance means the goods came in under the agreed price.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.purchase_order_number') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.supplier') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.product_variant') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.unit_cost') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.last_received_unit_cost') }}</th>
                        <th class="py-2">{{ __('admin.purchasing.fields.cost_variance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($costVariance as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-2 pr-4">{{ $row['purchase_order_number'] }}</td>
                            <td class="py-2 pr-4">{{ $row['supplier'] }}</td>
                            <td class="py-2 pr-4">{{ $row['variant'] }}</td>
                            <td class="py-2 pr-4">{{ number_format($row['ordered_cost'], 2) }}</td>
                            <td class="py-2 pr-4">{{ number_format($row['received_cost'], 2) }}</td>
                            <td @class([
                                'py-2 font-semibold',
                                'text-danger-600 dark:text-danger-400' => $row['variance'] > 0,
                                'text-success-600 dark:text-success-400' => $row['variance'] < 0,
                            ])>{{ number_format($row['variance'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-gray-400">Every received line came in at the ordered cost.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('admin.purchasing.reports.duplicate_reference_attempts') }}</x-slot>
        <x-slot name="description">Supplier invoice references that the payable duplicate-payment control refused. These attempts are audit evidence; no bill was created.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">{{ __('admin.purchasing.reports.attempted_at') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.fields.supplier') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.reports.supplier_reference') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.purchasing.reports.attempted_by') }}</th>
                        <th class="py-2">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($duplicateReferenceAttempts as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-2 pr-4">{{ $row['attempted_at'] }}</td>
                            <td class="py-2 pr-4">{{ $row['supplier'] }}</td>
                            <td class="py-2 pr-4 font-medium">{{ $row['supplier_reference'] }}</td>
                            <td class="py-2 pr-4">{{ $row['attempted_by'] }}</td>
                            <td class="py-2">{{ $row['message'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-gray-400">No duplicate supplier invoice attempts recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

</x-filament-panels::page>
