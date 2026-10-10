@php
    /** @var \App\Data\Inventory\InventoryDiagnosis $diagnosis */
    $overall = $diagnosis->severity();
    $attention = array_values(array_filter($diagnosis->findings, static fn ($finding): bool => in_array($finding->severity->value, ['blocker', 'warning'], true)));
    $updates = array_values(array_filter($diagnosis->findings, static fn ($finding): bool => $finding->severity->value === 'notice'));
@endphp

<div class="grid gap-3" data-inventory-health="{{ $overall->value }}">
    <div class="flex flex-wrap items-center gap-2">
        <span class="ierp-status" data-tone="{{ $overall->tone() }}">{{ $overall->label() }}</span>
        @if ($attention !== [])
            <span class="text-sm font-medium">{{ $diagnosis->summary() }}</span>
        @elseif ($updates !== [])
            <span class="text-sm font-medium">{{ trans_choice('inventory_view_explanations.workflow_summary', count($updates), ['count' => count($updates)]) }}</span>
        @else
            <span class="text-sm font-medium">{{ $diagnosis->summary() }}</span>
        @endif
        <x-inventory.term-help :text="__('inventory_view_ux.description')" />
    </div>

    @if ($attention !== [])
        <ul class="grid gap-2" role="list" data-inventory-attention>
            @foreach ($attention as $finding)
                <x-inventory.diagnostic-finding :finding="$finding" />
            @endforeach
        </ul>
    @endif

    @if ($updates !== [])
        <details class="ierp-tile text-sm" data-variant="subtle" data-inventory-workflow-updates>
            <summary class="cursor-pointer font-medium">{{ trans_choice('inventory_view_explanations.workflow_details', count($updates), ['count' => count($updates)]) }}</summary>
            <ul class="mt-3 grid gap-2" role="list">
                @foreach ($updates as $finding)
                    <x-inventory.diagnostic-finding :finding="$finding" compact />
                @endforeach
            </ul>
        </details>
    @endif

    @if ($diagnosis->checks !== [] || $diagnosis->limits !== [])
        <details class="ierp-tile text-sm" data-variant="subtle" data-inventory-checks>
            <summary class="cursor-pointer font-medium">{{ __('inventory_view_explanations.checks_details') }}</summary>
            <x-inventory.term-help :text="__('inventory_view_ux.scope_toggle', ['count' => count($diagnosis->checks)])" />
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                @if ($diagnosis->checks !== [])
                    <div>
                        <div class="ierp-eyebrow">{{ __('inventory_view_ux.checks_heading') }}</div>
                        <ul class="mt-1 list-disc space-y-1 ps-5 text-xs">
                            @foreach ($diagnosis->checks as $check)
                                <li>{{ $check }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($diagnosis->limits !== [])
                    <div>
                        <div class="ierp-eyebrow">{{ __('inventory_view_ux.limits_heading') }}</div>
                        <ul class="mt-1 list-disc space-y-1 ps-5 text-xs">
                            @foreach ($diagnosis->limits as $limit)
                                <li>{{ $limit }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </details>
    @endif
</div>
