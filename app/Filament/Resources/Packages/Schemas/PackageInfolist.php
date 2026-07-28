<?php

declare(strict_types=1);

namespace App\Filament\Resources\Packages\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class PackageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label(__('admin.package.fields.name')),
            TextEntry::make('packageType.name')->label(__('admin.package.fields.package_type')),
            TextEntry::make('warehouse.name')->label(__('admin.package.fields.warehouse')),
            TextEntry::make('location.name')->label(__('admin.package.fields.location')),
            IconEntry::make('is_active')->label(__('admin.package.fields.is_active'))->boolean(),
        ]);
    }
}
