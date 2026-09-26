@php
    use Illuminate\Support\Number;
    use Illuminate\Support\Str;
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Top customers">
        @if (empty($customers))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No sales activity exists for the selected period.
            </p>
        @else
            <ol class="flex flex-col divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($customers as $index => $customer)
                    <li class="flex items-center justify-between gap-x-4 py-2 first:pt-0 last:pb-0">
                        <div>
                            <p class="text-sm font-medium text-gray-950 dark:text-white">
                                {{ $index + 1 }}. {{ $customer['label'] }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $customer['orders_count'] }} {{ Str::plural('order', $customer['orders_count']) }}
                                · Avg {{ Number::currency($customer['average_value'], $currency) }}
                            </p>
                        </div>
                        <span class="text-sm font-medium text-gray-950 dark:text-white whitespace-nowrap">
                            {{ Number::currency($customer['value'], $currency) }}
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
