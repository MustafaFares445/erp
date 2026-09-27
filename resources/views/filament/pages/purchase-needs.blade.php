<x-filament-panels::page>
    @php
        $needs = $this->needs();
    @endphp

    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="text-sm font-medium text-gray-950 dark:text-white">Unified procurement demand</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                This queue combines Sales shortages and Inventory replenishment demand without duplicating their source records.
                Purchasing owns the commercial Purchase Order created to cover the demand.
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
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $need['product'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $need['sku'] }}</div>
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
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $need['next_action'] }}</td>
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
