@php
    $config = $getConfig();
    $sections = collect($config['sections']);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('notification-message-editor') }}"
        x-data="notificationMessageEditor({ state: $wire.$entangle(@js($getStatePath())), config: @js($config) })"
        class="grid grid-cols-1 gap-6 lg:grid-cols-5 lg:items-start xl:grid-cols-12"
    >
        <div class="flex flex-col gap-4 lg:col-span-3 xl:col-span-7">
            @if (count($config['languages']) > 1)
                <x-filament::tabs>
                    @foreach ($config['languages'] as $language)
                        <x-filament::tabs.item
                            alpine-active="language === '{{ $language['locale'] }}'"
                            x-on:click="language = '{{ $language['locale'] }}'"
                        >
                            {{ $language['label'] }}
                        </x-filament::tabs.item>
                    @endforeach
                </x-filament::tabs>
            @endif

            <x-filament::section :heading="$config['texts']['content']">
                @foreach ($config['languages'] as $language)
                    <div
                        x-show="language === @js($language['locale'])"
                        x-cloak
                        class="flex flex-col gap-6"
                    >
                        @foreach ($sections->where('locale', $language['locale']) as $section)
                            <div class="flex flex-col gap-4">
                                @if ($sections->where('locale', $language['locale'])->count() > 1)
                                    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $section['heading'] }}</p>
                                @endif

                                @foreach (['subject' => $section['subjectLabel'], 'body' => $section['messageLabel']] as $field => $label)
                                    <div class="flex flex-col gap-1">
                                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $label }}</span>

                                        <x-filament::input.wrapper>
                                            <div
                                                wire:ignore
                                                role="textbox"
                                                contenteditable="true"
                                                dir="{{ $language['rtl'] ? 'rtl' : 'ltr' }}"
                                                data-editor-key="{{ $section['key'] }}"
                                                data-editor-field="{{ $field }}"
                                                data-editor-locale="{{ $language['locale'] }}"
                                                data-multiline="{{ $field === 'body' ? '1' : '0' }}"
                                                aria-label="{{ $label }}"
                                                x-init="mountEditor($el)"
                                                class="nm-editor {{ $field === 'body' ? 'nm-editor--multiline' : '' }}"
                                            ></div>
                                        </x-filament::input.wrapper>

                                        <div>
                                            <x-filament::dropdown placement="bottom-start">
                                                <x-slot name="trigger">
                                                    <x-filament::link tag="button" type="button" size="sm" icon="heroicon-m-plus">
                                                        {{ $config['texts']['insert'] }}
                                                    </x-filament::link>
                                                </x-slot>

                                                <x-filament::dropdown.list>
                                                    @foreach ($config['information'][$language['locale']] as $item)
                                                        <x-filament::dropdown.list.item
                                                            x-on:click="insertToken('{{ $section['key'] }}', '{{ $field }}', '{{ $item['name'] }}'); close()"
                                                        >
                                                            {{ $item['label'] }}
                                                        </x-filament::dropdown.list.item>
                                                    @endforeach
                                                </x-filament::dropdown.list>
                                            </x-filament::dropdown>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </x-filament::section>
        </div>

        <div class="lg:col-span-2 lg:sticky lg:top-24 xl:col-span-5">
            <x-filament::section :heading="$config['texts']['preview']">
                <div class="flex flex-col gap-4">
                    @if (count($config['formats']) > 1)
                        <div class="flex flex-wrap gap-2">
                            @foreach ($config['formats'] as $format)
                                <button
                                    type="button"
                                    x-on:click="previewFormat = @js($format['channel'])"
                                    x-bind:class="previewFormat === @js($format['channel'])
                                        ? 'bg-gray-100 text-primary-600 dark:bg-white/10 dark:text-primary-400'
                                        : 'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5'"
                                    class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-gray-950/10 transition dark:ring-white/10"
                                >
                                    {{ $format['label'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($sections as $section)
                        <div
                            x-show="language === @js($section['locale']) && previewFormat === @js($section['channel'])"
                            x-cloak
                            dir="{{ $section['rtl'] ? 'rtl' : 'ltr' }}"
                        >
                            @if ($section['channel'] === 'mail')
                                <div class="overflow-hidden rounded-lg bg-white ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/10">
                                    <div class="flex items-center gap-2 border-b border-gray-950/5 px-4 py-2 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                                        <x-filament::icon icon="heroicon-o-envelope" class="h-4 w-4" />
                                        <span>{{ $config['texts']['email_hint'] }}</span>
                                    </div>
                                    <div class="flex flex-col gap-3 p-4">
                                        <p class="break-words text-base font-semibold text-gray-950 dark:text-white" x-text="preview(@js($section['key']), 'subject')"></p>
                                        <p class="whitespace-pre-line break-words text-sm text-gray-700 dark:text-gray-300" x-text="preview(@js($section['key']), 'body')"></p>
                                    </div>
                                </div>
                            @else
                                <div class="flex items-start gap-3 rounded-lg bg-white p-4 ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/10">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                        <x-filament::icon icon="heroicon-o-bell" class="h-4 w-4" />
                                    </span>
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <p class="break-words text-sm font-semibold text-gray-950 dark:text-white" x-text="preview(@js($section['key']), 'subject')"></p>
                                        <p class="whitespace-pre-line break-words text-sm text-gray-600 dark:text-gray-400" x-text="preview(@js($section['key']), 'body')"></p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $config['texts']['just_now'] }}</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $config['texts']['preview_hint'] }}</p>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-dynamic-component>
