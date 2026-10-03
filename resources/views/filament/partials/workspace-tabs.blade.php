@php
    use App\Filament\Support\WorkspaceNavigation;

    /** @var array $item */
    /** @var array $activeTab */
    $tabs = WorkspaceNavigation::accessibleTabs($item);
    $tools = WorkspaceNavigation::accessibleTools($item);
@endphp

@if (count($tabs) > 1 || $tools !== [])
    <div class="ierp-workspace-tabs flex flex-wrap items-center justify-between gap-3" data-workspace="{{ $item['label'] }}">
        @if (count($tabs) > 1)
            <div class="max-w-full overflow-x-auto">
                <x-filament::tabs :label="__($item['label'])">
                    @foreach ($tabs as $tab)
                        <x-filament::tabs.item
                            :active="$tab['link'] === $activeTab['link'] && ($tab['page'] ?? null) === ($activeTab['page'] ?? null)"
                            :href="WorkspaceNavigation::tabUrl($tab)"
                            tag="a"
                        >
                            {{ __($tab['label']) }}
                        </x-filament::tabs.item>
                    @endforeach
                </x-filament::tabs>
            </div>
        @endif

        @if ($tools !== [])
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($tools as $tool)
                    <x-filament::button
                        color="gray"
                        size="sm"
                        outlined
                        tag="a"
                        :href="WorkspaceNavigation::toolUrl($tool)"
                        :icon="$tool['icon']"
                    >
                        {{ __($tool['label']) }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif
    </div>
@endif
