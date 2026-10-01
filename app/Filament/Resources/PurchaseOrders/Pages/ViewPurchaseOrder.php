<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\SalesProcurementRequirement;
use App\Models\SupplierConfirmation;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use App\Services\Purchasing\SupplierConfirmationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * The order's full record: header, approval trail, lines, receipts,
 * confirmations, and — for a user holding `purchase.audit.view` — its audit
 * trail (FR-054).
 */
final class ViewPurchaseOrder extends ViewRecord
{
    use InteractsWithPurchasingServices;

    protected static string $resource = PurchaseOrderResource::class;

    #[\Override]
    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof PurchaseOrder
            ? 'Purchase Order '.$record->purchase_order_number
            : 'Purchase Order';
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof PurchaseOrder) {
            return null;
        }

        $expected = $record->expected_at?->toDateString() ?? 'Not specified';

        return sprintf(
            '%s · %s %s · Expected %s',
            $record->supplier->name,
            $record->currency_code,
            number_format((float) $record->total_amount, 2),
            $expected,
        );
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            PurchaseOrderActions::submit(),
            PurchaseOrderActions::approve(),
            PurchaseOrderActions::send(),
            $this->recordSupplierResponseAction(),
            $this->requestSupplierFollowUpAction(),
            $this->reSourceSalesDemandAction(),
            $this->openInboundAction(),
            $this->reviewBillAction(),
            ActionGroup::make([
                EditAction::make()
                    ->color('gray')
                    ->visible(fn (PurchaseOrder $record): bool => $record->status->isEditable()),
                Action::make('print')
                    ->label(__('admin.purchasing.actions.print'))
                    ->icon(Heroicon::Printer)
                    ->color('gray')
                    ->url(fn (PurchaseOrder $record): string => route('admin.purchase-orders.print', $record))
                    ->openUrlInNewTab(),
                PurchaseOrderActions::reject(),
                PurchaseOrderActions::close(),
                PurchaseOrderActions::cancel(),
            ])
                ->label(__('More actions'))
                ->color('gray'),
        ];
    }

    private function recordSupplierResponseAction(): Action
    {
        return Action::make('recordSupplierResponse')
            ->label(__('Record supplier response'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('primary')
            ->visible(function (PurchaseOrder $record): bool {
                $confirmation = $this->pendingConfirmation($record);

                return $confirmation instanceof SupplierConfirmation
                    && (auth()->user()?->can('answer', $confirmation) ?? false);
            })
            ->url(function (PurchaseOrder $record): ?string {
                $confirmation = $this->pendingConfirmation($record);

                return $confirmation instanceof SupplierConfirmation
                    ? SupplierConfirmationResource::getUrl('view', ['record' => $confirmation])
                    : null;
            });
    }

    private function requestSupplierFollowUpAction(): Action
    {
        return Action::make('requestSupplierFollowUp')
            ->label(__('Request follow-up commitment'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(function (PurchaseOrder $record): bool {
                $projection = app(PurchaseOrderWorkflowService::class)->project($record);

                return $record->sent_at !== null
                    && (float) $projection->backorderedBaseQuantity > 0
                    && ! $this->pendingConfirmation($record) instanceof SupplierConfirmation
                    && (auth()->user()?->can('request', SupplierConfirmation::class) ?? false);
            })
            ->action(function (PurchaseOrder $record): void {
                $actor = self::purchasingActor();

                if (! $actor instanceof User) {
                    return;
                }

                $confirmation = self::runPurchasingOperation(
                    fn (): SupplierConfirmation => app(SupplierConfirmationService::class)
                        ->recordPurchaseOrder($actor, $record, 'Follow-up requested for outstanding supplier backorder.'),
                );

                Notification::make()
                    ->success()
                    ->title(__('Supplier follow-up created'))
                    ->send();

                $this->redirect(SupplierConfirmationResource::getUrl('view', ['record' => $confirmation]));
            });
    }

    private function reSourceSalesDemandAction(): Action
    {
        return Action::make('reSourceSalesDemand')
            ->label(__('Re-source remaining demand'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (PurchaseOrder $record): bool => SalesProcurementRequirement::query()
                ->where('purchase_order_id', $record->id)
                ->where('status', 'superseded')
                ->exists())
            ->url(PurchaseNeeds::getUrl());
    }

    private function openInboundAction(): Action
    {
        return Action::make('openInbound')
            ->label(__('Open inbound execution'))
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('primary')
            ->visible(function (PurchaseOrder $record): bool {
                $inbound = $record->purchaseInbound;

                return $inbound !== null
                    && app(PurchaseOrderWorkflowService::class)->project($record)->nextOwner === 'Inventory'
                    && (auth()->user()?->can('view', $inbound) ?? false);
            })
            ->url(fn (PurchaseOrder $record): ?string => $record->purchaseInbound === null
                ? null
                : PurchaseInboundResource::getUrl('view', ['record' => $record->purchaseInbound]));
    }

    private function reviewBillAction(): Action
    {
        return Action::make('reviewSupplierBill')
            ->label(fn (PurchaseOrder $record): string => $this->accountingBill($record) instanceof Bill
                ? 'Review supplier bill'
                : 'Create supplier bill')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('primary')
            ->visible(function (PurchaseOrder $record): bool {
                if (app(PurchaseOrderWorkflowService::class)->project($record)->nextOwner !== 'Accounting') {
                    return false;
                }

                $bill = $this->accountingBill($record);

                return $bill instanceof Bill
                    ? (auth()->user()?->can('view', $bill) ?? false)
                    : (auth()->user()?->can('create', Bill::class) ?? false);
            })
            ->url(function (PurchaseOrder $record): string {
                $bill = $this->accountingBill($record);

                return $bill instanceof Bill
                    ? BillResource::getUrl('view', ['record' => $bill])
                    : BillResource::getUrl('index', [
                        'action' => 'create',
                        'actionArguments' => ['purchase_order_id' => $record->id],
                    ]);
            });
    }

    private function pendingConfirmation(PurchaseOrder $order): ?SupplierConfirmation
    {
        return $order->confirmations()
            ->where('confirmation_status', SupplierConfirmationStatus::Pending->value)
            ->latest('id')
            ->first();
    }

    private function accountingBill(PurchaseOrder $order): ?Bill
    {
        return $order->bills()->latest('id')->first();
    }

    /**
     * Every logged transition on this order, newest first.
     *
     * Read from the shared activity log rather than a purchasing-specific table,
     * per ADR 0005. Gated on `viewAudit` so the audit trail follows the same
     * permission boundary as the reports.
     *
     * @return list<array{event: string, causer: string, at: string}>
     */
    public function auditTrail(): array
    {
        $record = $this->getRecord();
        $actor = auth()->user();

        if (! $record instanceof PurchaseOrder) {
            return [];
        }

        if (! $actor instanceof User || ! $actor->can('viewAudit', $record)) {
            return [];
        }

        $entries = AuditLog::query()
            ->where('subject_type', PurchaseOrder::class)
            ->where('subject_id', $record->getKey())
            ->latest('id')
            ->get();

        $trail = [];

        foreach ($entries as $entry) {
            $causer = $entry->causer;

            $trail[] = [
                'event' => (string) $entry->description,
                'causer' => $causer instanceof User ? $causer->name : '—',
                'at' => (string) $entry->created_at?->toDateTimeString(),
            ];
        }

        return $trail;
    }
}
