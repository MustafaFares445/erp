<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.trial_balance') }}</x-slot>

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400">
                <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.account_code') }}</th>
                <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.account_name') }}</th>
                <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.account_type') }}</th>
                <th class="py-2 pr-4 text-right">{{ __('admin.accounting.reports.columns.opening_balance') }}</th>
                <th class="py-2 pr-4 text-right">{{ __('admin.accounting.reports.columns.period_debit') }}</th>
                <th class="py-2 pr-4 text-right">{{ __('admin.accounting.reports.columns.period_credit') }}</th>
                <th class="py-2 text-right">{{ __('admin.accounting.reports.columns.closing_balance') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="py-2 pr-4" style="padding-inline-start: {{ $row['depth'] }}rem">{{ $row['code'] }}</td>
                    <td class="py-2 pr-4">
                        {{ $row['name'] }}
                        @if ($row['isDeleted'])
                            <span class="text-gray-400">{{ __('admin.accounting.reports.deleted_suffix') }}</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4">{{ $row['element'] }}</td>
                    <td class="py-2 pr-4 text-right">{{ $row['openingBalance'] }}</td>
                    <td class="py-2 pr-4 text-right">{{ $row['periodDebit'] }}</td>
                    <td class="py-2 pr-4 text-right">{{ $row['periodCredit'] }}</td>
                    <td class="py-2 text-right font-semibold">{{ $row['closingBalance'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-3 text-gray-400">{{ __('admin.accounting.reports.no_rows') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="border-t border-gray-300 font-semibold dark:border-gray-600">
                <td class="py-2 pr-4" colspan="4">{{ __('admin.accounting.reports.total') }}</td>
                <td class="py-2 pr-4 text-right">{{ $report['totalDebit'] }}</td>
                <td class="py-2 pr-4 text-right">{{ $report['totalCredit'] }}</td>
                <td class="py-2"></td>
            </tr>
        </tfoot>
    </table>
</div>

<div class="mt-4">
    @if ($report['foots'])
        <x-filament::badge color="success">{{ __('admin.accounting.reports.proof.balanced') }}</x-filament::badge>
    @else
        <x-filament::badge color="danger">{{ __('admin.accounting.reports.proof.out_of_balance', ['variance' => $report['variance']]) }}</x-filament::badge>
    @endif
</div>
</x-filament::section>
