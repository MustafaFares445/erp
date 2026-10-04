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
                <div class="ierp-metric-label">Needs sourcing</div>
                <div class="ierp-metric-value">{{ count($needs) }}</div>
                <div class="ierp-metric-helper">{{ $salesNeeds }} driven by Sales demand</div>
            </div>
            <div class="ierp-card ierp-metric">
                <div class="ierp-metric-label">Missing supplier setup</div>
                <div class="ierp-metric-value {{ $withoutSupplier > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400' }}">{{ $withoutSupplier }}</div>
                <div class="ierp-metric-helper">Products with no active Supplier Product</div>
            </div>
            <div class="ierp-card">
                <label for="purchase-needs-search" class="ierp-label">Find a need</label>
                <x-filament::input.wrapper class="mt-2" prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        id="purchase-needs-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search product, SKU, Sales Order, or warehouse…"
                    />
                </x-filament::input.wrapper>
            </div>
        </div>

        <div class="ierp-card">
            <div class="text-sm font-semibold text-gray-950 dark:text-white">Purchase demand queue</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Sales shortages and Inventory replenishment that still need a commercial Purchase Order.
            </div>
        </div>

        <div class="ierp-card overflow-hidden p-0">
            <div class="overflow-x-auto">
                <table class="ierp-table ierp-table-band">
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th>Required</th>
                            <th>Covered</th>
                            <th>Remaining</th>
                            <th>Suppliers</th>
                            <th>Linked PO</th>
                            <th>Status</th>
                            <th>Next action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($needs as $need)
                            <tr>
                                <td>
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $need['source'] }}</div>
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
                                            <img src="{{ $need['image'] }}" alt="" class="h-10 w-10 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-white/10" />
                                        @else
                                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-xs font-semibold text-gray-500 dark:bg-white/5 dark:text-gray-400">
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
                                <td>{{ \App\Support\QuantityFormatter::display($need['required']) }}</td>
                                <td>{{ \App\Support\QuantityFormatter::display($need['covered']) }}</td>
                                <td class="font-semibold">{{ \App\Support\QuantityFormatter::display($need['remaining']) }}</td>
                                <td>{{ $need['supplier_count'] }}</td>
                                <td>
                                    @if ($need['linked_po_url'])
                                        <a href="{{ $need['linked_po_url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $need['linked_po'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">Needs PO</span>
                                    @endif
                                </td>
                                <td>{{ str($need['status'])->replace('_', ' ')->title() }}</td>
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
                                <td colspan="10" class="py-10 text-center text-gray-500 dark:text-gray-400">
                                    No uncovered purchase demand currently requires Purchasing attention.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
