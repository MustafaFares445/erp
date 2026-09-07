<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Receivables ageing</h2>
                    <p class="text-sm text-gray-500">Derived from issued invoices, confirmed credits, posted payment allocations and approved write-offs.</p>
                </div>
                <label class="text-sm font-medium">
                    As of
                    <input type="date" wire:model.live="asOf" class="fi-input mt-1 block rounded-lg border-gray-300" />
                </label>
            </div>
        </x-filament::section>

        @if($summary !== [])
            <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                @foreach([
                    'Billed' => $summary['billed_minor'],
                    'Credits' => $summary['credited_minor'],
                    'Collected' => $summary['paid_minor'],
                    'Written off' => $summary['written_off_minor'],
                    'Outstanding' => $summary['outstanding_minor'],
                    'AR control account' => $summary['control_account_minor'],
                ] as $label => $minor)
                    <x-filament::section>
                        <p class="text-sm text-gray-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ number_format($minor / 100, 2) }}</p>
                    </x-filament::section>
                @endforeach
            </div>

            <x-filament::section>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">Reconciliation proof</h2>
                        <p class="text-sm text-gray-500">Derived receivables subledger versus the posted Accounts Receivable control account. Differences are reported, never plugged.</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-sm font-medium {{ $reconciliation['is_reconciled'] ? 'bg-success-100 text-success-700' : 'bg-danger-100 text-danger-700' }}">
                        {{ $reconciliation['is_reconciled'] ? 'Reconciled' : 'Difference: '.number_format($reconciliation['difference_minor'] / 100, 2) }}
                    </span>
                </div>

                @if(! $reconciliation['is_reconciled'] && ($reconciliation['candidate_causes'] ?? []) !== [])
                    <div class="mt-4 rounded-lg border border-danger-200 bg-danger-50 p-4">
                        <p class="font-medium text-danger-700">Candidate causes to investigate</p>
                        <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-danger-700">
                            @foreach($reconciliation['candidate_causes'] as $cause)
                                <li>{{ $cause['message'] }} ({{ $cause['count'] }})</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-gray-500">
                                <th class="px-3 py-2">Customer</th>
                                <th class="px-3 py-2">Billed</th>
                                <th class="px-3 py-2">Credits</th>
                                <th class="px-3 py-2">Collected</th>
                                <th class="px-3 py-2">Written off</th>
                                <th class="px-3 py-2">Outstanding</th>
                                <th class="px-3 py-2">Current</th>
                                <th class="px-3 py-2">1–30</th>
                                <th class="px-3 py-2">31–60</th>
                                <th class="px-3 py-2">61–90</th>
                                <th class="px-3 py-2">Over 90</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($summary['customers'] as $customer)
                                <tr class="border-b">
                                    <td class="px-3 py-2">
                                        {{ $customer['customer_name'] }}
                                        @if($customer['customer_deleted'])
                                            <span class="text-xs text-danger-600">(deleted)</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ number_format($customer['billed_minor'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($customer['credited_minor'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($customer['paid_minor'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($customer['written_off_minor'] / 100, 2) }}</td>
                                    <td class="px-3 py-2 font-medium">{{ number_format($customer['outstanding_minor'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($customer['buckets']['current'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($customer['buckets']['1_30'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($customer['buckets']['31_60'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($customer['buckets']['61_90'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($customer['buckets']['over_90'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">
                                        <button type="button" wire:click="showCustomer({{ $customer['customer_id'] }})" class="text-primary-600 hover:underline">View detail</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="12" class="px-3 py-6 text-center text-gray-500">No outstanding receivables.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            @if($detail !== [])
                <x-filament::section>
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold">{{ $selectedCustomerName }} detail</h2>
                            <p class="text-sm text-gray-500">Open documents as of {{ $summary['as_of'] }}.</p>
                        </div>
                        <div class="flex gap-3 text-sm">
                            <button type="button" wire:click="downloadStatement" class="text-primary-600 hover:underline">Download statement CSV</button>
                            <button type="button" wire:click="clearCustomer" class="text-primary-600 hover:underline">Back to all customers</button>
                        </div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b text-gray-500">
                                    <th class="px-3 py-2">Invoice</th>
                                    <th class="px-3 py-2">Invoice date</th>
                                    <th class="px-3 py-2">Due date</th>
                                    <th class="px-3 py-2">Days overdue</th>
                                    <th class="px-3 py-2">Total</th>
                                    <th class="px-3 py-2">Credits</th>
                                    <th class="px-3 py-2">Collected</th>
                                    <th class="px-3 py-2">Written off</th>
                                    <th class="px-3 py-2">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($detail['documents'] ?? [] as $document)
                                    <tr class="border-b">
                                        <td class="px-3 py-2">{{ $document['number'] }}</td>
                                        <td class="px-3 py-2">{{ $document['invoice_date'] }}</td>
                                        <td class="px-3 py-2">{{ $document['due_date'] }}</td>
                                        <td class="px-3 py-2">{{ $document['days_overdue'] }}</td>
                                        <td class="px-3 py-2">{{ number_format($document['total_minor'] / 100, 2) }}</td>
                                        <td class="px-3 py-2">{{ number_format($document['credited_minor'] / 100, 2) }}</td>
                                        <td class="px-3 py-2">{{ number_format($document['paid_minor'] / 100, 2) }}</td>
                                        <td class="px-3 py-2">{{ number_format($document['written_off_minor'] / 100, 2) }}</td>
                                        <td class="px-3 py-2 font-medium">{{ number_format($document['outstanding_minor'] / 100, 2) }}</td>
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