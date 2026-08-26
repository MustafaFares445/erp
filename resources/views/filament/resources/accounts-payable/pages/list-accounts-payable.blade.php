<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Payable aging</h2>
                    <p class="text-sm text-gray-500">Computed from approved supplier bills and expenses as of the selected date.</p>
                </div>
                <label class="text-sm font-medium">
                    As of
                    <input type="date" wire:model.live="asOf" class="fi-input mt-1 block rounded-lg border-gray-300" />
                </label>
            </div>
        </x-filament::section>

        @if($summary !== [])
            <div class="grid gap-4 md:grid-cols-4">
                @foreach([
                    'Billed' => $summary['billed_minor'],
                    'Paid' => $summary['paid_minor'],
                    'Outstanding' => $summary['outstanding_minor'],
                    'Payable control account' => $summary['control_account_minor'],
                ] as $label => $minor)
                    <x-filament::section>
                        <p class="text-sm text-gray-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ number_format($minor / 100, 2) }}</p>
                    </x-filament::section>
                @endforeach
            </div>

            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Tie-out proof</h2>
                        <p class="text-sm text-gray-500">Subledger outstanding minus payable control account.</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-sm font-medium {{ $summary['is_reconciled'] ? 'bg-success-100 text-success-700' : 'bg-danger-100 text-danger-700' }}">
                        {{ $summary['is_reconciled'] ? 'Reconciled' : 'Difference: '.number_format($summary['tie_out_difference_minor'] / 100, 2) }}
                    </span>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-gray-500">
                                <th class="px-3 py-2">Supplier</th>
                                <th class="px-3 py-2">Billed</th>
                                <th class="px-3 py-2">Paid</th>
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
                            @forelse($summary['suppliers'] as $supplier)
                                <tr class="border-b">
                                    <td class="px-3 py-2">
                                        {{ $supplier['supplier_name'] }}
                                        @if($supplier['supplier_deleted'])
                                            <span class="text-xs text-danger-600">(deleted)</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ number_format($supplier['billed_minor'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($supplier['paid_minor'] / 100, 2) }}</td>
                                    <td class="px-3 py-2 font-medium">{{ number_format($supplier['outstanding_minor'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($supplier['buckets']['current'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($supplier['buckets']['1_30'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($supplier['buckets']['31_60'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($supplier['buckets']['61_90'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">{{ number_format($supplier['buckets']['over_90'] / 100, 2) }}</td>
                                    <td class="px-3 py-2">
                                        <button type="button" wire:click="showSupplier({{ $supplier['supplier_id'] }})" class="text-primary-600 hover:underline">View detail</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="px-3 py-6 text-center text-gray-500">No outstanding payables.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            @if($detail !== [])
                <x-filament::section>
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold">{{ $selectedSupplierName }} detail</h2>
                            <p class="text-sm text-gray-500">Open documents as of {{ $summary['as_of'] }}.</p>
                        </div>
                        <button type="button" wire:click="clearSupplier" class="text-sm text-primary-600 hover:underline">Back to all suppliers</button>
                    </div>
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b text-gray-500">
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Number</th>
                                    <th class="px-3 py-2">Supplier reference</th>
                                    <th class="px-3 py-2">Date</th>
                                    <th class="px-3 py-2">Due date</th>
                                    <th class="px-3 py-2">Days overdue</th>
                                    <th class="px-3 py-2">Total</th>
                                    <th class="px-3 py-2">Paid</th>
                                    <th class="px-3 py-2">Remaining</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($detail['documents'] ?? [] as $document)
                                    <tr class="border-b">
                                        <td class="px-3 py-2">{{ ucfirst($document['type']) }}</td>
                                        <td class="px-3 py-2">{{ $document['number'] }}</td>
                                        <td class="px-3 py-2">{{ $document['supplier_reference'] ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ $document['date'] }}</td>
                                        <td class="px-3 py-2">{{ $document['due_date'] }}</td>
                                        <td class="px-3 py-2">{{ $document['days_overdue'] }}</td>
                                        <td class="px-3 py-2">{{ number_format($document['total_minor'] / 100, 2) }}</td>
                                        <td class="px-3 py-2">{{ number_format($document['paid_minor'] / 100, 2) }}</td>
                                        <td class="px-3 py-2 font-medium">{{ number_format($document['remaining_minor'] / 100, 2) }}</td>
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
