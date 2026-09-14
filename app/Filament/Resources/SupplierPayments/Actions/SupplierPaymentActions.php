<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierPayments\Actions;

use App\Enums\BillStatus;
use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Models\Bill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\AccountingDocumentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class SupplierPaymentActions
{
    use InteractsWithAccountingServices;

    public static function pay(): Action
    {
        return Action::make('pay_supplier_payment')
            ->label('Pay supplier payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalHeading('Allocate and post supplier payment')
            ->modalDescription('Allocate the full payment to open bills. Posting debits Accounts Payable, credits the selected payment account, and updates each bill balance and status.')
            ->schema([
                Repeater::make('allocations')
                    ->label('Bill allocations')
                    ->default(fn (SupplierPayment $record): array => self::defaultAllocation($record))
                    ->schema([
                        Select::make('bill_id')
                            ->label('Bill')
                            ->options(fn (SupplierPayment $record): array => self::billOptions($record))
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')
                            ->label('Allocated amount')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->required(),
                    ])
                    ->columns(2)
                    ->reorderable(false)
                    ->minItems(1)
                    ->required(),
            ])
            ->visible(fn (SupplierPayment $record): bool => $record->isDraft() && self::can('pay', $record))
            ->authorize(fn (SupplierPayment $record): bool => self::can('pay', $record))
            ->action(function (SupplierPayment $record, array $data): void {
                $actor = self::accountingActor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runAccountingOperation(
                    fn (): SupplierPayment => app(AccountingDocumentService::class)->paySupplierPayment(
                        $actor,
                        $record,
                        self::allocationsFrom($data['allocations'] ?? null),
                    ),
                );

                Notification::make()->success()->title('Supplier payment posted. Bill balances were updated.')->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel_supplier_payment')
            ->label('Cancel draft payment')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This cancels the draft only. No payable balance or journal entry will be changed.')
            ->visible(fn (SupplierPayment $record): bool => $record->isDraft() && self::can('update', $record))
            ->authorize(fn (SupplierPayment $record): bool => self::can('update', $record))
            ->action(function (SupplierPayment $record): void {
                $actor = self::accountingActor();
                if (! $actor instanceof User) {
                    return;
                }

                self::runAccountingOperation(
                    fn (): SupplierPayment => app(AccountingDocumentService::class)->cancelSupplierPayment($actor, $record),
                );

                Notification::make()->success()->title('Supplier payment cancelled.')->send();
            });
    }

    /** @return array<int, string> */
    private static function billOptions(SupplierPayment $payment): array
    {
        return Bill::query()
            ->where('resolved_supplier_id', $payment->supplier_id)
            ->whereIn('status', [BillStatus::Approved->value, BillStatus::PartiallyPaid->value])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Bill $bill): bool => $bill->outstandingAmount() > 0.00001)
            ->mapWithKeys(fn (Bill $bill): array => [
                $bill->id => sprintf('%s — outstanding %.2f', $bill->bill_number, $bill->outstandingAmount()),
            ])
            ->all();
    }

    /** @return list<array{bill_id:int,amount:float}> */
    private static function defaultAllocation(SupplierPayment $payment): array
    {
        $billId = request()->integer('bill_id');
        if ($billId <= 0) {
            return [];
        }

        $bill = Bill::query()
            ->whereKey($billId)
            ->where('resolved_supplier_id', $payment->supplier_id)
            ->whereIn('status', [BillStatus::Approved->value, BillStatus::PartiallyPaid->value])
            ->first();

        if (! $bill instanceof Bill || $bill->outstandingAmount() <= 0.00001) {
            return [];
        }

        return [[
            'bill_id' => (int) $bill->id,
            'amount' => min((float) $payment->amount, $bill->outstandingAmount()),
        ]];
    }

    /** @return list<array{bill_id:int,amount:float}> */
    private static function allocationsFrom(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allocations = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $allocations[] = [
                'bill_id' => self::integerFrom($row['bill_id'] ?? null),
                'amount' => is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0.0,
            ];
        }

        return $allocations;
    }

    private static function can(string $ability, SupplierPayment $payment): bool
    {
        return self::accountingActor()?->can($ability, $payment) ?? false;
    }
}
