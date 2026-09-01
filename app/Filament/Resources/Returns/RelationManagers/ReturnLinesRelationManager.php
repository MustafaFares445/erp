<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\RelationManagers;

use App\Enums\InventoryReturnDisposition;
use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Models\InventoryLot;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Inventory\InventoryReturnService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ReturnLinesRelationManager extends RelationManager
{
    use InteractsWithInventoryServices;

    protected static string $relationship = 'lines';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('productVariant.sku')->label(__('admin.inventory.stock.variant')),
                TextColumn::make('transaction_quantity')->label(__('admin.inventory.return.quantity'))->numeric(decimalPlaces: 6),
                TextColumn::make('transactionUnit.symbol')->label(__('admin.inventory.return.unit'))->placeholder('—'),
                TextColumn::make('base_quantity')->label(__('admin.inventory.return.base_quantity'))->numeric(decimalPlaces: 6),
                TextColumn::make('lot.lot_number')->label(__('admin.inventory.lot.fields.lot'))->placeholder('—'),
                TextColumn::make('serializedUnit.serial_number')->label(__('admin.inventory.return.serial'))->placeholder('—'),
                TextColumn::make('source_condition')->label(__('admin.inventory.return.source_condition'))->badge()->placeholder('—'),
                TextColumn::make('disposition')->label(__('admin.inventory.return.disposition'))->badge()->placeholder('—'),
                TextColumn::make('posted_base_quantity')->label(__('admin.inventory.return.posted_quantity'))->numeric(decimalPlaces: 6),
            ])
            ->headerActions([
                $this->addCustomerLineAction(),
                $this->addSupplierLineAction(),
            ])
            ->recordActions([
                $this->inspectAction(),
                $this->removeAction(),
            ])
            ->toolbarActions([]);
    }

    private function addCustomerLineAction(): Action
    {
        return Action::make('addCustomerLine')
            ->label(__('admin.inventory.return.actions.add_customer_line'))
            ->visible(fn (): bool => $this->returnRecord()->return_type === InventoryReturnType::Customer
                && $this->returnRecord()->isDraft())
            ->authorize(fn (): bool => auth()->user()?->can('update', $this->returnRecord()) ?? false)
            ->schema([
                Select::make('original_inventory_operation_line_id')
                    ->label(__('admin.inventory.return.original_line'))
                    ->options(fn (): array => $this->customerLineOptions())
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('transaction_quantity')
                    ->label(__('admin.inventory.return.quantity'))
                    ->numeric()
                    ->minValue(0.000001)
                    ->step(0.000001)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $actor = $this->actor();
                $lineId = $data['original_inventory_operation_line_id'] ?? null;
                $quantity = $data['transaction_quantity'] ?? null;

                if (! is_numeric($lineId) || (! is_string($quantity) && ! is_int($quantity) && ! is_float($quantity))) {
                    throw new LogicException('A valid delivery line and return quantity are required.');
                }

                $deliveryLine = InventoryOperationLine::query()->findOrFail((int) $lineId);
                $normalizedQuantity = is_float($quantity)
                    ? number_format($quantity, 6, '.', '')
                    : (string) $quantity;

                $this->runInventoryOperation(
                    fn () => app(InventoryReturnService::class)->addCustomerLine(
                        $this->returnRecord(),
                        $deliveryLine,
                        $normalizedQuantity,
                        $deliveryLine->inventory_lot_id,
                        $deliveryLine->serialized_inventory_unit_id,
                    ),
                    'admin.inventory.return.notifications.line_added',
                );
            });
    }

    private function addSupplierLineAction(): Action
    {
        return Action::make('addSupplierLine')
            ->label(__('admin.inventory.return.actions.add_supplier_line'))
            ->visible(fn (): bool => $this->returnRecord()->return_type === InventoryReturnType::Supplier
                && $this->returnRecord()->isDraft())
            ->authorize(fn (): bool => auth()->user()?->can('update', $this->returnRecord()) ?? false)
            ->schema([
                Select::make('product_variant_id')
                    ->label(__('admin.inventory.stock.variant'))
                    ->options(fn (): array => ProductVariant::query()
                        ->where('is_active', true)
                        ->orderBy('sku')
                        ->pluck('sku', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required(),
                Select::make('source_condition')
                    ->label(__('admin.inventory.return.source_condition'))
                    ->options([
                        StockCondition::Saleable->value => __('admin.inventory.stock.saleable_quantity'),
                        StockCondition::Quarantine->value => __('admin.inventory.stock.quarantine_quantity'),
                        StockCondition::Damaged->value => __('admin.inventory.stock.damaged_quantity'),
                    ])
                    ->live()
                    ->required(),
                TextInput::make('transaction_quantity')
                    ->label(__('admin.inventory.return.quantity'))
                    ->numeric()
                    ->minValue(0.000001)
                    ->step(0.000001)
                    ->required(),
                Select::make('inventory_lot_id')
                    ->label(__('admin.inventory.lot.fields.lot'))
                    ->options(fn (Get $get): array => $this->supplierLotOptions($get))
                    ->searchable()
                    ->preload()
                    ->live(),
                Select::make('serialized_inventory_unit_id')
                    ->label(__('admin.inventory.return.serial'))
                    ->options(fn (Get $get): array => $this->supplierSerialOptions($get))
                    ->searchable()
                    ->preload(),
                Select::make('original_inventory_operation_line_id')
                    ->label(__('admin.inventory.return.original_receipt_line'))
                    ->options(fn (): array => $this->supplierReceiptLineOptions())
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => $this->returnRecord()->original_inventory_operation_id !== null),
            ])
            ->action(function (array $data): void {
                $variantId = $data['product_variant_id'] ?? null;
                $conditionValue = $data['source_condition'] ?? null;
                $quantity = $data['transaction_quantity'] ?? null;

                if (
                    ! is_numeric($variantId)
                    || ! is_string($conditionValue)
                    || (! is_string($quantity) && ! is_int($quantity) && ! is_float($quantity))
                ) {
                    throw new LogicException('A valid supplier return line is required.');
                }

                $variant = ProductVariant::query()->findOrFail((int) $variantId);
                $condition = StockCondition::from($conditionValue);
                $receiptLineId = $data['original_inventory_operation_line_id'] ?? null;
                $normalizedQuantity = is_float($quantity)
                    ? number_format($quantity, 6, '.', '')
                    : (string) $quantity;

                $this->runInventoryOperation(
                    fn () => app(InventoryReturnService::class)->addSupplierLine(
                        $this->returnRecord(),
                        $variant,
                        (int) $variant->unit_id,
                        $normalizedQuantity,
                        $condition,
                        is_numeric($data['inventory_lot_id'] ?? null) ? (int) $data['inventory_lot_id'] : null,
                        is_numeric($data['serialized_inventory_unit_id'] ?? null)
                            ? (int) $data['serialized_inventory_unit_id']
                            : null,
                        is_numeric($receiptLineId)
                            ? InventoryOperationLine::query()->findOrFail((int) $receiptLineId)
                            : null,
                    ),
                    'admin.inventory.return.notifications.line_added',
                );
            });
    }

    private function inspectAction(): Action
    {
        return Action::make('inspect')
            ->label(__('admin.inventory.return.actions.inspect'))
            ->visible(fn (InventoryReturnLine $record): bool => $this->returnRecord()->return_type === InventoryReturnType::Customer
                && $this->returnRecord()->status === InventoryReturnStatus::Draft
                && (auth()->user()?->can('inspect', $this->returnRecord()) ?? false))
            ->authorize(fn (): bool => auth()->user()?->can('inspect', $this->returnRecord()) ?? false)
            ->schema([
                Select::make('disposition')
                    ->label(__('admin.inventory.return.disposition'))
                    ->options([
                        InventoryReturnDisposition::Saleable->value => __('admin.inventory.stock.saleable_quantity'),
                        InventoryReturnDisposition::Quarantine->value => __('admin.inventory.stock.quarantine_quantity'),
                        InventoryReturnDisposition::Damaged->value => __('admin.inventory.stock.damaged_quantity'),
                    ])
                    ->required(),
                Textarea::make('inspection_notes')
                    ->label(__('admin.inventory.return.inspection_notes'))
                    ->maxLength(2_000),
            ])
            ->fillForm(fn (InventoryReturnLine $record): array => [
                'disposition' => $record->disposition?->value,
                'inspection_notes' => $record->inspection_notes,
            ])
            ->action(function (InventoryReturnLine $record, array $data): void {
                $disposition = $data['disposition'] ?? null;

                if (! is_string($disposition)) {
                    throw new LogicException('A return disposition is required.');
                }

                $notes = is_string($data['inspection_notes'] ?? null)
                    ? mb_trim($data['inspection_notes'])
                    : null;

                $this->runInventoryOperation(
                    fn () => app(InventoryReturnService::class)->inspectLine(
                        $record,
                        InventoryReturnDisposition::from($disposition),
                        $this->actor(),
                        $notes === '' ? null : $notes,
                    ),
                    'admin.inventory.return.notifications.inspected',
                );
            });
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('admin.inventory.return.actions.remove_line'))
            ->color('danger')
            ->visible(fn (): bool => $this->returnRecord()->isDraft()
                && (auth()->user()?->can('update', $this->returnRecord()) ?? false))
            ->authorize(fn (): bool => auth()->user()?->can('update', $this->returnRecord()) ?? false)
            ->requiresConfirmation()
            ->action(fn (InventoryReturnLine $record) => $this->runInventoryOperation(
                fn () => app(InventoryReturnService::class)->removeLine($record),
                'admin.inventory.return.notifications.line_removed',
            ));
    }

    /** @return array<int, string> */
    private function customerLineOptions(): array
    {
        $operationId = $this->returnRecord()->original_inventory_operation_id;

        if (! is_int($operationId)) {
            return [];
        }

        return InventoryOperationLine::query()
            ->with('productVariant')
            ->where('inventory_operation_id', $operationId)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (InventoryOperationLine $line): array {
                $lineId = self::integerKey($line);
                $variant = $line->productVariant;
                $variantSku = $variant instanceof ProductVariant
                    ? $variant->sku
                    : (string) $line->product_variant_id;

                return [$lineId => sprintf(
                    '%s — %s',
                    $variantSku,
                    (string) ($line->transaction_quantity ?? $line->quantity),
                )];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function supplierReceiptLineOptions(): array
    {
        $operationId = $this->returnRecord()->original_inventory_operation_id;

        if (! is_int($operationId)) {
            return [];
        }

        return InventoryOperationLine::query()
            ->with('productVariant')
            ->where('inventory_operation_id', $operationId)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (InventoryOperationLine $line): array {
                $variant = $line->productVariant;

                return [self::integerKey($line) => $variant instanceof ProductVariant
                    ? $variant->sku
                    : (string) $line->product_variant_id];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function supplierLotOptions(Get $get): array
    {
        $variantId = $get('product_variant_id');
        $conditionValue = $get('source_condition');

        if (! is_numeric($variantId) || ! is_string($conditionValue)) {
            return [];
        }

        $condition = StockCondition::tryFrom($conditionValue);

        if (! $condition instanceof StockCondition || ! $condition->isMaterialized()) {
            return [];
        }

        $warehouseId = $this->returnRecord()->warehouse_id;

        return InventoryLot::query()
            ->canonical()
            ->where('product_variant_id', (int) $variantId)
            ->whereHas('conditionBalances', function (Builder $query) use ($warehouseId, $condition): void {
                $query->where('warehouse_id', $warehouseId)
                    ->where('stock_condition', $condition->value);

                if ($condition === StockCondition::Saleable) {
                    $query->whereRaw('on_hand_base_quantity > reserved_base_quantity');
                } else {
                    $query->where('on_hand_base_quantity', '>', 0);
                }
            })
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (InventoryLot $lot): array {
                $lotId = self::integerKey($lot);

                return [$lotId => $lot->lot_number ?? '#'.$lotId];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function supplierSerialOptions(Get $get): array
    {
        $variantId = $get('product_variant_id');
        $conditionValue = $get('source_condition');

        if (! is_numeric($variantId) || ! is_string($conditionValue)) {
            return [];
        }

        $condition = StockCondition::tryFrom($conditionValue);

        if (! $condition instanceof StockCondition || ! $condition->isMaterialized()) {
            return [];
        }

        $expectedStatus = $condition === StockCondition::Damaged
            ? SerializedInventoryUnitStatus::Damaged
            : SerializedInventoryUnitStatus::Available;

        $lotId = self::nullableInteger($get('inventory_lot_id'));

        return SerializedInventoryUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('warehouse_id', $this->returnRecord()->warehouse_id)
            ->where('custody_type', SerializedCustodyType::Warehouse->value)
            ->where('stock_condition', $condition->value)
            ->where('status', $expectedStatus->value)
            ->when(
                $lotId !== null,
                fn (Builder $query): Builder => $query->where('inventory_lot_id', $lotId),
            )
            ->orderBy('serial_number')
            ->get()
            ->mapWithKeys(fn (SerializedInventoryUnit $unit): array => [
                self::integerKey($unit) => (string) $unit->serial_number,
            ])
            ->all();
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory return actor is required.');
        }

        return $actor;
    }

    private static function integerKey(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory records must use integer identifiers.');
        }

        return $key;
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

    private function returnRecord(): InventoryReturn
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof InventoryReturn) {
            throw new LogicException('Expected an InventoryReturn owner record.');
        }

        return $record;
    }
}
