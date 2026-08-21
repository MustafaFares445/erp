<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Actions;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Models\InventoryOperation;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderApprovalService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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

                // Which of the two things happened is only known after the
                // service has evaluated the threshold, so the message is chosen
                // here rather than through the runner's success key.
                Notification::make()
                    ->success()
                    ->title(__(
                        $submitted->status === PurchaseOrderStatus::Approved
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
            ->visible(fn (PurchaseOrder $record): bool => self::canTransition($record, PurchaseOrderStatus::Approved)
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
            ->visible(fn (PurchaseOrder $record): bool => self::canTransition($record, PurchaseOrderStatus::Sent)
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
     * Opens a draft inventory receipt pre-filled from the order's outstanding
     * quantities.
     *
     * Stock does not move here. It moves when that receipt is completed through
     * the Inventory services, which is the whole point of R-001 — purchasing
     * initiates, Inventory posts.
     */
    public static function receive(): Action
    {
        return Action::make('receive')
            ->label(__('admin.purchasing.actions.receive'))
            ->icon(Heroicon::ArrowDownTray)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('admin.purchasing.hints.receive_through_inventory'))
            ->visible(fn (PurchaseOrder $record): bool => self::canAct('receive', $record))
            ->authorize(fn (PurchaseOrder $record): bool => self::canAct('receive', $record))
            ->action(function (PurchaseOrder $record): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                $operation = self::runPurchasingOperation(
                    fn (): InventoryOperation => app(PurchaseOrderReceivingService::class)->initiate($actor, $record),
                );

                Notification::make()
                    ->success()
                    ->title(__('admin.purchasing.notifications.receipt_started', [
                        // A draft operation has no number yet — one is allocated
                        // when it reaches `ready` — so the id is what identifies
                        // it until then.
                        'operation' => $operation->operation_number ?? (string) $operation->id,
                        'order' => $record->purchase_order_number,
                    ]))
                    ->send();
            });
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
