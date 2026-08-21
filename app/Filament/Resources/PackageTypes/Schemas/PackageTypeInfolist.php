<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageTypes\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class PackageTypeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label(__('admin.package.type.fields.name')),
            TextEntry::make('code')->label(__('admin.package.type.fields.code')),
            IconEntry::make('is_active')->label(__('admin.package.type.fields.is_active'))->boolean(),
        ]);
    }
}
