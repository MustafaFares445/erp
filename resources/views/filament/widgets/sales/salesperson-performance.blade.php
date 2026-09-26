@php use Illuminate\Support\Number; @endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Salesperson performance">
        @if (empty($salespeople))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No quotations exist for the selected period.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-xs text-gray-500 dark:text-gray-400">
                            <th class="pb-2 text-start font-medium">Salesperson</th>
                            <th class="pb-2 text-end font-medium">Quotations</th>
                            <th class="pb-2 text-end font-medium">Orders</th>
                            <th class="pb-2 text-end font-medium">Conversion</th>
                            <th class="pb-2 text-end font-medium">Sales value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($salespeople as $salesperson)
                            <tr>
                                <td class="py-2 font-medium text-gray-950 dark:text-white">{{ $salesperson['label'] }}</td>
                                <td class="py-2 text-end text-gray-700 dark:text-gray-300">{{ $salesperson['quotations'] }}</td>
                                <td class="py-2 text-end text-gray-700 dark:text-gray-300">{{ $salesperson['orders'] }}</td>
                                <td class="py-2 text-end text-gray-700 dark:text-gray-300">{{ number_format($salesperson['conversion_percent'], 1) }}%</td>
                                <td class="py-2 text-end font-medium text-gray-950 dark:text-white">{{ Number::currency($salesperson['value'], $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
