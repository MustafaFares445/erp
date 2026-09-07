<div class="space-y-4">
    <div class="grid grid-cols-3 gap-3 text-sm">
        <div class="rounded-lg border p-3">
            <div class="text-gray-500">{{ __('admin.inventory.stock.on_hand_quantity') }}</div>
            <div class="text-lg font-semibold">{{ number_format($explanation['on_hand'], 3) }}</div>
        </div>
        <div class="rounded-lg border p-3">
            <div class="text-gray-500">{{ __('admin.inventory.stock.available_quantity') }}</div>
            <div class="text-lg font-semibold">{{ number_format($explanation['available'], 3) }}</div>
        </div>
        <div class="rounded-lg border p-3">
            <div class="text-gray-500">{{ __('admin.inventory.stock.availability_breakdown_gap') }}</div>
            <div class="text-lg font-semibold">{{ number_format($explanation['gap'], 3) }}</div>
        </div>
    </div>

    @if (empty($explanation['causes']))
        <p class="text-sm text-gray-500">{{ __('admin.inventory.stock.availability_breakdown_empty') }}</p>
    @else
        <div class="space-y-3">
            @foreach ($explanation['causes'] as $cause)
                <div class="rounded-lg border p-3">
                    <div class="flex items-center justify-between">
                        <div class="font-medium">{{ $cause['label'] }}</div>
                        <div class="font-semibold">{{ number_format($cause['quantity'], 3) }}</div>
                    </div>

                    @if (empty($cause['documents']))
                        <p class="mt-1 text-sm text-gray-500">{{ __('admin.inventory.stock.availability_breakdown_no_documents') }}</p>
                    @else
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($cause['documents'] as $document)
                                <li class="flex items-center justify-between">
                                    <span>
                                        @if ($document['url'])
                                            <a href="{{ $document['url'] }}" class="text-primary-600 underline">{{ $document['label'] }}</a>
                                        @else
                                            {{ $document['label'] }}
                                        @endif
                                        @if (! empty($document['holder']))
                                            <span class="text-gray-500">— {{ $document['holder'] }}</span>
                                        @endif
                                        @if (! empty($document['expires_at']))
                                            <span class="text-gray-500">({{ $document['expires_at'] }})</span>
                                        @endif
                                    </span>
                                    @if ($document['quantity'] !== null)
                                        <span>{{ number_format($document['quantity'], 3) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($cause['action'])
                        <a href="{{ $cause['action']['url'] }}" class="mt-2 inline-block text-sm text-primary-600 underline">
                            {{ $cause['action']['label'] }}
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if (($explanation['in_transit']['quantity'] ?? 0) > 0)
        <div class="rounded-lg border p-3">
            <div class="flex items-center justify-between">
                <div class="font-medium">{{ __('admin.inventory.stock.availability_breakdown_in_transit') }}</div>
                <div class="font-semibold">{{ number_format($explanation['in_transit']['quantity'], 3) }}</div>
            </div>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($explanation['in_transit']['operations'] as $operation)
                    <li class="flex items-center justify-between">
                        @if ($operation['url'])
                            <a href="{{ $operation['url'] }}" class="text-primary-600 underline">{{ $operation['label'] }}</a>
                        @else
                            <span>{{ $operation['label'] }}</span>
                        @endif
                        <span>{{ number_format($operation['quantity'], 3) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (($explanation['expired']['quantity'] ?? 0) > 0)
        <div class="rounded-lg border p-3">
            <div class="flex items-center justify-between">
                <div class="font-medium">{{ __('admin.inventory.stock.availability_breakdown_expired') }}</div>
                <div class="font-semibold">{{ number_format($explanation['expired']['quantity'], 3) }}</div>
            </div>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($explanation['expired']['lots'] as $lot)
                    <li class="flex items-center justify-between">
                        <span>
                            @if ($lot['url'])
                                <a href="{{ $lot['url'] }}" class="text-primary-600 underline">{{ $lot['label'] }}</a>
                            @else
                                {{ $lot['label'] }}
                            @endif
                            @if (! empty($lot['expires_at']))
                                <span class="text-gray-500">({{ $lot['expires_at'] }})</span>
                            @endif
                        </span>
                        <span>{{ number_format($lot['quantity'], 3) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
