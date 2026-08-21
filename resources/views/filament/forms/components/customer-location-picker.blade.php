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
            <input
                x-model="searchTerm"
                x-on:keydown.enter.prevent="search()"
                type="search"
                placeholder="Search for an address..."
                class="fi-input block w-full rounded-lg border-gray-300"
            >
            <button x-on:click="search()" type="button" class="fi-btn fi-btn-color-gray shrink-0">
                Search
            </button>
        </div>
        <div x-ref="map" wire:ignore class="customer-location-picker__map mt-4 w-full rounded-lg border border-gray-300"></div>
        <p x-text="locationStatus" class="mt-3 text-sm text-gray-600" role="status" aria-live="polite"></p>
    </div>
</x-dynamic-component>
