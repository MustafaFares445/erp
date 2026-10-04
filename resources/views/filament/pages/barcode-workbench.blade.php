<x-filament-panels::page>
    <div class="space-y-6" x-data x-on:barcode-focus.window="$nextTick(() => $refs.scan?.focus())">
        <div class="grid gap-4 lg:grid-cols-4">
            <div class="ierp-card">
                <label class="ierp-label mb-2 block">{{ __('Mode') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="mode">
                        @foreach ($this->modeOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div class="ierp-card lg:col-span-3">
                @if ($mode === 'count')
                    <label class="ierp-label mb-2 block">{{ __('Inventory count') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="countId">
                            <option value="">{{ __('Select an open count') }}</option>
                            @foreach ($this->countOptions() as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @else
                    <label class="ierp-label mb-2 block">{{ __('Inventory operation') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="operationId">
                            <option value="">{{ __('Select an open operation') }}</option>
                            @foreach ($this->operationOptions() as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif
            </div>
        </div>

        <div class="ierp-card p-5" x-data x-init="$nextTick(() => $refs.scan?.focus())">
            <div class="mb-3 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Scan barcode, SKU, or serial') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('USB/Bluetooth scanners work as keyboard input. Scan and press Enter.') }}</p>
                </div>
                @if ($this->targetUrl())
                    <a href="{{ $this->targetUrl() }}" class="text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">{{ __('Open source document') }} →</a>
                @endif
            </div>
            <div class="flex gap-3">
                <x-filament::input.wrapper class="min-w-0 flex-1">
                    <x-filament::input
                        x-ref="scan"
                        autofocus
                        autocomplete="off"
                        wire:model="scanCode"
                        wire:keydown.enter.prevent="scan"
                        placeholder="{{ __('Scan now…') }}"
                    />
                </x-filament::input.wrapper>
                <x-filament::button wire:click="scan" type="button" size="lg">
                    {{ __('Scan') }}
                </x-filament::button>
            </div>
        </div>

        @if ($resolution)
            <div class="ierp-card p-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><div class="ierp-eyebrow">{{ __('Matched by') }}</div><div class="font-semibold">{{ str($resolution['kind'])->headline() }}</div></div>
                    <div><div class="ierp-eyebrow">{{ __('SKU') }}</div><div class="font-semibold">{{ $resolution['sku'] }}</div></div>
                    <div><div class="ierp-eyebrow">{{ __('Variant') }}</div><div class="font-semibold">{{ $resolution['variant_name'] ?: '—' }}</div></div>
                    <div><div class="ierp-eyebrow">{{ __('Serial') }}</div><div class="font-semibold">{{ $resolution['serial_number'] ?: '—' }}</div></div>
                </div>
            </div>
        @endif

        @if ($matches !== [])
            <div class="ierp-card overflow-hidden p-0">
                <div class="border-b border-gray-200 px-5 py-4 dark:border-white/10">
                    <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Matched document lines') }}</h2>
                    @if (count($matches) > 1)
                        <div class="ierp-alert mt-2" data-tone="warning">
                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="ierp-alert-icon" />
                            <span>{{ __('Multiple grains match this product. Choose the exact line before recording a count.') }}</span>
                        </div>
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
                                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
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
            <div class="ierp-card p-5">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-56 flex-1">
                        <label class="ierp-label mb-2 block">{{ __('Counted base quantity') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input wire:model="countQuantity" type="number" min="0" step="0.000001" />
                        </x-filament::input.wrapper>
                    </div>
                    <x-filament::button wire:click="recordCount" type="button" color="success" size="lg">
                        {{ __('Record count') }}
                    </x-filament::button>
                </div>
            </div>
        @endif

        <div class="ierp-alert" data-tone="info">
            <x-filament::icon icon="heroicon-m-information-circle" class="ierp-alert-icon" />
            <div>
                <strong>{{ __('Safety boundary:') }}</strong>
                {{ __('Scanning never writes stock directly. Receipt, delivery, and transfer custody changes stay on the original inventory operation and run through Inventory services. Physical counts are recorded through InventoryCountService.') }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
