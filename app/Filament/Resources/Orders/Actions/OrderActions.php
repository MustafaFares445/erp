<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Data\Orders\OrderFulfillmentData;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Sales\SalesProcurementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class OrderActions
{
    use InteractsWithSalesServices;

    public static function prepareFulfillment(): Action
    {
        return Action::make('prepare_fulfillment')
            ->label('Prepare fulfillment')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription('Allocate stock, create delivery operations, and create shipments for this sales order.')
            ->visible(fn (Order $record): bool => self::canFulfill($record)
                && ! in_array($record->status, ['pending_supplier_confirmation', 'supplier_rejected'], true))
            ->authorize(fn (Order $record): bool => self::canFulfill($record))
            ->action(function (Order $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runSalesOperation(function () use ($record, $actor): Order {
                    $record->loadMissing(['customer', 'deliveryAddress']);

                    if (! $record->customer) {
                        throw new DomainException('The sales order customer is unavailable.');
                    }

                    $service = app(OrderFulfillmentService::class);
                    $products = $service->productsForOrder($record);
                    $shipments = $service->suggestForOrder($record);

                    return $service->prepareExisting(
                        $record,
                        new OrderFulfillmentData(
                            customer: $record->customer,
                            products: $products,
                            shipments: $shipments,
                            actor: $actor,
                            notes: $record->notes,
                            deliveryAddress: $record->deliveryAddress,
                            scheduledAt: $record->scheduled_at,
                            responsible: is_numeric($record->responsible_id)
                                ? User::query()->find((int) $record->responsible_id)
                                : null,
                        ),
                    );
                });

                Notification::make()->success()->title('Fulfillment prepared on the existing sales order.')->send();
            });
    }

    public static function detectProcurement(): Action
    {
        return Action::make('detect_procurement')
            ->label('Check shortage')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Order $record): bool => self::canFulfill($record)
                && ! $record->deliveries()->where('stage', '!=', 'canceled')->exists())
            ->authorize(fn (Order $record): bool => self::canFulfill($record))
            ->action(function (Order $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                $requirements = self::runSalesOperation(
                    fn () => app(SalesProcurementService::class)->detectShortages($actor, $record),
                );

                Notification::make()
                    ->success()
                    ->title($requirements->isEmpty()
                        ? 'No stock shortage was detected.'
                        : sprintf('%d procurement requirement(s) created.', $requirements->count()))
                    ->send();
            });
    }

    public static function requestSupplierConfirmation(): Action
    {
        return Action::make('request_supplier_confirmation')
            ->label('Request supplier confirmation')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('warning')
            ->schema([
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->visible(fn (Order $record): bool => $record->status === 'pending_supplier_confirmation'
                && $record->procurementRequirements()->whereNotIn('status', ['fulfilled', 'cancelled'])->exists())
            ->action(function (Order $record, array $data): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runSalesOperation(
                    fn () => app(SalesProcurementService::class)->requestSupplierConfirmation(
                        $actor,
                        $record,
                        self::integerFrom($data['supplier_id'] ?? null),
                    ),
                );

                Notification::make()->success()->title('Supplier confirmation request recorded.')->send();
            });
    }

    public static function createPurchaseOrder(): Action
    {
        return Action::make('create_procurement_po')
            ->label('Create purchase order')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->color('success')
            ->schema([
                Select::make('supplier_id')
                    ->label('Confirmed supplier')
                    ->options(fn (Order $record): array => $record->confirmations()
                        ->whereIn('confirmation_status', ['confirmed', 'partial'])
                        ->with('supplier:id,name')
                        ->get()
                        ->mapWithKeys(fn ($confirmation): array => [
                            (int) $confirmation->supplier_id => (string) $confirmation->supplier?->name,
                        ])
                        ->all())
                    ->required(),
                Select::make('warehouse_id')
                    ->label('Receiving warehouse')
                    ->options(fn (): array => Warehouse::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->visible(fn (Order $record): bool => $record->procurementRequirements()
                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                ->whereNull('purchase_order_id')
                ->exists()
                && $record->confirmations()->whereIn('confirmation_status', ['confirmed', 'partial'])->exists())
            ->action(function (Order $record, array $data): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                $purchaseOrder = self::runSalesOperation(
                    fn () => app(SalesProcurementService::class)->createPurchaseOrder(
                        $actor,
                        $record,
                        self::integerFrom($data['supplier_id'] ?? null),
                        self::integerFrom($data['warehouse_id'] ?? null),
                    ),
                );

                Notification::make()
                    ->success()
                    ->title("Purchase order {$purchaseOrder->purchase_order_number} created.")
                    ->send();
            });
    }

    private static function canFulfill(Order $record): bool
    {
        return self::salesActor()?->can('fulfill', $record) ?? false;
    }
}
