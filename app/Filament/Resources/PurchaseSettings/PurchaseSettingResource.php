<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseSettings;

use App\Filament\Resources\PurchaseSettings\Pages\ManagePurchaseSettings;
use App\Models\PurchaseSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The approval threshold, mirroring the `InventorySettings` singleton shape.
 *
 * Reachable by System Admin alone. A Purchasing Manager who could raise the
 * threshold could approve their own spending by moving the line rather than by
 * breaking a rule, which would make the separation of duties decorative.
 *
 * @see /specs/017-purchasing-orders-suppliers/data-model.md §5
 */
final class PurchaseSettingResource extends Resource
{
    protected static ?string $model = PurchaseSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.purchasing';

    protected static ?int $navigationSort = 104;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.purchase_settings');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.purchase_settings');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return parent::canCreate() && PurchaseSetting::query()->doesntExist();
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('approval_threshold_amount')
                ->label(__('admin.purchasing.fields.approval_threshold_amount'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->required()
                ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.purchasing.hints.approval_threshold')),
            TextInput::make('approval_threshold_currency')
                ->label(__('admin.purchasing.fields.approval_threshold_currency'))
                ->required()
                ->length(3)
                ->default('AED')
                ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.purchasing.hints.threshold_currency')),
        ])->columns(2);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('approval_threshold_amount')
                ->label(__('admin.purchasing.fields.approval_threshold_amount'))
                ->numeric(decimalPlaces: 2),
            TextColumn::make('approval_threshold_currency')
                ->label(__('admin.purchasing.fields.approval_threshold_currency')),
        ])->recordActions([EditAction::make()]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManagePurchaseSettings::route('/')];
    }
}
