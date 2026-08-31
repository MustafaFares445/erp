<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\OperationType;
use App\Enums\TransferDiscrepancyDisposition;
use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\User;
use App\Services\Inventory\InventoryOperationService;
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
            $this->transitionAction('complete', 'complete', 'admin.inventory.operation.notifications.completed')
                ->visible(fn (InventoryOperation $record): bool => $record->operation_type !== OperationType::InternalTransfer
                    && (auth()->user()?->can('complete', $record) ?? false)),
            $this->transitionAction('cancel', 'cancel', 'admin.inventory.operation.notifications.canceled'),
        ];
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
            ->label(Str::headline($ability))
            ->visible(fn (InventoryOperation $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->authorize(fn (InventoryOperation $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->action(function (InventoryOperation $record) use ($method, $notification): void {
                $actor = auth()->user();

                // @codeCoverageIgnoreStart
                // Unreachable in practice: this action's `authorize()` closure already requires
                // an authenticated user able to perform the ability, so the guard exists only to
                // narrow `auth()->user()`'s nullable, generic Authenticatable type for static
                // analysis.
                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated inventory operation actor is required.');
                }

                // @codeCoverageIgnoreEnd

                $service = app(InventoryOperationService::class);

                $this->runInventoryOperation(
                    fn (): InventoryOperation => match ($method) {
                        'ready' => $service->markReady($record, $actor),
                        'dispatch' => $service->dispatch($record, $actor),
                        'complete' => $service->complete($record, $actor),
                        'cancel' => $service->cancel($record, $actor, 'Canceled from the inventory operation screen.'),
                        default => throw new LogicException(sprintf('Unknown inventory operation transition [%s].', $method)),
                    },
                    $notification,
                );
            });
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
