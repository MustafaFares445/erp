@php
    $current = app()->getLocale();
    $target = $current === 'ar' ? 'en' : 'ar';
@endphp

<a
    href="{{ route('admin.locale.switch', $target) }}"
    class="app-language-switcher"
    title="{{ $target === 'ar' ? 'العربية' : 'English' }}"
    lang="{{ $target }}"
>
    {{ $target === 'ar' ? 'عربي' : 'EN' }}
</a>
