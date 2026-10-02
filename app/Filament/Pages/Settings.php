<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\Settings\SettingsRegistry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

final class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'filament.pages.settings';

    public string $search = '';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.settings');
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.settings');
    }

    /** @return list<array{group:string,label:string,description:string,keywords:string,url:string,icon:Heroicon}> */
    public function cards(): array
    {
        $items = SettingsRegistry::accessible();
        $needle = Str::of($this->search)->lower()->squish()->toString();

        if ($needle === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (array $item): bool => str_contains(
                Str::of($item['group'].' '.$item['label'].' '.$item['description'].' '.$item['keywords'])->lower()->squish()->toString(),
                $needle,
            ),
        ));
    }
}
