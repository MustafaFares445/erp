<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCorrections\RelationManagers;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Services\Inventory\InventoryCorrectionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CorrectionLinesRelationManager extends RelationManager
{
    use InteractsWithInventoryServices;

    protected static string $relationship = 'lines';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label(__('admin.inventory.stock.variant')),
                TextColumn::make('transaction_quantity')
                    ->label(__('admin.inventory.correction.quantity'))
                    ->numeric(decimalPlaces: 6),
                TextColumn::make('base_quantity')
                    ->label(__('admin.inventory.correction.base_quantity'))
                    ->numeric(decimalPlaces: 6),
                TextColumn::make('lot.lot_number')
                    ->label(__('admin.inventory.lot.fields.lot'))
                    ->placeholder('—'),
                TextColumn::make('serializedUnit.serial_number')
                    ->label(__('admin.inventory.correction.serial'))
                    ->placeholder('—'),
                TextColumn::make('original_inventory_movement_id')
                    ->label(__('admin.inventory.correction.original_movement')),
                TextColumn::make('posted_inventory_movement_id')
                    ->label(__('admin.inventory.correction.compensating_movement'))
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('addReceiptLine')
                    ->label(__('admin.inventory.correction.actions.add_line'))
                    ->visible(fn (): bool => $this->correctionRecord()->isDraft())
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->correctionRecord()) ?? false)
                    ->schema([
                        Select::make('original_inventory_operation_line_id')
                            ->label(__('admin.inventory.correction.original_line'))
                            ->options(fn (): array => $this->receiptLineOptions())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('transaction_quantity')
                            ->label(__('admin.inventory.correction.quantity'))
                            ->numeric()
                            ->minValue(0.000001)
                            ->step(0.000001)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $lineId = $data['original_inventory_operation_line_id'] ?? null;
                        $quantity = $data['transaction_quantity'] ?? null;

                        if (
                            ! is_numeric($lineId)
                            || (! is_string($quantity) && ! is_int($quantity) && ! is_float($quantity))
                        ) {
                            throw new LogicException('A receipt line and correction quantity are required.');
                        }

                        $normalized = is_float($quantity)
                            ? number_format($quantity, 6, '.', '')
                            : (string) $quantity;

                        $this->runInventoryOperation(
                            fn () => app(InventoryCorrectionService::class)->addReceiptLine(
                                $this->correctionRecord(),
                                InventoryOperationLine::query()->findOrFail((int) $lineId),
                                $normalized,
                            ),
                            'admin.inventory.correction.notifications.line_added',
                        );
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(__('admin.inventory.correction.actions.remove_line'))
                    ->color('danger')
                    ->visible(fn (): bool => $this->correctionRecord()->isDraft())
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->correctionRecord()) ?? false)
                    ->requiresConfirmation()
                    ->action(fn (InventoryCorrectionLine $record) => $this->runInventoryOperation(
                        fn () => app(InventoryCorrectionService::class)->removeLine($record),
                        'admin.inventory.correction.notifications.line_removed',
                    )),
            ])
            ->toolbarActions([]);
    }

    /** @return array<int, string> */
    private function receiptLineOptions(): array
    {
        return InventoryOperationLine::query()
            ->with('productVariant')
            ->where('inventory_operation_id', $this->correctionRecord()->original_inventory_operation_id)
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

    private static function integerKey(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory records must use integer identifiers.');
        }

        return $key;
    }

    private function correctionRecord(): InventoryCorrection
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof InventoryCorrection) {
            throw new LogicException('Expected an InventoryCorrection owner record.');
        }

        return $record;
    }
}
