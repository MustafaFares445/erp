<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ $this->reportOptions()[$reportType] ?? __('CRM report') }}</x-slot>
            <x-slot name="description">{{ $this->reportDescription() }}</x-slot>

            <div class="max-w-md">
                <label class="ierp-label" for="crm-report-type">{{ __('Report') }}</label>
                <x-filament::input.wrapper class="mt-2">
                    <x-filament::input.select id="crm-report-type" wire:model.live="reportType">
                        @foreach ($this->reportOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </x-filament::section>

        <div wire:loading.delay>
            <div class="ierp-card flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>{{ __('reporting.states.loading') }}</span>
            </div>
        </div>

        <div wire:loading.remove class="space-y-6">
            @if ($reportSummary !== [])
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($reportSummary as $metric)
                        <div class="ierp-card ierp-metric">
                            <div class="ierp-metric-label">{{ $metric['label'] }}</div>
                            <div class="ierp-metric-value">{{ $metric['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($reportType === \App\Enums\CrmReportType::StageConversion && $reportRows->isNotEmpty())
                @php
                    $stageMax = max(1, (int) $reportRows->max(__('Leads')));
                @endphp

                <x-filament::section>
                    <x-slot name="heading">{{ __('Pipeline stage distribution') }}</x-slot>
                    <x-slot name="description">{{ __('A visual comparison of lead volume across the current pipeline stages.') }}</x-slot>

                    <div class="space-y-3">
                        @foreach ($reportRows as $row)
                            @php
                                $count = is_numeric($row[__('Leads')] ?? null) ? (int) $row[__('Leads')] : 0;
                                $width = $count > 0 ? max(4, round(($count / $stageMax) * 100, 1)) : 0;
                            @endphp
                            <div class="grid gap-2 sm:grid-cols-[11rem_minmax(0,1fr)_4rem] sm:items-center">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $row[__('Stage')] ?? '—' }}</span>
                                <div class="h-2.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="img" aria-label="{{ ($row[__('Stage')] ?? __('Stage')).': '.$count }}">
                                    <div class="h-full rounded-full bg-primary-600 transition-all dark:bg-primary-500" style="width: {{ $width }}%"></div>
                                </div>
                                <span class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white sm:text-end">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section>
                <div class="ierp-section-bleed overflow-x-auto">
                    <table class="ierp-table ierp-table-band">
                        @if ($reportRows->isNotEmpty())
                            <thead>
                                <tr>
                                    @foreach (array_keys($reportRows->first()) as $heading)
                                        <th>{{ $heading }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($reportRows as $row)
                                    <tr>
                                        @foreach ($row as $value)
                                            <td class="tabular-nums">{{ $value === null || $value === '' ? '—' : $value }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        @else
                            <tbody>
                                <tr>
                                    <td class="py-10 text-center text-gray-500 dark:text-gray-400">
                                        {{ __('reporting.states.no_data') }}
                                    </td>
                                </tr>
                            </tbody>
                        @endif
                    </table>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
