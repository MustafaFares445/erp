<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\OperationType;
use App\Enums\ShipmentStatus;
use App\Enums\TransferDiscrepancyDisposition;
use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryCorrections\InventoryCorrectionResource;
use App\Filament\Resources\InventoryOperations\Actions\InventoryOperationActions;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReturnService;
use App\Services\Logistics\OutboundDispatchService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use LogicException;

final class ViewInventoryOperation extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = InventoryOperationResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
            $this->transitionAction('markReady', 'ready', 'admin.inventory.operation.notifications.ready'),
            $this->transitionAction('dispatch', 'dispatch', 'admin.inventory.operation.notifications.dispatched'),
            $this->transferReceiptAction(),
            InventoryOperationActions::generatePackingList(),
            $this->transitionAction('complete', 'complete', 'admin.inventory.operation.notifications.completed')
                ->visible(fn (InventoryOperation $record): bool => match ($record->operation_type) {
                    OperationType::Receipt => auth()->user()?->can('complete', $record) ?? false,
                    OperationType::Delivery => ($record->shipment()
                        ->where('status', ShipmentStatus::Planned->value)
                        ->exists())
                        && (auth()->user()?->can('complete', $record) ?? false),
                    OperationType::InternalTransfer => false,
                }),
            $this->createReceiptCorrectionAction(),
            $this->createCustomerReturnAction(),
            $this->transitionAction('cancel', 'cancel', 'admin.inventory.operation.notifications.canceled'),
        ];
    }

    private function createReceiptCorrectionAction(): Action
    {
        return Action::make('createReceiptCorrection')
            ->label(__('admin.inventory.operation.actions.create_receipt_correction'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('gray')
            ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Receipt
                && $record->isDone()
                && InventoryCorrectionResource::canCreate())
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.inventory.correction.reason'))
                    ->required()
                    ->maxLength(2_000),
                Textarea::make('notes')
                    ->label(__('admin.inventory.correction.notes'))
                    ->maxLength(2_000),
            ])
            ->action(function (InventoryOperation $record, array $data): void {
                $actor = auth()->user();
                $reason = $data['reason'] ?? null;

                if (! $actor instanceof User || ! is_string($reason)) {
                    throw new LogicException('An authenticated actor and correction reason are required.');
                }

                $notes = is_string($data['notes'] ?? null) && mb_trim($data['notes']) !== ''
                    ? mb_trim($data['notes'])
                    : null;

                $correction = app(InventoryCorrectionService::class)->createReceiptCorrection(
                    $actor,
                    $record,
                    $reason,
                    $notes,
                );

                $this->redirect(InventoryCorrectionResource::getUrl('view', ['record' => $correction]));
            });
    }

    private function createCustomerReturnAction(): Action
    {
        return Action::make('createCustomerReturn')
            ->label(__('admin.inventory.operation.actions.create_customer_return'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (InventoryOperation $record): bool => $record->operation_type === OperationType::Delivery
                && $record->isDone()
                && ReturnResource::canCreate())
            ->schema([
                Select::make('warehouse_id')
                    ->label(__('admin.inventory.return.warehouse'))
                    ->options(fn (): array => Warehouse::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (InventoryOperation $record): ?int => $record->source_warehouse_id)
                    ->searchable()
                    ->preload()
                    ->required(),
                Textarea::make('reason')
                    ->label(__('admin.inventory.return.reason'))
                    ->maxLength(2_000),
                Textarea::make('notes')
                    ->label(__('admin.inventory.return.notes'))
                    ->maxLength(2_000),
            ])
            ->action(function (InventoryOperation $record, array $data): void {
                $actor = auth()->user();
                $warehouseId = $data['warehouse_id'] ?? null;

                if (! $actor instanceof User || ! is_numeric($warehouseId)) {
                    throw new LogicException('An authenticated actor and return warehouse are required.');
                }

                $return = app(InventoryReturnService::class)->createCustomerReturn(
                    $actor,
                    $record,
                    Warehouse::query()->findOrFail((int) $warehouseId),
                    $this->nullableString($data['reason'] ?? null),
                    $this->nullableString($data['notes'] ?? null),
                );

                $this->redirect(ReturnResource::getUrl('view', ['record' => $return]));
            });
    }

    private function transferReceiptAction(): Action
    {
        return Action::make('receiveTransfer')
            ->label(__('admin.inventory.operation.actions.receive_transfer'))
            ->visible(fn (InventoryOperation $record): bool => auth()->user()?->can('receiveTransfer', $record) ?? false)
            ->authorize(fn (InventoryOperation $record): bool => auth()->user()?->can('receiveTransfer', $record) ?? false)
            ->fillForm(fn (InventoryOperation $record): array => [
                'lines' => $record->lines()
                    ->with(['productVariant', 'transactionUnit'])
                    ->orderBy('id')
                    ->get()
                    ->filter(fn (InventoryOperationLine $line): bool => ! $line->discrepancy_disposition instanceof TransferDiscrepancyDisposition
                        && bccomp(
                            $this->numericDecimal($line->received_base_quantity, '0'),
                            $this->numericDecimal($line->dispatched_base_quantity, '0'),
                            6,
                        ) < 0)
                    ->map(fn (InventoryOperationLine $line): array => [
                        'operation_line_id' => $line->getKey(),
                        'product' => $line->productVariant->sku ?? (string) $line->product_variant_id,
                        'remaining' => $this->remainingTransactionQuantity($line),
                        'received_transaction_quantity' => $this->remainingTransactionQuantity($line),
                    ])
                    ->values()
                    ->all(),
            ])
            ->schema([
                Repeater::make('lines')
                    ->label(__('admin.inventory.operation.actions.receipt_lines'))
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->schema([
                        Hidden::make('operation_line_id'),
                        TextInput::make('product')->disabled()->dehydrated(false),
                        TextInput::make('remaining')->disabled()->dehydrated(false),
                        TextInput::make('received_transaction_quantity')
                            ->label(__('admin.inventory.operation.fields.received_quantity'))
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->step(0.000001),
                        Select::make('discrepancy_disposition')
                            ->label(__('admin.inventory.operation.fields.discrepancy_disposition'))
                            ->options(collect(TransferDiscrepancyDisposition::cases())
                                ->mapWithKeys(fn (TransferDiscrepancyDisposition $disposition): array => [$disposition->value => $disposition->name])
                                ->all()),
                        Textarea::make('discrepancy_reason')
                            ->label(__('admin.inventory.operation.fields.discrepancy_reason'))
                            ->maxLength(2_000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->action(function (InventoryOperation $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated inventory operation actor is required.');
                }

                $this->runInventoryOperation(
                    fn (): InventoryOperation => app(InventoryOperationService::class)->receiveTransfer(
                        $record,
                        $actor,
                        new TransferReceiptCommand($this->transferReceiptLines($data)),
                    ),
                    'admin.inventory.operation.notifications.transfer_received',
                );
            });
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<TransferReceiptLine>
     */
    private function transferReceiptLines(array $data): array
    {
        $formLines = $data['lines'] ?? null;

        if (! is_array($formLines)) {
            throw new DomainException('A transfer receipt must include its receipt lines.');
        }

        $receiptLines = [];

        foreach ($formLines as $formLine) {
            if (! is_array($formLine)) {
                throw new DomainException('A transfer receipt line is invalid.');
            }

            $operationLineId = $formLine['operation_line_id'] ?? null;
            $receivedTransactionQuantity = $formLine['received_transaction_quantity'] ?? null;
            $dispositionValue = $formLine['discrepancy_disposition'] ?? null;
            $reason = $formLine['discrepancy_reason'] ?? null;

            if ((! is_int($operationLineId) && (! is_string($operationLineId) || ! ctype_digit($operationLineId)))
                || (! is_string($dispositionValue) && $dispositionValue !== null)
                || (! is_string($reason) && $reason !== null)) {
                throw new DomainException('A transfer receipt line has invalid field values.');
            }

            $disposition = $dispositionValue === null || $dispositionValue === ''
                ? null
                : TransferDiscrepancyDisposition::tryFrom($dispositionValue);

            if ($dispositionValue !== null && $dispositionValue !== '' && $disposition === null) {
                throw new DomainException('A transfer receipt line has an invalid discrepancy disposition.');
            }

            $receiptLines[] = new TransferReceiptLine(
                operationLineId: (int) $operationLineId,
                receivedTransactionQuantity: $this->receiptTransactionQuantity($receivedTransactionQuantity),
                discrepancyDisposition: $disposition,
                discrepancyReason: $reason,
            );
        }

        return $receiptLines;
    }

    private function receiptTransactionQuantity(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        if (! is_float($value) || ! is_finite($value)) {
            throw new DomainException('A transfer receipt quantity must be a finite number.');
        }

        $quantity = number_format($value, 6, '.', '');

        if ((float) $quantity !== $value) {
            throw new DomainException('A transfer receipt quantity may have at most six decimal places.');
        }

        return $quantity;
    }

    private function transitionAction(string $ability, string $method, string $notification): Action
    {
        return Action::make($ability)
            ->label(fn (InventoryOperation $record): string => $this->transitionLabel($ability, $record))
            ->color(fn (): string => match ($ability) {
                'markReady' => 'warning',
                'dispatch', 'complete' => 'success',
                'cancel' => 'danger',
                default => 'gray',
            })
            ->visible(fn (InventoryOperation $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->authorize(fn (InventoryOperation $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->requiresConfirmation()
            ->modalDescription(fn (InventoryOperation $record): string => $this->transitionImpact($ability, $record))
            ->action(function (InventoryOperation $record) use ($method, $notification): void {
                /** @var User $actor */
                $actor = auth()->user();

                $service = app(InventoryOperationService::class);

                $this->runInventoryOperation(
                    fn (): InventoryOperation => match ($method) {
                        'ready' => $service->markReady($record, $actor),
                        'dispatch' => $service->dispatch($record, $actor),
                        'complete' => $record->operation_type === OperationType::Delivery
                            ? $this->dispatchCustomerDelivery($record, $actor)
                            : $service->complete($record, $actor),
                        'cancel' => $service->cancel($record, $actor, 'Canceled from the inventory operation screen.'),
                        default => throw new LogicException(sprintf('Unknown inventory operation transition [%s].', $method)),
                    },
                    $notification,
                );
            });
    }

    private function dispatchCustomerDelivery(InventoryOperation $delivery, User $actor): InventoryOperation
    {
        app(OutboundDispatchService::class)->dispatch($actor, $delivery);

        return $delivery->refresh();
    }

    private function transitionLabel(string $ability, InventoryOperation $operation): string
    {
        return match ($ability) {
            'markReady' => __('admin.inventory.operation.actions.mark_ready'),
            'dispatch' => __('admin.inventory.operation.actions.dispatch_transfer'),
            'complete' => $operation->operation_type === OperationType::Delivery
                ? __('admin.inventory.operation.actions.dispatch_delivery')
                : __('admin.inventory.operation.actions.complete_receipt'),
            'cancel' => __('admin.inventory.operation.actions.cancel'),
            default => Str::headline($ability),
        };
    }

    private function transitionImpact(string $ability, InventoryOperation $operation): string
    {
        return match ($ability) {
            'markReady' => match ($operation->operation_type) {
                OperationType::Receipt => __('admin.inventory.operation.workflow.draft_impact'),
                OperationType::Delivery => __('admin.inventory.operation.workflow.delivery_ready_impact'),
                OperationType::InternalTransfer => __('admin.inventory.operation.workflow.transfer_ready_impact'),
            },
            'dispatch' => __('admin.inventory.operation.workflow.transfer_ready_impact'),
            'complete' => $operation->operation_type === OperationType::Delivery
                ? __('admin.inventory.operation.workflow.delivery_ready_impact')
                : __('admin.inventory.operation.workflow.receipt_ready_impact'),
            'cancel' => __('admin.inventory.operation.workflow.canceled_impact'),
            default => __('admin.inventory.operation.confirm_preview_notice'),
        };
    }

    private function remainingTransactionQuantity(InventoryOperationLine $line): string
    {
        $dispatched = $this->numericDecimal($line->dispatched_base_quantity, '0');
        $received = $this->numericDecimal($line->received_base_quantity, '0');
        $factor = $this->numericDecimal($line->conversion_factor_snapshot, '1');

        if (bccomp($factor, '0', 6) <= 0) {
            return '0.000000';
        }

        return bcdiv(bcsub($dispatched, $received, 6), $factor, 6);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    /**
     * @param  numeric-string  $fallback
     * @return numeric-string
     */
    private function numericDecimal(mixed $value, string $fallback): string
    {
        if (! is_string($value) || ! is_numeric($value)) {
            return $fallback;
        }

        return bcadd($value, '0', 6);
    }
}
