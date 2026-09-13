<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Models\InventoryOperation;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Every lifecycle action, defined once and mounted on the table, the view page,
 * and the edit page.
 *
 * Each is visible only when the acting user holds the matching ability *and* the
 * order is in a status the transition matrix permits, so a Purchasing Officer
 * sees Submit but not Approve, and nobody sees Send on a draft. None does any
 * work itself — each is a thin adapter over the service that owns the
 * validation and the transaction (R-G).
 *
 * @see /specs/017-purchasing-orders-suppliers/contracts/permissions.md §3
 */
final class PurchaseOrderActions
{
    use InteractsWithPurchasingServices;

    public static function submit(): Action
    {
        return Action::make('submit')
            ->label(__('admin.purchasing.actions.submit'))
            ->icon(Heroicon::PaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('admin.purchasing.actions.submit_confirm'))
            ->visible(fn (PurchaseOrder $record): bool => self::canAct('submit', $record))
            ->authorize(fn (PurchaseOrder $record): bool => self::canAct('submit', $record))
            ->action(function (PurchaseOrder $record): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                $submitted = self::runPurchasingOperation(
                    fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)->submit($actor, $record),
                );

                Notification::make()
                    ->success()
                    ->title(__(
                        $submitted->status === PurchaseOrderStatus::Accepted
                            ? 'admin.purchasing.notifications.auto_approved'
                            : 'admin.purchasing.notifications.submitted',
                        ['order' => $submitted->purchase_order_number],
                    ))
                    ->send();
            });
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('admin.purchasing.actions.approve'))
            ->icon(Heroicon::CheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (PurchaseOrder $record): bool => self::canTransition($record, PurchaseOrderStatus::Accepted)
                && self::canAct('approve', $record))
            ->authorize(fn (PurchaseOrder $record): bool => self::canAct('approve', $record))
            ->action(function (PurchaseOrder $record): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(
                    fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)->approve($actor, $record),
                    'admin.purchasing.notifications.approved',
                    ['order' => (string) $record->purchase_order_number],
                );
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('admin.purchasing.actions.reject'))
            ->icon(Heroicon::XCircle)
            ->color('danger')
            ->modalDescription(__('admin.purchasing.actions.reject_confirm'))
            ->schema([
                Textarea::make('rejection_reason')
                    ->label(__('admin.purchasing.fields.rejection_reason'))
                    ->rows(2)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (PurchaseOrder $record): bool => self::canTransition($record, PurchaseOrderStatus::Rejected)
                && self::canAct('approve', $record))
            ->authorize(fn (PurchaseOrder $record): bool => self::canAct('approve', $record))
            ->action(function (PurchaseOrder $record, array $data): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(
                    fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)->reject(
                        $actor,
                        $record,
                        self::stringFrom($data['rejection_reason'] ?? null),
                    ),
                    'admin.purchasing.notifications.rejected',
                    ['order' => (string) $record->purchase_order_number],
                );
            });
    }

    public static function send(): Action
    {
        return Action::make('send')
            ->label(__('admin.purchasing.actions.send'))
            ->icon(Heroicon::Envelope)
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('admin.purchasing.actions.send_confirm'))
            ->visible(fn (PurchaseOrder $record): bool => $record->status->isAcceptedOrLater()
                && self::canAct('send', $record))
            ->authorize(fn (PurchaseOrder $record): bool => self::canAct('send', $record))
            ->action(function (PurchaseOrder $record): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(
                    fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)->send($actor, $record),
                    'admin.purchasing.notifications.sent',
                    ['order' => (string) $record->purchase_order_number],
                );
            });
    }

    public static function close(): Action
    {
        return Action::make('close')
            ->label(__('admin.purchasing.actions.close'))
            ->icon(Heroicon::ArchiveBox)
            ->color('warning')
            ->modalDescription(__('admin.purchasing.actions.close_confirm'))
            ->schema([
                Textarea::make('closure_reason')
                    ->label(__('admin.purchasing.fields.closure_reason'))
                    ->rows(2)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (PurchaseOrder $record): bool => self::canTransition($record, PurchaseOrderStatus::Closed)
                && self::canAct('close', $record))
            ->authorize(fn (PurchaseOrder $record): bool => self::canAct('close', $record))
            ->action(function (PurchaseOrder $record, array $data): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(
                    fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)->close(
                        $actor,
                        $record,
                        self::stringFrom($data['closure_reason'] ?? null),
                    ),
                    'admin.purchasing.notifications.closed',
                    ['order' => (string) $record->purchase_order_number],
                );
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('admin.purchasing.actions.cancel'))
            ->icon(Heroicon::NoSymbol)
            ->color('danger')
            ->modalDescription(__('admin.purchasing.actions.cancel_confirm'))
            ->schema([
                Textarea::make('cancellation_reason')
                    ->label(__('admin.purchasing.fields.cancellation_reason'))
                    ->rows(2)
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (PurchaseOrder $record): bool => self::canTransition($record, PurchaseOrderStatus::Cancelled)
                && self::canAct('cancel', $record))
            ->authorize(fn (PurchaseOrder $record): bool => self::canAct('cancel', $record))
            ->action(function (PurchaseOrder $record, array $data): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runPurchasingOperation(
                    fn (): PurchaseOrder => app(PurchaseOrderApprovalService::class)->cancel(
                        $actor,
                        $record,
                        self::stringFrom($data['cancellation_reason'] ?? null),
                    ),
                    'admin.purchasing.notifications.cancelled',
                    ['order' => (string) $record->purchase_order_number],
                );
            });
    }

    /**
     * Opens one draft Inventory receipt for one explicit inbound allocation.
     *
     * The allocation selector exposes only quantities not already reserved by a
     * non-cancelled receipt. Stock still does not move here; Inventory posts it
     * when the generated receipt operation is completed.
     */
    public static function receive(): Action
    {
        return Action::make('receive')
            ->label(__('admin.purchasing.actions.receive'))
            ->icon(Heroicon::ArrowDownTray)
            ->color('primary')
            ->modalDescription(__('purchase_inbound.hints.receipt'))
            ->schema([
                Select::make('purchase_inbound_allocation_id')
                    ->label(__('purchase_inbound.fields.allocation'))
                    ->options(fn (PurchaseOrder $record): array => self::receivableAllocationOptions($record))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn (PurchaseOrder $record): ?int => self::singleReceivableAllocationId($record)),
                TextInput::make('quantity')
                    ->label(__('purchase_inbound.fields.receipt_quantity'))
                    ->helperText(__('purchase_inbound.hints.base_quantity'))
                    ->numeric()
                    ->step(0.000001)
                    ->minValue(0.000001)
                    ->required()
                    ->default(fn (PurchaseOrder $record): ?string => self::singleReceivableAllocationQuantity($record)),
            ])
            ->visible(fn (PurchaseOrder $record): bool => self::canAct('receive', $record)
                && self::receivableAllocationOptions($record) !== [])
            ->authorize(fn (PurchaseOrder $record): bool => self::canAct('receive', $record))
            ->action(function (PurchaseOrder $record, array $data): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                $allocationId = self::integerFrom($data['purchase_inbound_allocation_id'] ?? null);
                $quantity = self::stringFrom($data['quantity'] ?? null);

                $operation = self::runPurchasingOperation(
                    fn (): InventoryOperation => app(PurchaseOrderReceivingService::class)->initiate($actor, $record, [[
                        'purchase_inbound_allocation_id' => $allocationId,
                        'quantity' => $quantity,
                    ]]),
                );

                Notification::make()
                    ->success()
                    ->title(__('admin.purchasing.notifications.receipt_started', [
                        'operation' => $operation->operation_number ?? (string) $operation->id,
                        'order' => $record->purchase_order_number,
                    ]))
                    ->send();
            });
    }

    /** @return array<int, string> */
    private static function receivableAllocationOptions(PurchaseOrder $order): array
    {
        $options = [];

        foreach (self::receivableAllocationData($order) as $allocationId => $data) {
            $options[$allocationId] = $data['label'];
        }

        return $options;
    }

    private static function singleReceivableAllocationId(PurchaseOrder $order): ?int
    {
        $data = self::receivableAllocationData($order);

        return count($data) === 1 ? array_key_first($data) : null;
    }

    private static function singleReceivableAllocationQuantity(PurchaseOrder $order): ?string
    {
        $data = self::receivableAllocationData($order);

        if (count($data) !== 1) {
            return null;
        }

        $first = reset($data);

        return $first['available'];
    }

    /**
     * @return array<int, array{label: string, available: string}>
     */
    private static function receivableAllocationData(PurchaseOrder $order): array
    {
        $inbound = $order->purchaseInbound()->first();

        if ($inbound === null) {
            return [];
        }

        $allocations = PurchaseInboundAllocation::query()
            ->whereNotNull('allocated_base_quantity')
            ->whereHas(
                'purchaseInboundLine',
                static fn ($query) => $query->where('purchase_inbound_id', $inbound->getKey()),
            )
            ->whereHas('warehouse', static fn ($query) => $query->where('is_active', true))
            ->with([
                'warehouse',
                'purchaseInboundLine.purchaseOrderLine.productVariant',
            ])
            ->orderBy('id')
            ->get();

        $receiving = app(PurchaseOrderReceivingService::class);
        $data = [];

        foreach ($allocations as $allocation) {
            $available = $receiving->availableBaseQuantityForAllocation($allocation);

            if (bccomp($available, '0.000000', 6) <= 0) {
                continue;
            }

            $purchaseLine = $allocation->purchaseInboundLine->purchaseOrderLine;
            $sku = $purchaseLine->productVariant->sku;

            $data[$allocation->id] = [
                'label' => __('purchase_inbound.options.receipt', [
                    'sku' => $sku,
                    'warehouse' => $allocation->warehouse->name,
                    'available' => $available,
                ]),
                'available' => $available,
            ];
        }

        return $data;
    }

    private static function canAct(string $ability, PurchaseOrder $order): bool
    {
        return self::purchasingActor()?->can($ability, $order) ?? false;
    }

    private static function canTransition(PurchaseOrder $order, PurchaseOrderStatus $target): bool
    {
        return $order->status->canTransitionTo($target);
    }
}
