<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Tax register — {{ $from }} to {{ $to }}</h2>
                    <p class="text-sm text-gray-500">Tax follows collection, not issuance: deferred tax charged on issued invoices only becomes payable once collected.</p>
                </div>
                <div class="flex items-end gap-3">
                    <label class="ierp-label grid gap-1.5">
                        From
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="from" />
                        </x-filament::input.wrapper>
                    </label>
                    <label class="ierp-label grid gap-1.5">
                        To
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="to" />
                        </x-filament::input.wrapper>
                    </label>
                </div>
            </div>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-5">
            @foreach([
                'Output tax charged (deferred)' => $summary['output_tax_charged_deferred'] ?? '0.00',
                'Output tax recognised (payable)' => $summary['output_tax_recognised_payable'] ?? '0.00',
                'Output tax reversed' => $summary['output_tax_reversed'] ?? '0.00',
                'Input tax recognised' => $summary['input_tax_recognised'] ?? '0.00',
                'Net position' => $summary['net_position'] ?? '0.00',
            ] as $label => $amount)
                <x-filament::section>
                    <p class="ierp-metric-label">{{ $label }}</p>
                        <p class="ierp-metric-value mt-1">{{ number_format((float) $amount, 2) }}</p>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section>
            <div class="mb-3">
                <h2 class="text-lg font-semibold">Reconciliation</h2>
                <p class="text-sm text-gray-500">Each tax account's register-derived movement compared to its actual ledger movement. A nonzero difference means a posting bypassed the canonical document flows.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="ierp-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Register</th>
                            <th>Journal</th>
                            <th>Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($reconciliation ?? []) as $account => $figures)
                            <tr>
                                <td class="capitalize">{{ $account }}</td>
                                <td>{{ number_format((float) $figures['register'], 2) }}</td>
                                <td>{{ number_format((float) $figures['journal'], 2) }}</td>
                                <td>
                                    <span class="ierp-status" data-tone="{{ $figures['difference'] === '0.00' ? 'success' : 'danger' }}">
                                        {{ $figures['difference'] === '0.00' ? 'Reconciled' : 'Difference: '.number_format((float) $figures['difference'], 2) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="mb-3 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Entries</h2>
                    <p class="text-sm text-gray-500">
                        Showing {{ $entriesShown }} of {{ $entriesTotal }} entries in this period.
                        @if($entriesTotal > $entriesShown)
                            Export the entries CSV for the full list.
                        @endif
                    </p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="ierp-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Direction</th>
                            <th>Tax type</th>
                            <th>Amount</th>
                            <th>Document</th>
                            <th>Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(($entries ?? []) as $entry)
                            <tr>
                                <td>{{ $entry['tax_date'] }}</td>
                                <td><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium">{{ $entry['direction'] }}</span></td>
                                <td>{{ $entry['tax_type'] }}</td>
                                <td>{{ number_format((float) $entry['tax_amount'], 2) }}</td>
                                <td>
                                    @if($entry['document_url'] !== null)
                                        <a href="{{ $entry['document_url'] }}" class="text-primary-600 hover:underline">{{ $entry['document_label'] }}</a>
                                    @else
                                        {{ $entry['document_label'] }}
                                    @endif
                                </td>
                                <td>
                                    @if($entry['invoice_url'] !== null)
                                        <a href="{{ $entry['invoice_url'] }}" class="text-primary-600 hover:underline">#{{ $entry['id'] }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-gray-500">No tax entries in this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
