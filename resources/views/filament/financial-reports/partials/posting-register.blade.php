<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.posting_register') }}</x-slot>

<div class="space-y-6">
    @forelse ($report as $entry)
        <div class="border border-gray-200 rounded-lg p-4 dark:border-gray-700">
            <div class="mb-2 grid grid-cols-1 gap-2 text-sm sm:grid-cols-5">
                <div><span class="text-gray-500 dark:text-gray-400">{{ __('admin.accounting.reports.columns.entry_number') }}:</span> {{ $entry['entryNumber'] }}</div>
                <div><span class="text-gray-500 dark:text-gray-400">{{ __('admin.accounting.reports.columns.entry_date') }}:</span> {{ $entry['entryDate'] }}</div>
                <div><span class="text-gray-500 dark:text-gray-400">{{ __('admin.accounting.reports.columns.fiscal_period') }}:</span> {{ $entry['fiscalPeriodName'] }}</div>
                <div><span class="text-gray-500 dark:text-gray-400">{{ __('admin.accounting.reports.columns.posted_by') }}:</span> {{ $entry['postedByName'] }}</div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">{{ __('admin.accounting.reports.columns.source') }}:</span>
                    @if ($entry['source'] === null)
                        —
                    @else
                        {{ $entry['source']['label'] }}
                    @endif
                </div>
            </div>

            @if ($entry['description'])
                <p class="mb-2 text-sm text-gray-600 dark:text-gray-300">{{ $entry['description'] }}</p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-1 pr-4">{{ __('admin.accounting.reports.columns.account_code') }}</th>
                            <th class="py-1 pr-4">{{ __('admin.accounting.reports.columns.account_name') }}</th>
                            <th class="py-1 pr-4 text-right">{{ __('admin.accounting.reports.columns.debit') }}</th>
                            <th class="py-1 text-right">{{ __('admin.accounting.reports.columns.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entry['lines'] as $line)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-1 pr-4">{{ $line['accountCode'] }}</td>
                                <td class="py-1 pr-4">{{ $line['accountName'] }}</td>
                                <td class="py-1 pr-4 text-right">{{ $line['debit'] }}</td>
                                <td class="py-1 text-right">{{ $line['credit'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <p class="text-gray-400">{{ __('admin.accounting.reports.no_rows') }}</p>
    @endforelse
</div>

<div class="mt-4">
    {{ $report->links() }}
</div>
</x-filament::section>
