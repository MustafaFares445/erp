<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.general_ledger') }}</x-slot>

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400">
                <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.entry_number') }}</th>
                <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.entry_date') }}</th>
                <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.account_code') }}</th>
                <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.account_name') }}</th>
                <th class="py-2 pr-4">{{ __('admin.accounting.reports.columns.description') }}</th>
                <th class="py-2 pr-4 text-right">{{ __('admin.accounting.reports.columns.debit') }}</th>
                <th class="py-2 pr-4 text-right">{{ __('admin.accounting.reports.columns.credit') }}</th>
                <th class="py-2 text-right">{{ __('admin.accounting.reports.columns.running_balance') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report as $line)
                <tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="py-2 pr-4">{{ $line['entryNumber'] }}</td>
                    <td class="py-2 pr-4">{{ $line['entryDate'] }}</td>
                    <td class="py-2 pr-4">{{ $line['accountCode'] }}</td>
                    <td class="py-2 pr-4">{{ $line['accountName'] }}</td>
                    <td class="py-2 pr-4">{{ $line['description'] }}</td>
                    <td class="py-2 pr-4 text-right">{{ $line['debit'] }}</td>
                    <td class="py-2 pr-4 text-right">{{ $line['credit'] }}</td>
                    <td class="py-2 text-right font-semibold">{{ $line['runningBalance'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-3 text-gray-400">{{ __('admin.accounting.reports.no_rows') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $report->links() }}
</div>
</x-filament::section>
