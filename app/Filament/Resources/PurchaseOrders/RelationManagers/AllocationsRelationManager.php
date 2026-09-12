<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Enums\InventoryPermission;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchaseInboundService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * Assigns each accepted order's lines to the warehouse they will be received
 * into.
 *
 * A purchase order no longer owns a destination warehouse. Allocation is an
 * Inventory-owned decision exposed contextually from the Purchase Order screen,
 * and is therefore gated by `inventory.inbound.allocate` rather than by the
 * ability to edit the commercial purchase order itself.
 */
final class AllocationsRelationManager extends RelationManager
{
    use InteractsWithPurchasingServices;

    protected static string $relationship = 'inboundLines';

    #[\Override]
    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.purchasing.fields.allocations');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('purchaseOrderLine.productVariant.sku')
                    ->label(__('admin.purchasing.fields.product_variant')),
                TextColumn::make('purchaseOrderLine.quantity_ordered')
                    ->label(__('admin.purchasing.fields.quantity_ordered')),
                TextColumn::make('allocation.warehouse.name')
                    ->label(__('admin.purchasing.fields.allocated_warehouse'))
                    ->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('allocate')
                    ->label(__('admin.purchasing.actions.allocate'))
                    ->schema([
                        Select::make('warehouse_id')
                            ->label(__('admin.purchasing.fields.warehouse'))
                            ->options(fn (): array => Warehouse::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->default(fn (PurchaseInboundLine $record): ?int => $record->allocation?->warehouse_id),
                    ])
                    ->visible(fn (): bool => self::purchasingActor()?->can(InventoryPermission::InboundAllocate->value) ?? false)
                    ->action(function (PurchaseInboundLine $record, array $data): void {
                        $actor = self::purchasingActor();

                        if (! $actor instanceof User) {
                            throw new LogicException('An allocation cannot be recorded without an authenticated actor.');
                        }

                        /** @var Warehouse $warehouse */
                        $warehouse = Warehouse::query()->findOrFail(self::integerFrom($data['warehouse_id'] ?? null));

                        self::runPurchasingOperation(
                            fn (): PurchaseInboundAllocation => app(PurchaseInboundService::class)->allocate($actor, $record, $warehouse),
                            'admin.purchasing.notifications.allocated',
                        );
                    }),
            ])
            ->toolbarActions([]);
    }
}
