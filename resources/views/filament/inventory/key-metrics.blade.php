@php
    /** @var list<array{label: string, value: string, helper: string|null, tone: string|null, help: string|null}> $metrics */
@endphp

<dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
    @foreach ($metrics as $metric)
        <div class="ierp-tile ierp-metric" @if ($metric['tone'] !== null) data-tone="{{ $metric['tone'] }}" @endif>
            <dt class="ierp-metric-label flex items-center gap-1.5">
                <span>{{ $metric['label'] }}</span>
                <x-inventory.term-help :text="$metric['help'] ?? $metric['helper'] ?? __('inventory_view_explanations.metrics_default')" />
            </dt>
            <dd class="ierp-metric-value text-xl"><bdi>{{ $metric['value'] }}</bdi></dd>
            @if ($metric['helper'] !== null)
                <dd class="ierp-metric-helper">{{ $metric['helper'] }}</dd>
            @endif
        </div>
    @endforeach
</dl>
