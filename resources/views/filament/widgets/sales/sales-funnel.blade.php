@php use Illuminate\Support\Number; @endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboards.sales.cards.funnel')">
        @if (collect($stages)->sum('count') === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('dashboards.sales.empty.quotations') }}
            </p>
        @else
            <div class="flex flex-col gap-y-3">
                @foreach ($stages as $stage)
                    <div>
                        <div class="flex items-center justify-between gap-x-2 text-sm">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $stage['label'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $stage['count'] }} · {{ Number::currency($stage['value'], $currency) }}
                            </span>
                        </div>

                        <div class="ierp-progress-track mt-1">
                            <div
                                class="ierp-progress-fill"
                                style="width: {{ max(4, round($stage['count'] / $maxCount * 100)) }}%"
                            ></div>
                        </div>

                        @if ($stage['conversion_percent'] !== null)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('dashboards.sales.cards.of_previous_stage', ['percent' => number_format($stage['conversion_percent'], 1)]) }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
