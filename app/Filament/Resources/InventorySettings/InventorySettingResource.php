<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventorySettings;

use App\Filament\Resources\InventorySettings\Pages\ManageInventorySettings;
use App\Models\InventorySetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class InventorySettingResource extends Resource
{
    protected static ?string $model = InventorySetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    #[\Override]
    public static function canCreate(): bool
    {
        return parent::canCreate() && InventorySetting::query()->doesntExist();
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('default_markup_percent')->numeric()->minValue(0)->maxValue(100)->step(0.01)->required(),
            TextInput::make('expiry_alert_days')->integer()->minValue(1)->maxValue(365)->required(),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('default_markup_percent')->suffix('%'),
            TextColumn::make('expiry_alert_days')->suffix(' days'),
        ])->recordActions([EditAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventorySettings::route('/')];
    }
}
