<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * The ordered lines of a purchase order.
 *
 * Every write goes through {@see PurchaseOrderService} rather than Filament's
 * own relationship save, so cost defaulting, duplicate rejection, and the
 * document-total recomputation happen in one place and a direct call is refused
 * by the same rules as the form (R-G).
 *
 * Add, edit, and remove are reachable only while the parent is a draft. Once an
 * order is sent, its lines are what the supplier agreed to (FR-025).
 */
final class LinesRelationManager extends RelationManager
{
    use InteractsWithPurchasingServices;

    protected static string $relationship = 'lines';

    #[\Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_variant_id')
                    ->label(__('admin.purchasing.fields.product_variant'))
                    ->relationship('productVariant', 'sku')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    // Shows the supplier's last-known price the moment a variant
                    // is chosen, so the buyer sees what they are expected to pay
                    // before they type anything (FR-013).
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (! is_numeric($state)) {
                            $set('unit_id', null);

                            return;
                        }

                        $variantId = (int) $state;
                        $unitId = $this->defaultPurchaseUnitId($variantId);
                        $set('unit_id', $unitId);
                        $set('unit_cost', $this->defaultUnitCost($variantId, $unitId));
                    }),
                Select::make('unit_id')
                    ->label(__('admin.purchasing.fields.unit'))
                    ->options(fn (Get $get): array => $this->purchaseUnitOptions($get('product_variant_id')))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        if (! is_numeric($state) || ! is_numeric($get('product_variant_id'))) {
                            return;
                        }

                        $set('unit_cost', $this->defaultUnitCost(
                            (int) $get('product_variant_id'),
                            (int) $state,
                        ));
                    }),
                TextInput::make('quantity_ordered')
                    ->label(__('admin.purchasing.fields.quantity_ordered'))
                    ->numeric()
                    ->minValue(0.001)
                    ->step(0.001)
                    ->required(),
                TextInput::make('unit_cost')
                    ->label(__('admin.purchasing.fields.unit_cost'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required()
                    ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.purchasing.hints.unit_cost_source')),
                DatePicker::make('expected_at')
                    ->label(__('admin.purchasing.fields.expected_at')),
            ])
            ->disabled(fn (): bool => ! $this->order()->status->isEditable());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('productVariant.sku')->label(__('admin.purchasing.fields.product_variant')),
                TextColumn::make('unit.name')->label(__('admin.purchasing.fields.unit')),
                TextColumn::make('supplier_item_number')
                    ->label(__('admin.purchasing.fields.supplier_item_number'))
                    ->placeholder('—'),
                TextColumn::make('quantity_ordered')->label(__('admin.purchasing.fields.quantity_ordered')),
                TextColumn::make('quantity_received')->label(__('admin.purchasing.fields.quantity_received')),
                TextColumn::make('outstanding')
                    ->label(__('admin.purchasing.fields.quantity_outstanding'))
                    ->state(static fn (PurchaseOrderLine $record): float => $record->outstandingQuantity()),
                TextColumn::make('unit_cost')->label(__('admin.purchasing.fields.unit_cost')),
                TextColumn::make('last_received_unit_cost')
                    ->label(__('admin.purchasing.fields.last_received_unit_cost'))
                    ->placeholder('—'),
                TextColumn::make('line_total')->label(__('admin.purchasing.fields.line_total')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->order()->status->isEditable())
                    ->using(function (array $data): PurchaseOrderLine {
                        $actor = self::purchasingActor();

                        if (! $actor instanceof User) {
                            throw new LogicException('A purchase order line cannot be added without an authenticated actor.');
                        }

                        return self::runPurchasingOperation(
                            fn (): PurchaseOrderLine => app(PurchaseOrderService::class)->addLine($actor, $this->order(), [
                                'product_variant_id' => self::integerFrom($data['product_variant_id'] ?? null),
                                'unit_id' => self::integerFrom($data['unit_id'] ?? null),
                                'quantity_ordered' => self::floatFrom($data['quantity_ordered'] ?? null),
                                'unit_cost' => self::floatFrom($data['unit_cost'] ?? null),
                                'expected_at' => self::nullableStringFrom($data['expected_at'] ?? null),
                            ]),
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->order()->status->isEditable())
                    ->using(function (PurchaseOrderLine $record, array $data): PurchaseOrderLine {
                        $actor = self::purchasingActor();

                        if (! $actor instanceof User) {
                            throw new LogicException('A purchase order line cannot be edited without an authenticated actor.');
                        }

                        return self::runPurchasingOperation(
                            fn (): PurchaseOrderLine => app(PurchaseOrderService::class)->updateLine($actor, $record, [
                                'quantity_ordered' => self::floatFrom($data['quantity_ordered'] ?? null),
                                'unit_cost' => self::floatFrom($data['unit_cost'] ?? null),
                                'expected_at' => self::nullableStringFrom($data['expected_at'] ?? null),
                            ]),
                        );
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->order()->status->isEditable())
                    ->using(function (PurchaseOrderLine $record): void {
                        $actor = self::purchasingActor();

                        if (! $actor instanceof User) {
                            throw new LogicException('A purchase order line cannot be removed without an authenticated actor.');
                        }

                        self::runPurchasingOperation(function () use ($actor, $record): bool {
                            app(PurchaseOrderService::class)->removeLine($actor, $record);

                            return true;
                        });
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ])->visible(fn (): bool => $this->order()->status->isEditable()),
            ]);
    }

    /** @return array<int, string> */
    private function purchaseUnitOptions(mixed $variantId): array
    {
        if (! is_numeric($variantId)) {
            return [];
        }

        return ProductVariantUnit::query()
            ->with('unit:id,name')
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->orderByDesc('is_base')
            ->get()
            ->mapWithKeys(static function (ProductVariantUnit $configuration): array {
                $unit = $configuration->unit;

                return [
                    $configuration->unit_id => $unit instanceof Unit
                        ? $unit->name
                        : (string) $configuration->unit_id,
                ];
            })
            ->all();
    }

    private function defaultPurchaseUnitId(int $variantId): ?int
    {
        $unitId = ProductVariantUnit::query()
            ->where('product_variant_id', $variantId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->orderByDesc('is_base')
            ->value('unit_id');

        return is_numeric($unitId) ? (int) $unitId : null;
    }

    private function defaultUnitCost(int $variantId, ?int $unitId): float
    {
        if (! is_int($unitId)) {
            return 0.0;
        }

        $reference = app(PurchaseOrderService::class)
            ->referenceFor($this->order()->supplier_id, $variantId);

        if (! $reference instanceof SupplierProductReference) {
            return 0.0;
        }

        $factor = ProductVariantUnit::query()
            ->where('product_variant_id', $variantId)
            ->where('unit_id', $unitId)
            ->where('is_active', true)
            ->where('is_purchase', true)
            ->value('factor_to_base');

        return is_numeric($factor)
            ? round((float) $reference->purchase_cost * (float) $factor, 2)
            : 0.0;
    }

    /**
     * The owner record is always a {@see PurchaseOrder} — this relation manager
     * is mounted nowhere else — but `getOwnerRecord()` is typed as the generic
     * base `Model`. Narrowing here once lets every caller above use the order's
     * own methods without repeating the check.
     */
    private function order(): PurchaseOrder
    {
        $record = $this->getOwnerRecord();

        // @codeCoverageIgnoreStart
        // Unreachable in practice; the guard exists only to satisfy static
        // analysis, which sees getOwnerRecord() as returning the base Model.
        if (! $record instanceof PurchaseOrder) {
            throw new LogicException('Expected the owner record of LinesRelationManager to be a PurchaseOrder.');
        }

        // @codeCoverageIgnoreEnd

        return $record;
    }
}
