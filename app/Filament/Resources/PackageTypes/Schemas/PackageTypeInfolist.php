<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageTypes\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class PackageTypeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('inventory_view_explanations.package_type_title'))
                ->description(__('inventory_view_explanations.package_type_desc'))
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label(__('admin.package.type.fields.name')),
                    TextEntry::make('code')->label(__('admin.package.type.fields.code'))
                        ->hintIcon(Heroicon::QuestionMarkCircle, __('inventory_view_explanations.package_type_code')),
                    IconEntry::make('is_active')->label(__('admin.package.type.fields.is_active'))
                        ->hintIcon(Heroicon::QuestionMarkCircle, __('inventory_view_explanations.package_type_active'))
                        ->boolean(),
                ]),
        ]);
    }
}
