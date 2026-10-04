@php
    use Illuminate\Support\Arr;

    /** @var list<array{key: string, label: string, icon: \Filament\Support\Icons\Heroicon, sort: int, items: array}> $groups */
    $activeGroup = collect($groups)->firstWhere('key', $activeKey) ?? Arr::first($groups);

    // Resolved once: both the wide tab strip and the compact dropdown below render every group.
    $urls = collect($groups)->mapWithKeys(
        static fn (array $group): array => [$group['key'] => \App\Filament\AdminModuleRegistry::firstUrlFor($group)],
    );
@endphp

{{--
    Wide screens have room for every module as its own tab. Below 90rem (1440px) there isn't room
    for 9 tabs next to the logo, search and user menu, so we collapse the switcher into a single
    dropdown trigger (the same `<x-filament::dropdown>` Filament itself uses for grouped topbar
    navigation) instead of forcing the tab strip into a horizontally scrollable row. Which of the
    two lists is visible is decided in resources/css/filament/admin/shell.css.
--}}
<ul class="fi-topbar-nav-groups app-module-switcher">
    @foreach ($groups as $group)
        <x-filament-panels::topbar.item
            :active="$activeKey === $group['key']"
            :icon="$group['icon']"
            :url="$urls[$group['key']]"
        >
            {{ __($group['label']) }}
        </x-filament-panels::topbar.item>
    @endforeach
</ul>

<ul class="fi-topbar-nav-groups app-module-switcher-compact">
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
                    :href="$urls[$group['key']]"
                >
                    {{ __($group['label']) }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</ul>
