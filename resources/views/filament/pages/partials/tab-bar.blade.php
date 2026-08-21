@php
    /** @var array<string, array{label: string, icon: \Filament\Support\Icons\Heroicon}> $tabs */
    /** @var string $active */
@endphp

<x-filament::tabs :label="$this->getTitle()">
    @foreach ($tabs as $key => $tab)
        <x-filament::tabs.item
            :active="$active === $key"
            :icon="$tab['icon']"
            wire:click="setTab('{{ $key }}')"
        >
            {{ $tab['label'] }}
        </x-filament::tabs.item>
    @endforeach
</x-filament::tabs>
