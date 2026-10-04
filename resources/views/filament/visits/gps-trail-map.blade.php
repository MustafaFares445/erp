<div>
    @if(empty($points) && ! $customerLocation)
        <div class="ierp-empty">No GPS records for this visit.</div>
    @else
        <div
            x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('visit-gps-trail-map') }}"
            x-data="visitGpsTrailMap({ points: @js($points), customerLocation: @js($customerLocation) })"
        >
            <div x-ref="map" wire:ignore class="visit-gps-trail-map relative z-0 h-80 w-full overflow-hidden rounded-xl border border-gray-200 dark:border-white/10"></div>
        </div>
    @endif

    <style>
        .visit-gps-trail-map__customer-icon {
            font-size: 1.25rem;
            line-height: 1;
            text-align: center;
        }
    </style>
</div>
