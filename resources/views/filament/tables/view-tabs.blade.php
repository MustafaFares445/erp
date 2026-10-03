@php
    /** @var \Filament\Resources\Pages\ListRecords $page (uses \App\Filament\Concerns\HasTableViewTabs) */
    $activeSavedView = $page->activeSavedTableView;
@endphp

<div class="ierp-table-view-tabs">
    <x-filament::tabs :label="__('Table views')" class="ierp-table-view-tabs-list">
        @foreach ($page->getCachedTabs() as $key => $tab)
            <x-filament::tabs.item
                :active="$activeSavedView === null && (string) $page->activeTab === (string) $key"
                :badge="$tab->getBadge()"
                :badge-color="$tab->getBadgeColor()"
                :icon="$tab->getIcon()"
                :icon-position="$tab->getIconPosition()"
                wire:click="selectTableView('preset', {{ \Illuminate\Support\Js::from((string) $key) }})"
                wire:key="table-view-preset-{{ $key }}"
            >
                {{ $tab->getLabel() }}
            </x-filament::tabs.item>
        @endforeach

        @foreach ($page->getTabBarSavedTableViews() as $savedView)
            <x-filament::tabs.item
                :active="$activeSavedView === $savedView->id"
                :icon="$savedView->icon ?: \Filament\Support\Icons\Heroicon::OutlinedBookmark"
                wire:click="selectTableView('saved', '{{ $savedView->id }}')"
                wire:key="table-view-saved-{{ $savedView->id }}"
            >
                {{ $savedView->name }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="ierp-table-view-tabs-menu">
        {{ $page->getTableViewMenu() }}
    </div>
</div>
