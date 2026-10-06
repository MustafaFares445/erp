<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventorySettings;

use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\InventorySettings\Pages\ManageInventorySettings;
use App\Models\InventorySetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class InventorySettingResource extends Resource
{
    protected static ?string $model = InventorySetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    #[\Override]
    public static function canCreate(): bool
    {
        return parent::canCreate() && InventorySetting::query()->doesntExist();
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('default_markup_percent')->numeric()->minValue(0)->maxValue(100)->step(0.01)->required()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'This percentage is used as the starting markup when a variant does not have a specific pricing rule.'),
            TextInput::make('expiry_critical_days')->label(__('Critical expiry window'))->integer()->minValue(1)->maxValue(365)->default(30)->required()
                ->suffix(__(' days'))
                ->hintIcon(Heroicon::QuestionMarkCircle, 'The first actionable near-expiry bucket, normally 30 days.'),
            TextInput::make('expiry_warning_days')->label(__('Warning expiry window'))->integer()->minValue(1)->maxValue(365)->default(60)->required()
                ->suffix(__(' days'))
                ->hintIcon(Heroicon::QuestionMarkCircle, 'The second near-expiry bucket. It cannot be shorter than the critical window.'),
            TextInput::make('expiry_notice_days')->label(__('Notice expiry window'))->integer()->minValue(1)->maxValue(365)->default(90)->required()
                ->suffix(__(' days'))
                ->hintIcon(Heroicon::QuestionMarkCircle, 'The earliest horizon at which the ERP starts surfacing near-expiry stock.'),
            TextInput::make('max_price_floor_override_percent')->numeric()->minValue(0)->maxValue(100)->step(0.01)
                ->hintIcon(Heroicon::QuestionMarkCircle, "The furthest below a variant's price floor an approver may go, as a percentage of the floor. Leave empty for no ceiling."),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('default_markup_percent')->suffix('%'),
            TextColumn::make('expiry_critical_days')->label(__('Critical'))->suffix(__(' days')),
            TextColumn::make('expiry_warning_days')->label(__('Warning'))->suffix(__(' days')),
            TextColumn::make('expiry_notice_days')->label(__('Notice'))->suffix(__(' days')),
            TextColumn::make('max_price_floor_override_percent')->suffix('%')->placeholder(__('No ceiling')),
        ])->recordActions([EditAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventorySettings::route('/')];
    }
}
