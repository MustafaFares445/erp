<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges;

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\QuarantineDisposition;
use App\Filament\Resources\InventoryConditionChanges\Pages\CreateInventoryConditionChange;
use App\Filament\Resources\InventoryConditionChanges\Pages\ListInventoryConditionChanges;
use App\Filament\Resources\InventoryConditionChanges\Pages\ViewInventoryConditionChange;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLot;
use App\Models\SerializedInventoryUnit;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class InventoryConditionChangeResource extends Resource
{
    protected static ?string $model = InventoryConditionChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 306;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.inventory_condition_changes');
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'productVariant:id,sku,name',
            'warehouse:id,code,name',
            'lot:id,lot_number',
            'serializedUnit:id,serial_number',
            'inspector:id,name',
            'postedBy:id,name',
            'createdBy:id,name',
        ]);
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quarantine disposition')
                ->description('Move quarantined stock through the canonical inventory ledger.')
                ->columns(2)
                ->schema([
                    Select::make('product_variant_id')
                        ->label('Product variant')
                        ->relationship('productVariant', 'name')
                        ->searchable(['name', 'sku'])
                        ->preload()
                        ->default(fn (): ?int => request()->integer('product_variant_id') ?: null)
                        ->required(),
                    Select::make('warehouse_id')
                        ->label('Warehouse')
                        ->relationship('warehouse', 'name')
                        ->searchable()
                        ->preload()
                        ->default(fn (): ?int => request()->integer('warehouse_id') ?: null)
                        ->required(),
                    Select::make('inventory_lot_id')
                        ->label('Lot')
                        ->options(fn (): array => InventoryLot::query()
                            ->canonical()
                            ->orderBy('lot_number')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn (InventoryLot $lot): array => [
                                (int) $lot->getKey() => $lot->lot_number ?? 'Lot #'.$lot->getKey(),
                            ])
                            ->all())
                        ->searchable()
                        ->preload(),
                    Select::make('serialized_inventory_unit_id')
                        ->label('Serialized unit')
                        ->options(fn (): array => SerializedInventoryUnit::query()
                            ->orderBy('serial_number')
                            ->limit(500)
                            ->pluck('serial_number', 'id')
                            ->all())
                        ->searchable()
                        ->preload(),
                    TextInput::make('base_quantity')
                        ->label('Base quantity')
                        ->default(fn (): ?string => request()->query('base_quantity'))
                        ->numeric()
                        ->minValue(0.000001)
                        ->required(),
                    Select::make('disposition')
                        ->options([
                            QuarantineDisposition::ReleaseToSaleable->value => 'Release to saleable',
                            QuarantineDisposition::DowngradeToDamaged->value => 'Downgrade to damaged',
                            QuarantineDisposition::Dispose->value => 'Dispose',
                            QuarantineDisposition::ReturnToSupplier->value => 'Return to supplier',
                        ])
                        ->required(),
                    Select::make('reason_category')
                        ->label('Reason category')
                        ->options(collect(ConditionChangeReason::cases())
                            ->mapWithKeys(fn (ConditionChangeReason $reason): array => [
                                $reason->value => str($reason->name)->headline()->toString(),
                            ])
                            ->all())
                        ->required(),
                    Textarea::make('reason')
                        ->required()
                        ->maxLength(2_000)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Condition change')
                ->columns(3)
                ->schema([
                    TextEntry::make('document_number')->label('Document'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('disposition')->badge(),
                    TextEntry::make('productVariant.sku')->label('SKU'),
                    TextEntry::make('productVariant.name')->label('Variant'),
                    TextEntry::make('warehouse.name')->label('Warehouse'),
                    TextEntry::make('lot.lot_number')->label('Lot')->placeholder('—'),
                    TextEntry::make('serializedUnit.serial_number')->label('Serial')->placeholder('—'),
                    TextEntry::make('base_quantity')->label('Quantity')->numeric(decimalPlaces: 6),
                    TextEntry::make('condition_from')->label('From')->badge(),
                    TextEntry::make('condition_to')->label('To')->badge(),
                    TextEntry::make('reason_category')->label('Reason category')->badge(),
                    TextEntry::make('reason')->columnSpanFull(),
                    TextEntry::make('inspector.name')->label('Inspected by')->placeholder('—'),
                    TextEntry::make('inspected_at')->dateTime()->placeholder('—'),
                    TextEntry::make('postedBy.name')->label('Posted by')->placeholder('—'),
                    TextEntry::make('posted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('inventory_movement_id')->label('Movement')->placeholder('—'),
                    TextEntry::make('supplier_return_id')->label('Supplier return')->placeholder('—'),
                ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('document_number')->label('Document')->searchable()->sortable(),
                TextColumn::make('productVariant.sku')->label('SKU')->searchable(),
                TextColumn::make('warehouse.name')->label('Warehouse')->searchable(),
                TextColumn::make('base_quantity')->label('Quantity')->numeric(decimalPlaces: 6),
                TextColumn::make('disposition')->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InventoryConditionChangeStatus::cases())
                        ->mapWithKeys(fn (InventoryConditionChangeStatus $status): array => [
                            $status->value => str($status->name)->headline()->toString(),
                        ])
                        ->all()),
                SelectFilter::make('disposition')
                    ->options(collect(QuarantineDisposition::cases())
                        ->mapWithKeys(fn (QuarantineDisposition $disposition): array => [
                            $disposition->value => str($disposition->name)->headline()->toString(),
                        ])
                        ->all()),
            ])
            ->recordUrl(fn (InventoryConditionChange $record): string => self::getUrl('view', ['record' => $record]));
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryConditionChanges::route('/'),
            'create' => CreateInventoryConditionChange::route('/create'),
            'view' => ViewInventoryConditionChange::route('/{record}'),
        ];
    }
}
