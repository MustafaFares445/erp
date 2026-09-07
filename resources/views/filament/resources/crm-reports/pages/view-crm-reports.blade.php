<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">CRM report</x-slot>
            <select wire:model.live="reportType" class="fi-input w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                @foreach ($this->reportOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($reportType === 'pipeline_value_and_age')
                <p class="mt-3 text-sm text-gray-500">Lead age is available now. Monetary pipeline value is intentionally deferred until WP-2.2 introduces the canonical lead/opportunity link; this screen does not invent a duplicate value source.</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    @php($rows = $this->rows())
                    @if ($rows->isNotEmpty())
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                @foreach (array_keys($rows->first()) as $heading)
                                    <th class="px-3 py-2 text-start font-semibold">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    @foreach ($row as $value)
                                        <td class="px-3 py-2">{{ $value }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @else
                        <tbody><tr><td class="px-3 py-4 text-gray-500">No data for this report.</td></tr></tbody>
                    @endif
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
