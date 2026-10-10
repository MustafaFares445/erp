@props(['text'])

<details class="ierp-term-help" data-term-help>
    <summary aria-label="{{ __('inventory_view_explanations.help_label') }}" title="{{ __('inventory_view_explanations.help_label') }}">?</summary>
    <span class="ierp-term-help-content" role="note">{{ $text }}</span>
</details>
