<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.general_ledger') }}</x-slot>

<div class="overflow-x-auto">
    <table class="ierp-table">
        <thead>
            <tr>
                <th>{{ __('admin.accounting.reports.columns.entry_number') }}</th>
                <th>{{ __('admin.accounting.reports.columns.entry_date') }}</th>
                <th>{{ __('admin.accounting.reports.columns.account_code') }}</th>
                <th>{{ __('admin.accounting.reports.columns.account_name') }}</th>
                <th>{{ __('admin.accounting.reports.columns.description') }}</th>
                <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.debit') }}</th>
                <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.credit') }}</th>
                <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.running_balance') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report as $line)
                <tr>
                    <td>{{ $line['entryNumber'] }}</td>
                    <td>{{ $line['entryDate'] }}</td>
                    <td>{{ $line['accountCode'] }}</td>
                    <td>{{ $line['accountName'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="ierp-table-num">{{ $line['debit'] }}</td>
                    <td class="ierp-table-num">{{ $line['credit'] }}</td>
                    <td class="ierp-table-num font-semibold">{{ $line['runningBalance'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-6 text-center text-gray-500 dark:text-gray-400">{{ __('admin.accounting.reports.no_rows') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $report->links() }}
</div>
</x-filament::section>
