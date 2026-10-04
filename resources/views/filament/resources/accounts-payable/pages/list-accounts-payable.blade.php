<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Payable aging</h2>
                    <p class="text-sm text-gray-500">Computed from approved supplier bills and expenses as of the selected date.</p>
                </div>
                <label class="ierp-label grid gap-1.5">
                        As of
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="asOf" />
                        </x-filament::input.wrapper>
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
                        <p class="ierp-metric-label">{{ $label }}</p>
                        <p class="ierp-metric-value mt-1">{{ number_format($minor / 100, 2) }}</p>
                    </x-filament::section>
                @endforeach
            </div>

            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Tie-out proof</h2>
                        <p class="text-sm text-gray-500">Subledger outstanding minus payable control account.</p>
                    </div>
                    <span class="ierp-status" data-tone="{{ $summary['is_reconciled'] ? 'success' : 'danger' }}">
                        {{ $summary['is_reconciled'] ? 'Reconciled' : 'Difference: '.number_format($summary['tie_out_difference_minor'] / 100, 2) }}
                    </span>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="overflow-x-auto">
                    <table class="ierp-table">
                        <thead>
                            <tr>
                                <th>Supplier</th>
                                <th>Billed</th>
                                <th>Paid</th>
                                <th>Outstanding</th>
                                <th>Current</th>
                                <th>1–30</th>
                                <th>31–60</th>
                                <th>61–90</th>
                                <th>Over 90</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($summary['suppliers'] as $supplier)
                                <tr>
                                    <td>
                                        {{ $supplier['supplier_name'] }}
                                        @if($supplier['supplier_deleted'])
                                            <span class="text-xs text-danger-600">(deleted)</span>
                                        @endif
                                    </td>
                                    <td>{{ number_format($supplier['billed_minor'] / 100, 2) }}</td>
                                    <td>{{ number_format($supplier['paid_minor'] / 100, 2) }}</td>
                                    <td class="font-medium">{{ number_format($supplier['outstanding_minor'] / 100, 2) }}</td>
                                    <td>{{ number_format($supplier['buckets']['current'] / 100, 2) }}</td>
                                    <td>{{ number_format($supplier['buckets']['1_30'] / 100, 2) }}</td>
                                    <td>{{ number_format($supplier['buckets']['31_60'] / 100, 2) }}</td>
                                    <td>{{ number_format($supplier['buckets']['61_90'] / 100, 2) }}</td>
                                    <td>{{ number_format($supplier['buckets']['over_90'] / 100, 2) }}</td>
                                    <td>
                                        <button type="button" wire:click="showSupplier({{ $supplier['supplier_id'] }})" class="text-primary-600 hover:underline">View detail</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="py-6 text-center text-gray-500">No outstanding payables.</td>
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
                        <div class="flex gap-3 text-sm">
                            <button type="button" wire:click="downloadStatement" class="text-primary-600 hover:underline">Download statement CSV</button>
                            <button type="button" wire:click="clearSupplier" class="text-primary-600 hover:underline">Back to all suppliers</button>
                        </div>
                    </div>
                    <div class="mt-4 overflow-x-auto">
                        <table class="ierp-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Number</th>
                                    <th>Supplier reference</th>
                                    <th>Date</th>
                                    <th>Due date</th>
                                    <th>Days overdue</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Remaining</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($detail['documents'] ?? [] as $document)
                                    <tr>
                                        <td>{{ ucfirst($document['type']) }}</td>
                                        <td>
                                            @php($documentUrl = $this->documentUrl((string) $document['type'], (int) $document['document_id']))
                                            @if($documentUrl)
                                                <a href="{{ $documentUrl }}" class="font-medium text-primary-600 hover:underline">{{ $document['number'] }}</a>
                                            @else
                                                {{ $document['number'] }}
                                            @endif
                                        </td>
                                        <td>{{ $document['supplier_reference'] ?? '—' }}</td>
                                        <td>{{ $document['date'] }}</td>
                                        <td>{{ $document['due_date'] }}</td>
                                        <td>{{ $document['days_overdue'] }}</td>
                                        <td>{{ number_format($document['total_minor'] / 100, 2) }}</td>
                                        <td>{{ number_format($document['paid_minor'] / 100, 2) }}</td>
                                        <td class="font-medium">{{ number_format($document['remaining_minor'] / 100, 2) }}</td>
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
