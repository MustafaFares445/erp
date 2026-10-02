<x-filament-panels::page>
    <div class="space-y-6" x-data x-on:barcode-focus.window="$nextTick(() => $refs.scan?.focus())">
        <div class="grid gap-4 lg:grid-cols-4">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">{{ __('Mode') }}</label>
                <select wire:model.live="mode" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900">
                    @foreach ($this->modeOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 lg:col-span-3">
                @if ($mode === 'count')
                    <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">{{ __('Inventory count') }}</label>
                    <select wire:model.live="countId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900">
                        <option value="">{{ __('Select an open count') }}</option>
                        @foreach ($this->countOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @else
                    <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">{{ __('Inventory operation') }}</label>
                    <select wire:model.live="operationId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900">
                        <option value="">{{ __('Select an open operation') }}</option>
                        @foreach ($this->operationOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" x-data x-init="$nextTick(() => $refs.scan?.focus())">
            <div class="mb-2 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Scan barcode, SKU, or serial') }}</h2>
                    <p class="text-sm text-gray-500">{{ __('USB/Bluetooth scanners work as keyboard input. Scan and press Enter.') }}</p>
                </div>
                @if ($this->targetUrl())
                    <a href="{{ $this->targetUrl() }}" class="text-sm font-medium text-primary-600">{{ __('Open source document') }} →</a>
                @endif
            </div>
            <div class="flex gap-3">
                <input
                    x-ref="scan"
                    autofocus
                    autocomplete="off"
                    wire:model="scanCode"
                    wire:keydown.enter.prevent="scan"
                    placeholder="{{ __('Scan now…') }}"
                    class="min-w-0 flex-1 rounded-lg border-gray-300 text-lg dark:border-white/10 dark:bg-gray-900"
                />
                <button wire:click="scan" type="button" class="rounded-lg bg-primary-600 px-5 py-2 font-semibold text-white hover:bg-primary-500">
                    {{ __('Scan') }}
                </button>
            </div>
        </div>

        @if ($resolution)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><div class="text-xs uppercase text-gray-400">{{ __('Matched by') }}</div><div class="font-semibold">{{ str($resolution['kind'])->headline() }}</div></div>
                    <div><div class="text-xs uppercase text-gray-400">{{ __('SKU') }}</div><div class="font-semibold">{{ $resolution['sku'] }}</div></div>
                    <div><div class="text-xs uppercase text-gray-400">{{ __('Variant') }}</div><div class="font-semibold">{{ $resolution['variant_name'] ?: '—' }}</div></div>
                    <div><div class="text-xs uppercase text-gray-400">{{ __('Serial') }}</div><div class="font-semibold">{{ $resolution['serial_number'] ?: '—' }}</div></div>
                </div>
            </div>
        @endif

        @if ($matches !== [])
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-white/10">
                    <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Matched document lines') }}</h2>
                    @if (count($matches) > 1)
                        <p class="mt-1 text-sm text-warning-600">{{ __('Multiple grains match this product. Choose the exact line before recording a count.') }}</p>
                    @endif
                </div>

                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($matches as $match)
                        <label class="flex cursor-pointer items-center gap-4 px-5 py-4 hover:bg-gray-50 dark:hover:bg-white/5">
                            @if ($mode === 'count')
                                <input type="radio" wire:model.live="countLineId" value="{{ $match['id'] }}" class="text-primary-600" />
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="font-semibold text-gray-950 dark:text-white">{{ $match['sku'] }} · {{ $match['variant'] }}</div>
                                <div class="mt-1 text-sm text-gray-500">
                                    @if ($match['serial'] ?? null) {{ __('Serial') }}: {{ $match['serial'] }} · @endif
                                    @if ($match['lot'] ?? null) {{ __('Lot') }}: {{ $match['lot'] }} · @endif
                                    @if ($mode === 'count')
                                        {{ __('Condition') }}: {{ $match['condition'] }} · {{ __('System') }}: {{ $match['system'] }} · {{ __('Counted') }}: {{ $match['counted'] ?? '—' }}
                                    @else
                                        {{ __('Quantity') }}: {{ $match['quantity'] }} · {{ __('Base') }}: {{ $match['base_quantity'] }}
                                    @endif
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($mode === 'count' && $countLineId)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-56 flex-1">
                        <label class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">{{ __('Counted base quantity') }}</label>
                        <input wire:model="countQuantity" type="number" min="0" step="0.000001" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-900" />
                    </div>
                    <button wire:click="recordCount" type="button" class="rounded-lg bg-success-600 px-5 py-2 font-semibold text-white hover:bg-success-500">
                        {{ __('Record count') }}
                    </button>
                </div>
            </div>
        @endif

        <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
            <strong>{{ __('Safety boundary:') }}</strong>
            {{ __('Scanning never writes stock directly. Receipt, delivery, and transfer custody changes stay on the original inventory operation and run through Inventory services. Physical counts are recorded through InventoryCountService.') }}
        </div>
    </div>
</x-filament-panels::page>
