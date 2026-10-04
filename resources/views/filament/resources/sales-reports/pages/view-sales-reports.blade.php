<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ $selectedType->label() }}</x-slot>
            <x-slot name="description">{{ $this->reportDescription() }}</x-slot>

            <div class="grid gap-4 lg:grid-cols-4 lg:items-end">
                <div class="lg:col-span-2">
                    <label class="ierp-label" for="sales-report-type">{{ __('Report') }}</label>
                    <x-filament::input.wrapper class="mt-2">
                        <x-filament::input.select id="sales-report-type" wire:model.live="reportType">
                            @foreach ($this->reportTypeOptions() as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                @unless ($this->usesAsOfDate())
                    <div>
                        <label class="ierp-label" for="sales-report-from">{{ __('From') }}</label>
                        <x-filament::input.wrapper class="mt-2">
                            <x-filament::input id="sales-report-from" type="date" wire:model.live="from" />
                        </x-filament::input.wrapper>
                    </div>
                @endunless

                <div>
                    <label class="ierp-label" for="sales-report-to">
                        {{ $this->usesAsOfDate() ? __('As of') : __('To') }}
                    </label>
                    <x-filament::input.wrapper class="mt-2">
                        <x-filament::input id="sales-report-to" type="date" wire:model.live="to" />
                    </x-filament::input.wrapper>
                </div>
            </div>

            @if ($from || $to)
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    @if (! $this->usesAsOfDate() && $from)
                        <x-filament::badge color="gray">{{ __('From') }}: {{ $from }}</x-filament::badge>
                    @endif
                    @if ($to)
                        <x-filament::badge color="gray">{{ $this->usesAsOfDate() ? __('As of') : __('To') }}: {{ $to }}</x-filament::badge>
                    @endif
                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ __('reporting.actions.clear_filters') }}
                    </button>
                </div>
            @endif
        </x-filament::section>

        <div wire:loading.delay>
            <div class="ierp-card flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>{{ __('reporting.states.loading') }}</span>
            </div>
        </div>

        <div wire:loading.remove class="space-y-6">
            @if ($presentation['metrics'] !== [])
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($presentation['metrics'] as $metric)
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ $metric['label'] }}</div>
                            <div class="ierp-metric-value tabular-nums">{{ $metric['value'] }}</div>
                            @if (! empty($metric['helper']))
                                <div class="ierp-metric-helper">{{ $metric['helper'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($selectedType === \App\Enums\SalesReportType::QuotationFunnel)
                @php
                    $funnelTable = collect($presentation['tables'])->firstWhere('heading', __('Quotation status'));
                    $funnelRows = is_array($funnelTable) ? ($funnelTable['rows'] ?? []) : [];
                    $funnelMax = max([1, ...array_map(
                        static fn (array $row): int => (int) ($row[1]['value'] ?? 0),
                        $funnelRows,
                    )]);
                @endphp

                @if ($funnelRows !== [])
                    <x-filament::section>
                        <x-slot name="heading">{{ __('Quotation funnel') }}</x-slot>
                        <x-slot name="description">{{ __('A quick visual comparison of quotation volume by status. Exact values remain available in the table below.') }}</x-slot>

                        <div class="space-y-3">
                            @foreach ($funnelRows as $row)
                                @php
                                    $count = (int) ($row[1]['value'] ?? 0);
                                    $width = $count > 0 ? max(4, round(($count / $funnelMax) * 100, 1)) : 0;
                                @endphp
                                <div class="grid gap-2 sm:grid-cols-[11rem_minmax(0,1fr)_4rem] sm:items-center">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $row[0]['value'] ?? '—' }}</span>
                                    <div class="h-2.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="img" aria-label="{{ ($row[0]['value'] ?? __('Status')).': '.$count }}">
                                        <div class="h-full rounded-full bg-primary-600 transition-all dark:bg-primary-500" style="width: {{ $width }}%"></div>
                                    </div>
                                    <span class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white sm:text-end">{{ $count }}</span>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif
            @endif

            @forelse ($presentation['tables'] as $table)
                <x-filament::section>
                    <x-slot name="heading">{{ $table['heading'] }}</x-slot>

                    <div class="ierp-section-bleed overflow-x-auto">
                        <table class="ierp-table ierp-table-band">
                            <thead>
                                <tr>
                                    @foreach ($table['columns'] as $column)
                                        <th>{{ $column }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($table['rows'] as $row)
                                    <tr>
                                        @foreach ($row as $cell)
                                            <td class="tabular-nums">
                                                @if ($cell['url'])
                                                    <a href="{{ $cell['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                        {{ $cell['value'] }}
                                                    </a>
                                                @else
                                                    {{ $cell['value'] }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ max(1, count($table['columns'])) }}" class="py-10 text-center text-gray-500 dark:text-gray-400">
                                            {{ ($from || $to) ? __('reporting.states.no_results') : __('reporting.states.no_data') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @empty
                @if ($presentation['metrics'] === [])
                    <div class="ierp-card py-10 text-center text-gray-500 dark:text-gray-400">
                        {{ ($from || $to) ? __('reporting.states.no_results') : __('reporting.states.no_data') }}
                    </div>
                @endif
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
