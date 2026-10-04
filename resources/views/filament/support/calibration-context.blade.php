<div class="space-y-4" data-testid="calibration-context">
    <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
        <div><dt class="text-gray-500">{{ __('Customer') }}</dt><dd class="mt-1 font-medium">{{ $record->customer->company_name ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">{{ __('Product') }}</dt><dd class="mt-1">{{ $record->productVariant?->product?->name ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">{{ __('Serial') }}</dt><dd class="mt-1">{{ $record->serializedInventoryUnit?->serial_number ?? $record->serial_number ?? '—' }}</dd></div>
        <div><dt class="text-gray-500">{{ __('Certificate') }}</dt><dd class="mt-1">{{ $record->calibration?->certificate_number ?? '—' }}</dd></div>
    </dl>
    @include('filament.support.calibration-progress', ['progress' => $progress])
    @include('filament.support.calibration-measurements', ['calibration' => $record->calibration])
    <a class="text-sm text-primary-600" href="{{ $url }}">{{ __('Open calibration workspace') }}</a>
</div>
