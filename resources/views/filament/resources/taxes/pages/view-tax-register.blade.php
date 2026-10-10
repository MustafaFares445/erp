<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">{{ __('Tax register — :from to :to', ['from' => $from, 'to' => $to]) }}</h2>
                    <p class="text-sm text-gray-500">{{ __('Tax follows collection, not issuance: deferred tax charged on issued invoices only becomes payable once collected.') }}</p>
                </div>
                <div class="flex items-end gap-3">
                    <label class="ierp-label grid gap-1.5">
                        {{ __('From') }}
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="from" />
                        </x-filament::input.wrapper>
                    </label>
                    <label class="ierp-label grid gap-1.5">
                        {{ __('To') }}
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" wire:model.live="to" />
                        </x-filament::input.wrapper>
                    </label>
                </div>
            </div>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-5">
            @foreach([
                __('Output tax charged (deferred)') => $summary['output_tax_charged_deferred'] ?? '0.00',
                __('Output tax recognised (payable)') => $summary['output_tax_recognised_payable'] ?? '0.00',
                __('Output tax reversed') => $summary['output_tax_reversed'] ?? '0.00',
                __('Input tax recognised') => $summary['input_tax_recognised'] ?? '0.00',
                __('Net position') => $summary['net_position'] ?? '0.00',
            ] as $label => $amount)
                <x-filament::section>
                    <p class="ierp-metric-label">{{ $label }}</p>
                        <p class="ierp-metric-value mt-1">{{ number_format((float) $amount, 2) }}</p>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section>
            <div class="mb-3">
                <h2 class="text-lg font-semibold">{{ __('Reconciliation') }}</h2>
                <p class="text-sm text-gray-500">{{ __('Each tax account\'s register-derived movement compared to its actual ledger movement. A nonzero difference means a posting bypassed the canonical document flows.') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="ierp-table">
                    <thead>
                        <tr>
                            <th>{{ __('Account') }}</th>
                            <th>{{ __('Register') }}</th>
                            <th>{{ __('Journal') }}</th>
                            <th>{{ __('Difference') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(($reconciliation ?? []) as $account => $figures)
                            <tr>
                                <td>{{ __(ucfirst($account)) }}</td>
                                <td>{{ number_format((float) $figures['register'], 2) }}</td>
                                <td>{{ number_format((float) $figures['journal'], 2) }}</td>
                                <td>
                                    <span class="ierp-status" data-tone="{{ $figures['difference'] === '0.00' ? 'success' : 'danger' }}">
                                        {{ $figures['difference'] === '0.00' ? __('Reconciled') : __('Difference: :amount', ['amount' => number_format((float) $figures['difference'], 2)]) }}
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
                    <h2 class="text-lg font-semibold">{{ __('Entries') }}</h2>
                    <p class="text-sm text-gray-500">
                        {{ __('Showing :shown of :total entries in this period.', ['shown' => $entriesShown, 'total' => $entriesTotal]) }}
                        @if($entriesTotal > $entriesShown)
                            {{ __('Export the entries CSV for the full list.') }}
                        @endif
                    </p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="ierp-table">
                    <thead>
                        <tr>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Direction') }}</th>
                            <th>{{ __('Tax type') }}</th>
                            <th>{{ __('Amount') }}</th>
                            <th>{{ __('Document') }}</th>
                            <th>{{ __('Invoice') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(($entries ?? []) as $entry)
                            <tr>
                                <td>{{ $entry['tax_date'] }}</td>
                                <td><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium">{{ __(str($entry['direction'])->replace('_', ' ')->ucfirst()->toString()) }}</span></td>
                                <td>{{ __($entry['tax_type']) }}</td>
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
                                <td colspan="6" class="py-6 text-center text-gray-500">{{ __('No tax entries in this period.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
