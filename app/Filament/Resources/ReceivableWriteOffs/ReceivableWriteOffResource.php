<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReceivableWriteOffs;

use App\Filament\Resources\ReceivableWriteOffs\Pages\CreateReceivableWriteOff;
use App\Filament\Resources\ReceivableWriteOffs\Pages\ListReceivableWriteOffs;
use App\Filament\Resources\ReceivableWriteOffs\Pages\ViewReceivableWriteOff;
use App\Filament\Resources\ReceivableWriteOffs\Schemas\ReceivableWriteOffForm;
use App\Filament\Resources\ReceivableWriteOffs\Schemas\ReceivableWriteOffInfolist;
use App\Filament\Resources\ReceivableWriteOffs\Tables\ReceivableWriteOffsTable;
use App\Models\ReceivableWriteOff;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class ReceivableWriteOffResource extends Resource
{
    protected static ?string $model = ReceivableWriteOff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    protected static ?int $navigationSort = 210;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.receivable_write_offs');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return ReceivableWriteOffForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return ReceivableWriteOffInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ReceivableWriteOffsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListReceivableWriteOffs::route('/'),
            'create' => CreateReceivableWriteOff::route('/create'),
            'view' => ViewReceivableWriteOff::route('/{record}'),
        ];
    }
}
