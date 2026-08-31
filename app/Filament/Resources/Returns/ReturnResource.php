<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns;

use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Filament\Resources\Returns\RelationManagers\ReturnLinesRelationManager;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ReturnResource extends Resource
{
    protected static ?string $model = InventoryReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.returns');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('return_type')
                ->label(__('admin.inventory.return.type'))
                ->options([
                    InventoryReturnType::Customer->value => __('admin.inventory.return.types.customer'),
                    InventoryReturnType::Supplier->value => __('admin.inventory.return.types.supplier'),
                ])
                ->required()
                ->live(),
            Select::make('warehouse_id')
                ->label(__('admin.inventory.return.warehouse'))
                ->options(fn (): array => Warehouse::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),
            Select::make('original_inventory_operation_id')
                ->label(__('admin.inventory.return.original_delivery'))
                ->options(fn (): array => InventoryOperation::query()
                    ->where('operation_type', OperationType::Delivery->value)
                    ->where('stage', OperationStage::Done->value)
                    ->whereNotNull('customer_id')
                    ->orderByDesc('id')
                    ->limit(200)
                    ->pluck('operation_number', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(fn (Get $get): bool => $get('return_type') === InventoryReturnType::Customer->value)
                ->visible(fn (Get $get): bool => $get('return_type') === InventoryReturnType::Customer->value),
            Select::make('supplier_id')
                ->label(__('admin.inventory.return.supplier'))
                ->options(fn (): array => Supplier::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->live()
                ->required(fn (Get $get): bool => $get('return_type') === InventoryReturnType::Supplier->value)
                ->visible(fn (Get $get): bool => $get('return_type') === InventoryReturnType::Supplier->value),
            Select::make('supplier_receipt_id')
                ->label(__('admin.inventory.return.original_receipt'))
                ->options(fn (Get $get): array => InventoryOperation::query()
                    ->where('operation_type', OperationType::Receipt->value)
                    ->where('stage', OperationStage::Done->value)
                    ->when(
                        is_numeric($get('supplier_id')),
                        fn (Builder $query): Builder => $query->where('supplier_id', (int) $get('supplier_id')),
                    )
                    ->orderByDesc('id')
                    ->limit(200)
                    ->pluck('operation_number', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => $get('return_type') === InventoryReturnType::Supplier->value),
            Select::make('original_purchase_order_id')
                ->label(__('admin.inventory.return.original_purchase_order'))
                ->options(fn (Get $get): array => PurchaseOrder::query()
                    ->when(
                        is_numeric($get('supplier_id')),
                        fn (Builder $query): Builder => $query->where('supplier_id', (int) $get('supplier_id')),
                    )
                    ->orderByDesc('id')
                    ->limit(200)
                    ->pluck('purchase_order_number', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => $get('return_type') === InventoryReturnType::Supplier->value),
            Textarea::make('reason')
                ->label(__('admin.inventory.return.reason'))
                ->maxLength(2_000)
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label(__('admin.inventory.return.notes'))
                ->maxLength(2_000)
                ->columnSpanFull(),
        ])->columns(2);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.inventory.return.details'))->columns(3)->schema([
                TextEntry::make('return_number')->label(__('admin.inventory.return.number')),
                TextEntry::make('return_type')->label(__('admin.inventory.return.type'))->badge(),
                TextEntry::make('status')->label(__('admin.inventory.return.status'))->badge(),
                TextEntry::make('warehouse.name')->label(__('admin.inventory.return.warehouse')),
                TextEntry::make('customer.customer_code')->label(__('admin.inventory.return.customer'))->placeholder('—'),
                TextEntry::make('supplier.name')->label(__('admin.inventory.return.supplier'))->placeholder('—'),
                TextEntry::make('originalOperation.operation_number')
                    ->label(__('admin.inventory.return.original_document'))
                    ->placeholder('—'),
                TextEntry::make('originalPurchaseOrder.purchase_order_number')
                    ->label(__('admin.inventory.return.original_purchase_order'))
                    ->placeholder('—'),
                TextEntry::make('createdBy.name')->label(__('admin.inventory.return.created_by'))->placeholder('—'),
                TextEntry::make('ready_at')->label(__('admin.inventory.return.ready_at'))->dateTime()->placeholder('—'),
                TextEntry::make('posted_at')->label(__('admin.inventory.return.posted_at'))->dateTime()->placeholder('—'),
                TextEntry::make('cancelled_at')->label(__('admin.inventory.return.cancelled_at'))->dateTime()->placeholder('—'),
                TextEntry::make('reason')->label(__('admin.inventory.return.reason'))->columnSpanFull()->placeholder('—'),
                TextEntry::make('notes')->label(__('admin.inventory.return.notes'))->columnSpanFull()->placeholder('—'),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('return_number')
                    ->label(__('admin.inventory.return.number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('return_type')
                    ->label(__('admin.inventory.return.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.inventory.return.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('warehouse.name')
                    ->label(__('admin.inventory.return.warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.customer_code')
                    ->label(__('admin.inventory.return.customer'))
                    ->placeholder('—'),
                TextColumn::make('supplier.name')
                    ->label(__('admin.inventory.return.supplier'))
                    ->placeholder('—'),
                TextColumn::make('originalOperation.operation_number')
                    ->label(__('admin.inventory.return.original_document'))
                    ->placeholder('—'),
                TextColumn::make('originalPurchaseOrder.purchase_order_number')
                    ->label(__('admin.inventory.return.original_purchase_order'))
                    ->placeholder('—'),
                TextColumn::make('lines_count')
                    ->label(__('admin.inventory.return.lines_count'))
                    ->counts('lines'),
                TextColumn::make('posted_at')
                    ->label(__('admin.inventory.return.posted_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('return_type')
                    ->label(__('admin.inventory.return.type'))
                    ->options([
                        InventoryReturnType::Customer->value => __('admin.inventory.return.types.customer'),
                        InventoryReturnType::Supplier->value => __('admin.inventory.return.types.supplier'),
                    ]),
                SelectFilter::make('status')
                    ->label(__('admin.inventory.return.status'))
                    ->options(collect(InventoryReturnStatus::cases())
                        ->mapWithKeys(fn (InventoryReturnStatus $status): array => [$status->value => $status->name])
                        ->all()),
                SelectFilter::make('warehouse_id')
                    ->label(__('admin.inventory.return.warehouse'))
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordUrl(fn (InventoryReturn $record): string => self::getUrl('view', ['record' => $record]));
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            ReturnLinesRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageReturns::route('/'),
            'view' => ViewReturn::route('/{record}'),
        ];
    }
}
