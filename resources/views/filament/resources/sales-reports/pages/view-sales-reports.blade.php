<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Sales report</x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="text-sm font-medium">Report type</label>
                    <select wire:model.live="reportType" class="fi-input w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                        @foreach ($this->reportTypeOptions() as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium">From</label>
                    <input type="date" wire:model.live="from" class="fi-input w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                </div>
                <div>
                    <label class="text-sm font-medium">To</label>
                    <input type="date" wire:model.live="to" class="fi-input w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                </div>
            </div>
        </x-filament::section>

        @php($summary = $this->summaryFields())

        @if ($summary !== [])
            <x-filament::section>
                <x-slot name="heading">Summary</x-slot>
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach ($summary as $label => $value)
                        <div>
                            <dt class="text-xs uppercase text-gray-500">{{ str($label)->replace('_', ' ') }}</dt>
                            <dd class="text-sm font-semibold">
                                @if (is_bool($value))
                                    {{ $value ? 'Yes' : 'No' }}
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif

        @if ($this->hasNoDetailRows())
            <x-filament::section>
                <p class="text-sm text-gray-500">No data for this report in the selected period.</p>
            </x-filament::section>
        @else
            @foreach ($this->tableSections() as $sectionKey => $rows)
                <x-filament::section>
                    <x-slot name="heading">{{ str($sectionKey)->replace('_', ' ')->headline() }}</x-slot>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    @foreach (array_keys($rows[0]) as $heading)
                                        <th class="px-3 py-2 text-start font-semibold">{{ str($heading)->replace('_', ' ') }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        @foreach ($row as $cell)
                                            <td class="px-3 py-2">{{ is_scalar($cell) || $cell === null ? $cell : json_encode($cell) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
