<div class="space-y-4" data-testid="installation-context">
    <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
        <div><dt class="text-gray-500">{{ __('Customer') }}</dt><dd class="mt-1 font-medium">{{ $record->customer->company_name ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">{{ __('Product') }}</dt><dd class="mt-1">{{ $record->productVariant?->product?->name ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">{{ __('Serial') }}</dt><dd class="mt-1">{{ $record->serializedInventoryUnit?->serial_number ?? $record->serial_number ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">{{ __('Shipment') }}</dt><dd class="mt-1">{{ $record->installation?->shipment?->tracking_number ?? '—' }}</dd></div>
    </dl>
    @include('filament.support.installation-progress', ['progress' => $progress])
    <a class="text-sm text-primary-600" href="{{ $url }}">{{ __('Open installation workspace') }}</a>
</div>
