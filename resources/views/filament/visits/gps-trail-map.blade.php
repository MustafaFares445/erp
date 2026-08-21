<div>
    @if(empty($points) && ! $customerLocation)
        <p class="visit-gps-trail-map__empty">No GPS records for this visit.</p>
    @else
        <div
            x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('visit-gps-trail-map') }}"
            x-data="visitGpsTrailMap({ points: @js($points), customerLocation: @js($customerLocation) })"
        >
            <div x-ref="map" wire:ignore class="visit-gps-trail-map"></div>
        </div>
    @endif

    <style>
        .visit-gps-trail-map {
            position: relative;
            z-index: 0;
            height: 20rem;
            width: 100%;
            overflow: hidden;
            border: 1px solid rgb(229 231 235);
            border-radius: 0.75rem;
        }

        .dark .visit-gps-trail-map {
            border-color: rgb(255 255 255 / 10%);
        }

        .visit-gps-trail-map__customer-icon {
            font-size: 1.25rem;
            line-height: 1;
            text-align: center;
        }

        .visit-gps-trail-map__empty {
            font-size: 0.875rem;
            color: rgb(107 114 128);
        }

        .dark .visit-gps-trail-map__empty {
            color: rgb(156 163 175);
        }
    </style>
</div>
