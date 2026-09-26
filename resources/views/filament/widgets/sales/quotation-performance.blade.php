@php use Illuminate\Support\Number; @endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Quotation performance">
        <div class="flex flex-col gap-y-4">
            <div class="flex items-center justify-between gap-x-2 text-sm">
                <span class="text-gray-500 dark:text-gray-400">Open quotation value</span>
                <span class="font-medium text-gray-950 dark:text-white">
                    {{ $open['count'] }} · {{ Number::currency($open['value'], $currency) }}
                </span>
            </div>

            @if ($totalCount === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No quotations exist for the selected period.
                </p>
            @else
                <div>
                    <div class="flex h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        @foreach ($segments as $segment)
                            @php $width = $totalCount > 0 ? $segment['data']['count'] / $totalCount * 100 : 0; @endphp
                            @if ($width > 0)
                                <div
                                    @class([
                                        'h-2',
                                        'bg-success-500' => $segment['color'] === 'success',
                                        'bg-info-500' => $segment['color'] === 'info',
                                        'bg-danger-500' => $segment['color'] === 'danger',
                                    ])
                                    style="width: {{ $width }}%"
                                ></div>
                            @endif
                        @endforeach
                    </div>

                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        @foreach ($segments as $segment)
                            <span>
                                {{ $segment['label'] }}:
                                {{ $totalCount > 0 ? number_format($segment['data']['count'] / $totalCount * 100, 0) : 0 }}%
                                ({{ $segment['data']['count'] }})
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-between gap-x-2 text-sm">
                <span class="text-gray-500 dark:text-gray-400">Quote → order conversion</span>
                <span class="font-medium text-gray-950 dark:text-white">
                    {{ $conversionPercent !== null ? number_format($conversionPercent, 1) . '%' : '—' }}
                </span>
            </div>

            <div class="flex items-center justify-between gap-x-2 text-sm">
                <span class="text-gray-500 dark:text-gray-400">Median days to decision</span>
                <span class="font-medium text-gray-950 dark:text-white">
                    {{ $medianDaysToDecision > 0 ? number_format($medianDaysToDecision, 0) . ' days' : '—' }}
                </span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
