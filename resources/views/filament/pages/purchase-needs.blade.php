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
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Needs sourcing</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ count($needs) }}</div>
                <div class="mt-1 text-xs text-gray-500">{{ $salesNeeds }} driven by Sales demand</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Missing supplier setup</div>
                <div class="mt-1 text-2xl font-semibold {{ $withoutSupplier > 0 ? 'text-warning-600' : 'text-success-600' }}">{{ $withoutSupplier }}</div>
                <div class="mt-1 text-xs text-gray-500">Products with no active Supplier Product</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <label for="purchase-needs-search" class="text-xs font-medium uppercase tracking-wide text-gray-500">Find a need</label>
                <input
                    id="purchase-needs-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search product, SKU, Sales Order, or warehouse…"
                    class="mt-2 w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950"
                />
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-sm font-medium text-gray-950 dark:text-white">Purchase demand queue</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Sales shortages and Inventory replenishment that still need a commercial Purchase Order.
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3">Warehouse</th>
                            <th class="px-4 py-3">Required</th>
                            <th class="px-4 py-3">Covered</th>
                            <th class="px-4 py-3">Remaining</th>
                            <th class="px-4 py-3">Suppliers</th>
                            <th class="px-4 py-3">Linked PO</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Next action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($needs as $need)
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $need['source'] }}</div>
                                    @if ($need['source_url'])
                                        <a href="{{ $need['source_url'] }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $need['source_reference'] }}
                                        </a>
                                    @else
                                        <div class="text-xs text-gray-500">{{ $need['source_reference'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        @if ($need['image'])
                                            <img src="{{ $need['image'] }}" alt="" class="h-10 w-10 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-white/10" />
                                        @else
                                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-xs font-semibold text-gray-500 dark:bg-white/5">
                                                {{ mb_substr($need['product'], 0, 2) }}
                                            </div>
                                        @endif
                                        <div>
                                            <div class="font-medium text-gray-950 dark:text-white">{{ $need['product'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $need['sku'] }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ $need['warehouse'] }}</td>
                                <td class="px-4 py-3">{{ \App\Support\QuantityFormatter::display($need['required']) }}</td>
                                <td class="px-4 py-3">{{ \App\Support\QuantityFormatter::display($need['covered']) }}</td>
                                <td class="px-4 py-3 font-semibold">{{ \App\Support\QuantityFormatter::display($need['remaining']) }}</td>
                                <td class="px-4 py-3">{{ $need['supplier_count'] }}</td>
                                <td class="px-4 py-3">
                                    @if ($need['linked_po_url'])
                                        <a href="{{ $need['linked_po_url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $need['linked_po'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-500">Needs PO</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ str($need['status'])->replace('_', ' ')->title() }}</td>
                                <td class="px-4 py-3">
                                    @if ($need['next_action_type'] === 'sales_create' && $need['sales_order_id'])
                                        <button
                                            type="button"
                                            wire:click="mountAction('createFromSalesDemand', @js(['order_id' => $need['sales_order_id']]))"
                                            class="inline-flex items-center rounded-lg bg-primary-600 px-3 py-2 text-xs font-semibold text-white hover:bg-primary-500"
                                        >
                                            {{ $need['next_action'] }}
                                        </button>
                                    @elseif ($need['next_action_url'])
                                        <a href="{{ $need['next_action_url'] }}" class="inline-flex items-center rounded-lg bg-primary-600 px-3 py-2 text-xs font-semibold text-white hover:bg-primary-500">
                                            {{ $need['next_action'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-600 dark:text-gray-300">{{ $need['next_action'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-10 text-center text-gray-500">
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
