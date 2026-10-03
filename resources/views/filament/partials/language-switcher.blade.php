@php
    $current = app()->getLocale();
    $target = $current === 'ar' ? 'en' : 'ar';
@endphp

<a
    href="{{ route('admin.locale.switch', $target) }}"
    class="fi-icon-btn fi-size-md app-language-switcher"
    title="{{ $target === 'ar' ? 'العربية' : 'English' }}"
    style="display:inline-flex;align-items:center;padding:0 .5rem;font-weight:600;font-size:.875rem"
>
    {{ $target === 'ar' ? 'عربي' : 'EN' }}
</a>
