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
                        <td colspan="2">{{ __($meta['subtotal']) }}</td>
                        <td class="ierp-table-num">{{ $report['sections'][$key]['subtotal'] }}</td>
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
