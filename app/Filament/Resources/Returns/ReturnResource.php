<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns;

use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Returns\Pages\ManageReturns;
use App\Filament\Resources\Returns\Pages\ViewReturn;
use App\Filament\Resources\Returns\RelationManagers\ReturnLinesRelationManager;
use App\Filament\Tables\TableFit;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use App\Filament\Components\InventoryViewDiagnostics;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Support\QuantityFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
                        self::nullableInteger($get('supplier_id')) !== null,
                        fn (Builder $query): Builder => $query->where('supplier_id', self::nullableInteger($get('supplier_id'))),
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
                        self::nullableInteger($get('supplier_id')) !== null,
                        fn (Builder $query): Builder => $query->where('supplier_id', self::nullableInteger($get('supplier_id'))),
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
            InventoryViewDiagnostics::section(),
            InventoryViewDiagnostics::metrics(static fn (Model $record): array => self::metrics($record)),
            Section::make(__('admin.inventory.return.details'))->columnSpanFull()->columns(['default' => 1, 'md' => 2, 'xl' => 3])->schema([
                TextEntry::make('return_number')->label(__('admin.inventory.return.number')),
                TextEntry::make('return_type')->label(__('admin.inventory.return.type'))->badge(),
                TextEntry::make('status')->label(__('admin.inventory.return.status'))->badge()
                    ->formatStateUsing(fn (InventoryReturnStatus|string|null $state): string => $state instanceof InventoryReturnStatus
                        ? $state->label()
                        : (InventoryReturnStatus::tryFrom((string) $state)?->label() ?? __('—'))),
                TextEntry::make('warehouse.name')->label(__('admin.inventory.return.warehouse')),
                TextEntry::make('customer.customer_code')->label(__('admin.inventory.return.customer'))->placeholder(__('—')),
                TextEntry::make('supplier.name')->label(__('admin.inventory.return.supplier'))->placeholder(__('—')),
                TextEntry::make('originalOperation.operation_number')
                    ->label(__('admin.inventory.return.original_document'))
                    ->url(fn (InventoryReturn $record): ?string => $record->original_inventory_operation_id
                        ? InventoryOperationResource::getUrl('view', ['record' => $record->original_inventory_operation_id])
                        : null)
                    ->placeholder(__('—')),
                TextEntry::make('originalPurchaseOrder.purchase_order_number')
                    ->label(__('admin.inventory.return.original_purchase_order'))
                    ->url(fn (InventoryReturn $record): ?string => $record->original_purchase_order_id
                        ? PurchaseOrderResource::getUrl('view', ['record' => $record->original_purchase_order_id])
                        : null)
                    ->placeholder(__('—')),
                TextEntry::make('createdBy.name')->label(__('admin.inventory.return.created_by'))->placeholder(__('—')),
                TextEntry::make('ready_at')->label(__('admin.inventory.return.ready_at'))->dateTime()->placeholder(__('—')),
                TextEntry::make('posted_at')->label(__('admin.inventory.return.posted_at'))->dateTime()->placeholder(__('—')),
                TextEntry::make('cancelled_at')->label(__('admin.inventory.return.cancelled_at'))->dateTime()->placeholder(__('—')),
                TextEntry::make('cancellation_reason')
                    ->label(__('admin.inventory.return.cancellation_reason'))
                    ->columnSpanFull()
                    ->placeholder(__('—')),
                TextEntry::make('reason')->label(__('admin.inventory.return.reason'))->columnSpanFull()->placeholder(__('—')),
                TextEntry::make('notes')->label(__('admin.inventory.return.notes'))->columnSpanFull()->placeholder(__('—')),
                TextEntry::make('financial_consequence')
                    ->label(__('admin.inventory.return.financial_consequence'))
                    ->state(fn (InventoryReturn $record): string => $record->return_type === InventoryReturnType::Supplier
                        ? __('admin.inventory.return.supplier_financial_consequence')
                        : __('admin.inventory.return.customer_financial_consequence'))
                    ->columnSpanFull(),
                TextEntry::make('credit_note_required')
                    ->label(__('admin.inventory.return.credit_note_required'))
                    ->visible(fn (InventoryReturn $record): bool => $record->return_type === InventoryReturnType::Customer)
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('admin.inventory.return.yes')
                        : __('admin.inventory.return.no')),
                TextEntry::make('creditNotes.credit_note_number')
                    ->label(__('admin.inventory.return.credit_notes'))
                    ->listWithLineBreaks()
                    ->placeholder(__('—'))
                    ->columnSpanFull(),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return TableFit::apply($table)
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
                    ->formatStateUsing(fn (InventoryReturnStatus|string|null $state): string => $state instanceof InventoryReturnStatus
                        ? $state->label()
                        : (InventoryReturnStatus::tryFrom((string) $state)?->label() ?? __('—')))
                    ->sortable(),
                TextColumn::make('warehouse.name')->wrap()
                    ->label(__('admin.inventory.return.warehouse'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.customer_code')
                    ->label(__('admin.inventory.return.customer'))
                    ->placeholder(__('—')),
                TextColumn::make('supplier.name')->wrap()
                    ->label(__('admin.inventory.return.supplier'))
                    ->placeholder(__('—')),
                TextColumn::make('originalOperation.operation_number')
                    ->label(__('admin.inventory.return.original_document'))
                    ->url(fn (InventoryReturn $record): ?string => $record->original_inventory_operation_id
                        ? InventoryOperationResource::getUrl('view', ['record' => $record->original_inventory_operation_id])
                        : null)
                    ->placeholder(__('—')),
                TextColumn::make('originalPurchaseOrder.purchase_order_number')->toggleable(isToggledHiddenByDefault: true)
                    ->label(__('admin.inventory.return.original_purchase_order'))
                    ->url(fn (InventoryReturn $record): ?string => $record->original_purchase_order_id
                        ? PurchaseOrderResource::getUrl('view', ['record' => $record->original_purchase_order_id])
                        : null)
                    ->placeholder(__('—')),
                TextColumn::make('lines_count')
                    ->label(__('admin.inventory.return.lines_count'))
                    ->counts('lines'),
                TextColumn::make('posted_at')
                    ->label(__('admin.inventory.return.posted_at'))
                    ->dateTime()
                    ->placeholder(__('—'))
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
                        ->mapWithKeys(fn (InventoryReturnStatus $status): array => [$status->value => $status->label()])
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

    private static function nullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(Model $record): array
    {
        assert($record instanceof InventoryReturn);

        $record->loadMissing('lines');
        $lines = $record->lines;
        $metrics = [
            [
                'label' => __('inventory_view_ux.ui.return_lines'),
                'value' => (string) $lines->count(),
                'tone' => $lines->isEmpty() && ! $record->isTerminal() ? 'danger' : null,
            ],
        ];

        if ($record->return_type === InventoryReturnType::Customer) {
            $inspected = $lines->filter(static fn (InventoryReturnLine $line): bool => $line->disposition !== null && $line->inspected_at !== null)->count();
            $metrics[] = [
                'label' => __('inventory_view_ux.ui.return_inspected'),
                'value' => $inspected.' / '.$lines->count(),
                'tone' => $record->isDraft() && $inspected < $lines->count() ? 'warning' : null,
            ];
        }

        $metrics[] = [
            'label' => __('inventory_view_ux.ui.return_quantity'),
            'value' => QuantityFormatter::display($lines->sum(static fn (InventoryReturnLine $line): float => (float) $line->base_quantity)),
            'helper' => __('inventory_view_ux.ui.document_base_unit_helper'),
            'tone' => 'primary',
        ];

        if ($record->isPosted()) {
            $metrics[] = [
                'label' => __('inventory_view_ux.ui.return_posted_quantity'),
                'value' => QuantityFormatter::display($lines->sum(static fn (InventoryReturnLine $line): float => (float) $line->posted_base_quantity)),
            ];
        }

        return $metrics;
    }
}
