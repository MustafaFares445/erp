<x-filament::section>
<x-slot name="heading">{{ __('admin.accounting.report_type.balance_sheet') }}</x-slot>

@php
    $sectionMeta = [
        'asset' => ['label' => 'admin.accounting.reports.sections.asset', 'subtotal' => 'admin.accounting.reports.subtotal_assets'],
        'liability' => ['label' => 'admin.accounting.reports.sections.liability', 'subtotal' => 'admin.accounting.reports.subtotal_liabilities'],
        'equity' => ['label' => 'admin.accounting.reports.sections.equity', 'subtotal' => 'admin.accounting.reports.subtotal_equity'],
    ];
@endphp

@foreach ($sectionMeta as $key => $meta)
    <div class="mb-6">
        <h4 class="mb-2 font-semibold">{{ __($meta['label']) }}</h4>

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
                        <td class="py-2 pr-4" colspan="2">{{ __($meta['subtotal']) }}</td>
                        <td class="py-2 text-right">{{ $report['sections'][$key]['subtotal'] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endforeach

<div class="mb-4 flex items-center justify-between border-t border-gray-300 pt-3 font-semibold dark:border-gray-600">
    <span>{{ __('admin.accounting.reports.accumulated_earnings_label') }}</span>
    <span>{{ $report['accumulatedEarnings'] }}</span>
</div>

<div class="mt-4">
    @if ($report['balances'])
        <x-filament::badge color="success">{{ __('admin.accounting.reports.proof.balanced') }}</x-filament::badge>
    @else
        <x-filament::badge color="danger">{{ __('admin.accounting.reports.proof.out_of_balance', ['variance' => $report['variance']]) }}</x-filament::badge>
    @endif
</div>
</x-filament::section>
