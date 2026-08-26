<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.profit_and_loss') }}</x-slot>

@foreach (['income' => 'admin.accounting.reports.sections.income', 'expense' => 'admin.accounting.reports.sections.expense'] as $key => $labelKey)
    <div class="mb-6">
        <h4 class="mb-2 font-semibold">{{ __($labelKey) }}</h4>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.account_code') }}</th>
                        <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.account_name') }}</th>
                        <th class="py-2 text-right">{{ __('admin.accounting.reports.columns.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['sections'][$key]['rows'] as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-2 pr-4" style="padding-inline-start: {{ $row['depth'] }}rem">{{ $row['code'] }}</td>
                            <td class="py-2 pr-4">{{ $row['name'] }}</td>
                            <td class="py-2 text-right">{{ $row['amount'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-3 text-gray-400">{{ __('admin.accounting.reports.no_rows') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-300 font-semibold dark:border-gray-600">
                        <td class="py-2 pr-4" colspan="2">{{ __('admin.accounting.reports.subtotal_'.$key) }}</td>
                        <td class="py-2 text-right">{{ $report['sections'][$key]['subtotal'] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endforeach

<div class="mt-4">
    <x-filament::badge :color="$report['isLoss'] ? 'danger' : 'success'">
        {{ $report['isLoss'] ? __('admin.accounting.reports.net_loss') : __('admin.accounting.reports.net_profit') }}:
        {{ $report['netResult'] }}
    </x-filament::badge>
</div>
</x-filament::section>
