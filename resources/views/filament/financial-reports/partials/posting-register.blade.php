<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.posting_register') }}</x-slot>

<div class="space-y-6">
    @forelse ($report as $entry)
        <div class="ierp-tile">
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
                <table class="ierp-table">
                    <thead>
                        <tr>
                            <th>{{ __('admin.accounting.reports.columns.account_code') }}</th>
                            <th>{{ __('admin.accounting.reports.columns.account_name') }}</th>
                            <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.debit') }}</th>
                            <th class="ierp-table-num">{{ __('admin.accounting.reports.columns.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entry['lines'] as $line)
                            <tr>
                                <td>{{ $line['accountCode'] }}</td>
                                <td>{{ $line['accountName'] }}</td>
                                <td class="ierp-table-num">{{ $line['debit'] }}</td>
                                <td class="ierp-table-num">{{ $line['credit'] }}</td>
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
