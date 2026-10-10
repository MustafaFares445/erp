<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ __('Receivables ageing') }}</h2>
                    <p class="text-sm text-gray-500">{{ __('Derived from issued invoices, confirmed credits, posted payment allocations and approved write-offs.') }}</p>
                </div>
                <label class="ierp-label grid gap-1.5">
                        {{ __('As of') }}
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="asOf" />
                        </x-filament::input.wrapper>
                    </label>
            </div>
        </x-filament::section>

        @if($summary !== [])
            <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                @foreach([
                    __('Billed') => $summary['billed_minor'],
                    __('Credits') => $summary['credited_minor'],
                    __('Collected') => $summary['paid_minor'],
                    __('Written off') => $summary['written_off_minor'],
                    __('Outstanding') => $summary['outstanding_minor'],
                    __('Customer credit balance') => $summary['credit_balance_minor'],
                    __('Net receivable') => $summary['net_receivable_minor'],
                    __('AR control account') => $summary['control_account_minor'],
                ] as $label => $minor)
                    <x-filament::section>
                        <p class="ierp-metric-label">{{ $label }}</p>
                        <p class="ierp-metric-value mt-1">{{ number_format($minor / 100, 2) }}</p>
                    </x-filament::section>
                @endforeach
            </div>

            <x-filament::section>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">{{ __('Reconciliation proof') }}</h2>
                        <p class="text-sm text-gray-500">{{ __('Derived receivables subledger versus the posted Accounts Receivable control account. Differences are reported, never plugged.') }}</p>
                    </div>
                    <span class="ierp-status" data-tone="{{ $reconciliation['is_reconciled'] ? 'success' : 'danger' }}">
                        {{ $reconciliation['is_reconciled'] ? __('Reconciled') : __('Difference: :amount', ['amount' => number_format($reconciliation['difference_minor'] / 100, 2)]) }}
                    </span>
                </div>

                @if(! $reconciliation['is_reconciled'] && ($reconciliation['candidate_causes'] ?? []) !== [])
                    <div class="ierp-tile mt-4" data-tone="danger">
                        <p class="font-medium text-danger-700 dark:text-danger-300">{{ __('Candidate causes to investigate') }}</p>
                        <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-danger-700 dark:text-danger-300">
                            @foreach($reconciliation['candidate_causes'] as $cause)
                                <li>{{ $cause['message'] }} ({{ $cause['count'] }})</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="ierp-table">
                        <thead>
                            <tr>
                                <th>{{ __('Customer') }}</th>
                                <th>{{ __('Billed') }}</th>
                                <th>{{ __('Credits') }}</th>
                                <th>{{ __('Collected') }}</th>
                                <th>{{ __('Written off') }}</th>
                                <th>{{ __('Outstanding') }}</th>
                                <th>{{ __('Current') }}</th>
                                <th>1–30</th>
                                <th>31–60</th>
                                <th>61–90</th>
                                <th>{{ __('Over 90') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse([...$summary['customers'], ...$summary['credit_customers']] as $customer)
                                <tr>
                                    <td>
                                        {{ $customer['customer_name'] }}
                                        @if($customer['customer_deleted'])
                                            <span class="text-xs text-danger-600">{{ __('(deleted)') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ number_format($customer['billed_minor'] / 100, 2) }}</td>
                                    <td>{{ number_format($customer['credited_minor'] / 100, 2) }}</td>
                                    <td>{{ number_format($customer['paid_minor'] / 100, 2) }}</td>
                                    <td>{{ number_format($customer['written_off_minor'] / 100, 2) }}</td>
                                    <td class="font-medium">
                                        {{ number_format($customer['outstanding_minor'] / 100, 2) }}
                                        @if($customer['credit_balance_minor'] > 0)
                                            <span class="block text-xs text-gray-500">{{ __('Customer credit balance') }}: {{ number_format($customer['credit_balance_minor'] / 100, 2) }}</span>
                                        @endif
                                    </td>
                                    <td>{{ number_format($customer['buckets']['current'] / 100, 2) }}</td>
                                    <td>{{ number_format($customer['buckets']['1_30'] / 100, 2) }}</td>
                                    <td>{{ number_format($customer['buckets']['31_60'] / 100, 2) }}</td>
                                    <td>{{ number_format($customer['buckets']['61_90'] / 100, 2) }}</td>
                                    <td>{{ number_format($customer['buckets']['over_90'] / 100, 2) }}</td>
                                    <td>
                                        <button type="button" wire:click="showCustomer({{ $customer['customer_id'] }})" class="text-primary-600 hover:underline">{{ __('View detail') }}</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="12" class="py-6 text-center text-gray-500">{{ __('No outstanding receivables.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            @if($detail !== [])
                <x-filament::section>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold">{{ __(':name detail', ['name' => $selectedCustomerName]) }}</h2>
                            <p class="text-sm text-gray-500">{{ __('Open documents as of :date.', ['date' => $summary['as_of']]) }}</p>
                        </div>
                        <div class="flex gap-3 text-sm">
                            <button type="button" wire:click="downloadStatement" class="text-primary-600 hover:underline">{{ __('Download statement CSV') }}</button>
                            <button type="button" wire:click="clearCustomer" class="text-primary-600 hover:underline">{{ __('Back to all customers') }}</button>
                        </div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="ierp-table">
                            <thead>
                                <tr>
                                    <th>{{ __('Invoice') }}</th>
                                    <th>{{ __('Invoice date') }}</th>
                                    <th>{{ __('Due date') }}</th>
                                    <th>{{ __('Days overdue') }}</th>
                                    <th>{{ __('Total') }}</th>
                                    <th>{{ __('Credits') }}</th>
                                    <th>{{ __('Collected') }}</th>
                                    <th>{{ __('Written off') }}</th>
                                    <th>{{ __('Outstanding') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($detail['documents'] ?? [] as $document)
                                    <tr>
                                        <td>
                                            <a href="{{ $this->invoiceUrl((int) $document['invoice_id']) }}" class="font-medium text-primary-600 hover:underline">
                                                {{ $document['number'] }}
                                            </a>
                                        </td>
                                        <td>{{ $document['invoice_date'] }}</td>
                                        <td>{{ $document['due_date'] }}</td>
                                        <td>{{ $document['days_overdue'] }}</td>
                                        <td>{{ number_format($document['total_minor'] / 100, 2) }}</td>
                                        <td>{{ number_format($document['credited_minor'] / 100, 2) }}</td>
                                        <td>{{ number_format($document['paid_minor'] / 100, 2) }}</td>
                                        <td>{{ number_format($document['written_off_minor'] / 100, 2) }}</td>
                                        <td class="font-medium">{{ number_format($document['outstanding_minor'] / 100, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
