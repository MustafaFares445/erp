<x-filament-panels::page>
    @php
        $needs = $this->needs();
    @endphp

    <div class="space-y-4">
        @php
            $withoutSupplier = collect($needs)->where('supplier_count', 0)->count();
            $salesNeeds = collect($needs)->where('source', 'Sales Order')->count();
        @endphp

        <div class="grid gap-3 md:grid-cols-3">
            <div class="ierp-card ierp-metric">
                <div class="ierp-metric-label">{{ __('Needs sourcing') }}</div>
                <div class="ierp-metric-value">{{ count($needs) }}</div>
                <div class="ierp-metric-helper">{{ __(':count driven by Sales demand', ['count' => $salesNeeds]) }}</div>
            </div>
            <div class="ierp-card ierp-metric">
                <div class="ierp-metric-label">{{ __('Missing supplier setup') }}</div>
                <div class="ierp-metric-value {{ $withoutSupplier > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400' }}">{{ $withoutSupplier }}</div>
                <div class="ierp-metric-helper">{{ __('Products with no active Supplier Product') }}</div>
            </div>
            <div class="ierp-card">
                <label for="purchase-needs-search" class="ierp-label">{{ __('Find a need') }}</label>
                <x-filament::input.wrapper class="mt-2" prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        id="purchase-needs-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('Search product, SKU, Sales Order, or warehouse…')"
                    />
                </x-filament::input.wrapper>
            </div>
        </div>

        <div class="ierp-card">
            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Purchase demand queue') }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Sales shortages and Inventory replenishment that still need a commercial Purchase Order.') }}
            </div>
        </div>

        {{-- Related figures share one cell (stock position, suggested order, coverage) so the queue fits the content width instead of scrolling past the card edge. --}}
        <div class="ierp-card overflow-hidden p-0">
            <div class="overflow-x-auto">
                <table class="ierp-table ierp-table-band">
                    <thead>
                        <tr>
                            <th>{{ __('Source') }}</th>
                            <th>{{ __('Product') }}</th>
                            <th>{{ __('Warehouse') }}</th>
                            <th>{{ __('Stock position') }}</th>
                            <th>{{ __('Suggested order') }}</th>
                            <th>{{ __('Coverage') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Next action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($needs as $need)
                            <tr>
                                <td>
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $need['source_label'] }}</div>
                                    @if ($need['source_url'])
                                        <a href="{{ $need['source_url'] }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $need['source_reference'] }}
                                        </a>
                                    @else
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $need['source_reference'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        @if ($need['image'])
                                            <img src="{{ $need['image'] }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-white/10" />
                                        @else
                                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-xs font-semibold text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                                {{ mb_substr($need['product'], 0, 2) }}
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-medium text-gray-950 dark:text-white">{{ $need['product'] }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $need['sku'] }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $need['warehouse'] }}</td>
                                <td>
                                    @if ($need['available'] !== null)
                                        <dl class="grid grid-cols-2 gap-x-3 text-xs">
                                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Available') }}</dt>
                                            <dd class="whitespace-nowrap font-medium text-gray-950 dark:text-white">{{ \App\Support\QuantityFormatter::display($need['available']) }}</dd>
                                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Reserved') }}</dt>
                                            <dd class="whitespace-nowrap font-medium text-gray-950 dark:text-white">{{ \App\Support\QuantityFormatter::display($need['reserved']) }}</dd>
                                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Incoming') }}</dt>
                                            <dd class="whitespace-nowrap font-medium text-gray-950 dark:text-white">{{ \App\Support\QuantityFormatter::display($need['incoming']) }}</dd>
                                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Min / Max') }}</dt>
                                            <dd class="whitespace-nowrap font-medium text-gray-950 dark:text-white">{{ \App\Support\QuantityFormatter::display($need['minimum']) }} / {{ \App\Support\QuantityFormatter::display($need['maximum']) }}</dd>
                                        </dl>
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($need['suggested_quantity'] !== null)
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ \App\Support\QuantityFormatter::display($need['suggested_quantity']) }}</div>
                                    @endif
                                    @if ($need['suggested_supplier'])
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $need['suggested_supplier'] }}</div>
                                        @if ($need['lead_time_days'] !== null)
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Lead time: :days days', ['days' => $need['lead_time_days']]) }}</div>
                                        @endif
                                    @endif
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Eligible suppliers: :count', ['count' => $need['supplier_count']]) }}</div>
                                </td>
                                <td>
                                    <dl class="grid grid-cols-2 gap-x-3 text-xs">
                                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Required') }}</dt>
                                        <dd class="whitespace-nowrap font-medium text-gray-950 dark:text-white">{{ \App\Support\QuantityFormatter::display($need['required']) }}</dd>
                                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Covered') }}</dt>
                                        <dd class="whitespace-nowrap font-medium text-gray-950 dark:text-white">{{ \App\Support\QuantityFormatter::display($need['covered']) }}</dd>
                                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Remaining') }}</dt>
                                        <dd class="whitespace-nowrap font-semibold text-gray-950 dark:text-white">{{ \App\Support\QuantityFormatter::display($need['remaining']) }}</dd>
                                    </dl>
                                </td>
                                <td>
                                    <div>{{ $need['status_label'] }}</div>
                                    @if ($need['linked_po_url'])
                                        <a href="{{ $need['linked_po_url'] }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $need['linked_po'] }}
                                        </a>
                                    @else
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Needs PO') }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($need['next_action_type'] === 'sales_create' && $need['sales_order_id'])
                                        {{-- Blade compiles component tags before directives, so @js() cannot sit inside the attribute. --}}
                                        @php
                                            $createArguments = \Illuminate\Support\Js::from(['order_id' => $need['sales_order_id']]);
                                        @endphp
                                        <x-filament::button
                                            type="button"
                                            size="sm"
                                            wire:click="mountAction('createFromSalesDemand', {{ $createArguments }})"
                                        >
                                            {{ $need['next_action'] }}
                                        </x-filament::button>
                                    @elseif ($need['next_action_type'] === 'replenishment_create' && $need['replenishment_requirement_id'])
                                        @php
                                            $replenishmentArguments = \Illuminate\Support\Js::from([
                                                'requirement_id' => $need['replenishment_requirement_id'],
                                            ]);
                                        @endphp
                                        <x-filament::button
                                            type="button"
                                            size="sm"
                                            wire:click="mountAction('createFromReplenishment', {{ $replenishmentArguments }})"
                                        >
                                            {{ $need['next_action'] }}
                                        </x-filament::button>
                                    @elseif ($need['next_action_url'])
                                        <x-filament::button tag="a" size="sm" :href="$need['next_action_url']">
                                            {{ $need['next_action'] }}
                                        </x-filament::button>
                                    @else
                                        <span class="text-gray-600 dark:text-gray-300">{{ $need['next_action'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-10 text-center text-gray-500 dark:text-gray-400">
                                    {{ __('No uncovered purchase demand currently requires Purchasing attention.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
