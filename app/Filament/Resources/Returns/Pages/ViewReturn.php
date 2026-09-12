<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Pages;

use App\Enums\BillStatus;
use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\InventoryReturnType;
use App\Enums\SupplierReturnExpectedOutcome;
use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\SupplierDebitNotes\SupplierDebitNoteResource;
use App\Models\Bill;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\Order;
use App\Models\User;
use App\Services\Inventory\InventoryReturnService;
use App\Services\Purchasing\SupplierDebitNoteService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewReturn extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = ReturnResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('selectSupplierOutcome')
                ->label('Set supplier outcome')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->visible(fn (InventoryReturn $record): bool => $record->isDraft()
                    && $record->return_type === InventoryReturnType::Supplier
                    && (auth()->user()?->can('update', $record) ?? false))
                ->schema([
                    Select::make('expected_outcome')
                        ->label('Expected supplier outcome')
                        ->options(SupplierReturnExpectedOutcome::options())
                        ->default(fn (InventoryReturn $record): ?string => $record->expected_outcome?->value)
                        ->required(),
                ])
                ->action(function (InventoryReturn $record, array $data): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated supplier-return actor is required.');
                    }

                    $outcome = SupplierReturnExpectedOutcome::from((string) $data['expected_outcome']);
                    app(SupplierDebitNoteService::class)->setExpectedOutcome($actor, $record, $outcome);
                    $record->refresh();
                    Notification::make()->success()->title('Supplier return outcome saved')->send();
                }),
            Action::make('createSupplierDebitNote')
                ->label('Create supplier debit note')
                ->icon('heroicon-o-document-minus')
                ->visible(fn (InventoryReturn $record): bool => $record->isPosted()
                    && $record->return_type === InventoryReturnType::Supplier
                    && $record->expected_outcome?->requiresFinancialCredit() === true
                    && ! $record->supplierDebitNote()->exists()
                    && SupplierDebitNoteResource::canCreate())
                ->schema([
                    Select::make('bill_id')
                        ->label('Supplier bill')
                        ->options(function (InventoryReturn $record): array {
                            return Bill::query()
                                ->where('supplier_id', $record->supplier_id)
                                ->when(
                                    is_int($record->original_purchase_order_id),
                                    fn ($query) => $query->where('purchase_order_id', $record->original_purchase_order_id),
                                )
                                ->whereIn('status', [
                                    BillStatus::Approved->value,
                                    BillStatus::PartiallyPaid->value,
                                    BillStatus::Paid->value,
                                ])
                                ->orderByDesc('bill_date')
                                ->pluck('bill_number', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->required(),
                    Textarea::make('notes')->maxLength(2_000),
                ])
                ->action(function (InventoryReturn $record, array $data): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new LogicException('An authenticated supplier-debit-note actor is required.');
                    }

                    $bill = Bill::query()->findOrFail((int) $data['bill_id']);
                    $notes = is_string($data['notes'] ?? null) ? mb_trim($data['notes']) : null;
                    $note = app(SupplierDebitNoteService::class)->createForReturn(
                        $actor,
                        $record,
                        $bill,
                        $notes === '' ? null : $notes,
                    );

                    $this->redirect(SupplierDebitNoteResource::getUrl('view', ['record' => $note]));
                }),
            Action::make('createCreditNote')
                ->label(__('admin.inventory.return.actions.create_credit_note'))
                ->icon('heroicon-o-document-plus')
                ->visible(fn (InventoryReturn $record): bool => $record->isPosted()
                    && $record->return_type === InventoryReturnType::Customer
                    && $record->credit_note_required
                    && CreditNoteResource::canCreate()
                    && $this->sourceInvoice($record) instanceof Invoice)
                ->url(function (InventoryReturn $record): string {
                    $invoice = $this->sourceInvoice($record);

                    if (! $invoice instanceof Invoice) {
                        return CreditNoteResource::getUrl('index');
                    }

                    return CreditNoteResource::getUrl('create', [
                        'customer_id' => $record->customer_id,
                        'invoice_id' => $invoice->getKey(),
                        'inventory_return_id' => $record->getKey(),
                        'reason_category' => CreditNoteReason::SalesReturn->value,
                        'stock_consequence' => CreditNoteStockConsequence::GoodsReturned->value,
                        'reason' => $record->reason ?? 'Customer goods return',
                    ]);
                }),
            Action::make('markReady')
                ->label(__('admin.inventory.return.actions.mark_ready'))
                ->color('warning')
                ->visible(fn (InventoryReturn $record): bool => $record->isDraft()
                    && (auth()->user()?->can('markReady', $record) ?? false))
                ->authorize(fn (InventoryReturn $record): bool => auth()->user()?->can('markReady', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (InventoryReturn $record) => $this->runReturnAction(
                    $record,
                    fn (InventoryReturnService $service, User $actor): InventoryReturn => $service->markReady($record, $actor),
                    'admin.inventory.return.notifications.ready',
                )),
            Action::make('post')
                ->label(__('admin.inventory.return.actions.post'))
                ->color('success')
                ->visible(fn (InventoryReturn $record): bool => $record->isReady()
                    && (auth()->user()?->can('post', $record) ?? false))
                ->authorize(fn (InventoryReturn $record): bool => auth()->user()?->can('post', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (InventoryReturn $record) => $this->runReturnAction(
                    $record,
                    fn (InventoryReturnService $service, User $actor): InventoryReturn => $service->post($record, $actor),
                    'admin.inventory.return.notifications.posted',
                )),
            Action::make('cancel')
                ->label(__('admin.inventory.return.actions.cancel'))
                ->color('danger')
                ->visible(fn (InventoryReturn $record): bool => ! $record->isTerminal()
                    && (auth()->user()?->can('cancel', $record) ?? false))
                ->authorize(fn (InventoryReturn $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                ->schema([
                    Textarea::make('reason')
                        ->label(__('admin.inventory.return.reason'))
                        ->maxLength(2_000),
                ])
                ->requiresConfirmation()
                ->action(function (InventoryReturn $record, array $data): void {
                    $reason = is_string($data['reason'] ?? null) ? mb_trim($data['reason']) : null;

                    $this->runReturnAction(
                        $record,
                        fn (InventoryReturnService $service, User $actor): InventoryReturn => $service->cancel(
                            $record,
                            $actor,
                            $reason === '' ? null : $reason,
                        ),
                        'admin.inventory.return.notifications.cancelled',
                    );
                }),
        ];
    }

    private function sourceInvoice(InventoryReturn $return): ?Invoice
    {
        $operationId = $return->original_inventory_operation_id;

        if (! is_int($operationId)) {
            return null;
        }

        $linkedInvoice = InvoiceDeliveryLink::query()
            ->where('inventory_operation_id', $operationId)
            ->with('invoice')
            ->first()?->invoice;

        if ($linkedInvoice instanceof Invoice
            && (int) $linkedInvoice->customer_id === (int) $return->customer_id
            && $linkedInvoice->issued_at !== null) {
            return $linkedInvoice;
        }

        $operation = $return->originalOperation;

        if (
            $operation === null
            || $operation->source_document_type !== Order::class
            || ! is_int($operation->source_document_id)
        ) {
            return null;
        }

        return Invoice::query()
            ->where('order_id', $operation->source_document_id)
            ->where('customer_id', $return->customer_id)
            ->whereNotNull('issued_at')
            ->latest('issued_at')
            ->first();
    }

    private function runReturnAction(
        InventoryReturn $record,
        callable $operation,
        string $notification,
    ): void {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory return actor is required.');
        }

        $this->runInventoryOperation(
            function () use ($record, $operation, $actor): void {
                $updated = $operation(app(InventoryReturnService::class), $actor);

                if ($updated instanceof InventoryReturn) {
                    $record->refresh();
                }
            },
            $notification,
        );
    }
}
