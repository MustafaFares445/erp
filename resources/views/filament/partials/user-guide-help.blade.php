@props(['url'])

<x-filament::button
    tag="a"
    :href="$url"
    target="_blank"
    rel="noopener noreferrer"
    color="gray"
    size="sm"
    icon="heroicon-o-book-open"
>
    {{ __('User Guide') }}
</x-filament::button>
