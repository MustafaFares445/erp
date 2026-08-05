<section
    class="customer-delivery-map-panel"
    aria-labelledby="delivery-customer-map-heading"
>
    <div class="customer-delivery-map-panel__summary">
        <span class="customer-delivery-map-panel__icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25s-7.5-4.108-7.5-11.25a7.5 7.5 0 1 1 15 0Z" />
            </svg>
        </span>
        <div class="customer-delivery-map-panel__details">
            <h2 id="delivery-customer-map-heading">
                Customer delivery location
            </h2>
            @if($customerName)
                <p class="customer-delivery-map-panel__customer">{{ $customerName }}</p>
                @if($location)
                    <p class="customer-delivery-map-panel__location">{{ $location }}</p>
                @endif
            @else
                <p class="customer-delivery-map-panel__empty">Select a customer in Delivery Information to view their location.</p>
            @endif
        </div>
    </div>

    @if($latitude !== null && $longitude !== null)
        <div
            x-load
            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('customer-delivery-map') }}"
            x-data="customerDeliveryMap({
                customerName: @js($customerName),
                latitude: @js($latitude),
                longitude: @js($longitude),
                warehouses: @js($warehouses),
                warehouseOptions: @js($warehouseOptions),
                routingServiceUrl: @js($routingServiceUrl),
            })"
            class="customer-delivery-map-panel__map-wrapper"
        >
            <div
                x-ref="map"
                wire:ignore
                class="customer-delivery-map"
            ></div>
        </div>
    @elseif($customerName)
        <p class="customer-delivery-map-panel__missing-coordinates">
            This customer does not have delivery coordinates.
        </p>
    @endif
</section>
