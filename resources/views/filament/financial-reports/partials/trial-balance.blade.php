<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.trial_balance') }}</x-slot>

<div class="overflow-x-auto">
    <table class="ierp-table">
        <thead>
            <tr>
                <th>{{ __('admin.accounting.reports.columns.account_code') }}</th>
                <th>{{ __('admin.accounting.reports.columns.account_name') }}</th>
                <th>{{ __('admin.accounting.reports.columns.account_type') }}</th>
                <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.opening_balance') }}</th>
                <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.period_debit') }}</th>
                <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.period_credit') }}</th>
                <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.closing_balance') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td style="padding-inline-start: {{ $row['depth'] }}rem">{{ $row['code'] }}</td>
                    <td>
                        {{ $row['name'] }}
                        @if ($row['isDeleted'])
                            <span class="text-gray-400">{{ __('admin.accounting.reports.deleted_suffix') }}</span>
                        @endif
                    </td>
                    <td>{{ $row['element'] }}</td>
                    <td class="ierp-table-num">{{ $row['openingBalance'] }}</td>
                    <td class="ierp-table-num">{{ $row['periodDebit'] }}</td>
                    <td class="ierp-table-num">{{ $row['periodCredit'] }}</td>
                    <td class="ierp-table-num font-semibold">{{ $row['closingBalance'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-6 text-center text-gray-500 dark:text-gray-400">{{ __('admin.accounting.reports.no_rows') }}</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="ierp-table-total">
                <td colspan="4">{{ __('admin.accounting.reports.total') }}</td>
                <td class="ierp-table-num">{{ $report['totalDebit'] }}</td>
                <td class="ierp-table-num">{{ $report['totalCredit'] }}</td>
                <td></td>
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
