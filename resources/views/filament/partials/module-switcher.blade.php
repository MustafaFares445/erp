@php
    use Illuminate\Support\Arr;

    /** @var list<array{key: string, label: string, icon: \Filament\Support\Icons\Heroicon, sort: int, items: array}> $groups */
    $activeGroup = collect($groups)->firstWhere('key', $activeKey) ?? Arr::first($groups);
@endphp

{{--
    Wide screens have room for every module as its own tab. Below `lg` there isn't room for 7+
    tabs next to the sidebar toggle, search and user menu, so we collapse the switcher into a
    single dropdown trigger (the same `<x-filament::dropdown>` Filament itself uses for grouped
    topbar navigation) instead of forcing the tab strip into a horizontally scrollable row.
--}}
<ul class="fi-topbar-nav-groups app-module-switcher hidden lg:flex">
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

<ul class="fi-topbar-nav-groups app-module-switcher-compact flex lg:hidden">
    <x-filament::dropdown placement="bottom-start" teleport>
        <x-slot name="trigger">
            <x-filament-panels::topbar.item :active="true" :icon="$activeGroup['icon'] ?? null">
                {{ $activeGroup ? __($activeGroup['label']) : '' }}
            </x-filament-panels::topbar.item>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($groups as $group)
                <x-filament::dropdown.list.item
                    :icon="$group['icon']"
                    :color="$activeKey === $group['key'] ? 'primary' : 'gray'"
                    tag="a"
                    :href="\App\Filament\AdminModuleRegistry::firstUrlFor($group)"
                >
                    {{ __($group['label']) }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</ul>
