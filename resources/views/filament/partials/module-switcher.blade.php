@php
    /** @var list<array{key: string, label: string, icon: \Filament\Support\Icons\Heroicon, sort: int, items: array}> $groups */
@endphp

<ul class="fi-topbar-nav-groups">
    @foreach ($groups as $group)
        <x-filament-panels::topbar.item
            :active="$activeKey === $group['key']"
            :icon="$group['icon']"
            :url="\App\Filament\AdminModuleRegistry::firstUrlFor($group)"
        >
            {{ __($group['label']) }}
        </x-filament-panels::topbar.item>
    @endforeach
</ul>
