<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('operational-route-map') }}"
    x-data="operationalRouteMap({
        source: @js($source),
        destination: @js($destination),
        routingServiceUrl: @js($routingServiceUrl),
    })"
    class="space-y-3"
>
    @if($source === null && $destination === null)
        <p class="text-sm text-gray-500">{{ __('Map unavailable: neither location has valid coordinates.') }}</p>
    @else
        <div x-ref="map" wire:ignore class="relative z-0 h-80 w-full overflow-hidden rounded-xl border border-gray-200"></div>
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <div class="text-gray-600 dark:text-gray-300" aria-live="polite">
                <span x-show="state === 'loading'">{{ __('Loading route…') }}</span>
                <span x-show="state === 'ready'" x-text="details || 'Road route available'"></span>
                <span x-show="state === 'partial'">{{ __('Only one mapped location is available. Road route cannot be calculated.') }}</span>
                <span x-show="state === 'unavailable'">{{ __('Road route unavailable. Markers show recorded locations, not a driving path.') }}</span>
            </div>
            <a x-show="directionsUrl" :href="directionsUrl" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline">
                {{ __('Open directions') }} ↗
            </a>
        </div>
    @endif
</div>
