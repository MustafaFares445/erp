<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('customer-location-picker') }}"
        x-data="customerLocationPicker({
            latitude: $wire.$entangle(@js($getStatePath())),
            longitude: $wire.$entangle(@js($field->getLongitudeStatePath())),
        })"
        {{ $getExtraAttributeBag() }}
    >
        <div class="flex gap-2">
            <x-filament::input.wrapper class="min-w-0 flex-1">
                <x-filament::input
                    x-model="searchTerm"
                    x-on:keydown.enter.prevent="search()"
                    type="search"
                    placeholder="Search for an address..."
                />
            </x-filament::input.wrapper>
            <x-filament::button color="gray" x-on:click="search()" type="button" class="shrink-0">
                Search
            </x-filament::button>
        </div>
        <div x-ref="map" wire:ignore class="customer-location-picker__map mt-4 w-full rounded-xl border border-gray-200 dark:border-white/10"></div>
        <p x-text="locationStatus" class="mt-3 text-sm text-gray-600 dark:text-gray-400" role="status" aria-live="polite"></p>
    </div>
</x-dynamic-component>
