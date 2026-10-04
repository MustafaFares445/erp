@php use Illuminate\Support\Number; @endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.sales.cards.quotation_performance')">
        <div class="flex flex-col gap-y-4">
            <div class="flex items-center justify-between gap-x-2 text-sm">
                <span class="text-gray-500 dark:text-gray-400">{{ __('dashboards.sales.cards.open_quotation_value') }}</span>
                <span class="font-medium text-gray-950 dark:text-white">
                    {{ $open['count'] }} · {{ Number::currency($open['value'], $currency) }}
                </span>
            </div>

            @if ($totalCount === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('dashboards.sales.empty.quotations') }}
                </p>
            @else
                <div>
                    <div class="ierp-progress-track flex w-full">
                        @foreach ($segments as $segment)
                            @php $width = $totalCount > 0 ? $segment['data']['count'] / $totalCount * 100 : 0; @endphp
                            @if ($width > 0)
                                <div
                                    @class([
                                        'h-2',
                                        'bg-success-600' => $segment['color'] === 'success',
                                        'bg-info-600' => $segment['color'] === 'info',
                                        'bg-danger-600' => $segment['color'] === 'danger',
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
                <span class="text-gray-500 dark:text-gray-400">{{ __('dashboards.sales.kpis.conversion') }}</span>
                <span class="font-medium text-gray-950 dark:text-white">
                    {{ $conversionPercent !== null ? number_format($conversionPercent, 1) . '%' : '—' }}
                </span>
            </div>

            <div class="flex items-center justify-between gap-x-2 text-sm">
                <span class="text-gray-500 dark:text-gray-400">{{ __('dashboards.sales.cards.median_days') }}</span>
                <span class="font-medium text-gray-950 dark:text-white">
                    {{ $medianDaysToDecision > 0 ? __('dashboards.sales.cards.days', ['days' => number_format($medianDaysToDecision, 0)]) : '—' }}
                </span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
