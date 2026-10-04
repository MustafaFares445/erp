<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.profit_and_loss') }}</x-slot>

@foreach (['income' => 'admin.accounting.reports.sections.income', 'expense' => 'admin.accounting.reports.sections.expense'] as $key => $labelKey)
    <div class="mb-6">
        <h4 class="mb-2 font-semibold">{{ __($labelKey) }}</h4>

        <div class="overflow-x-auto">
            <table class="ierp-table">
                <thead>
                    <tr>
                        <th>{{ __('admin.accounting.reports.columns.account_code') }}</th>
                        <th>{{ __('admin.accounting.reports.columns.account_name') }}</th>
                        <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['sections'][$key]['rows'] as $row)
                        <tr>
                            <td style="padding-inline-start: {{ $row['depth'] }}rem">{{ $row['code'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td class="ierp-table-num">{{ $row['amount'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-center text-gray-500 dark:text-gray-400">{{ __('admin.accounting.reports.no_rows') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="ierp-table-total">
                        <td colspan="2">{{ __('admin.accounting.reports.subtotal_'.$key) }}</td>
                        <td class="ierp-table-num">{{ $report['sections'][$key]['subtotal'] }}</td>
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
