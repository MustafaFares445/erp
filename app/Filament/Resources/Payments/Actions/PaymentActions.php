<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Actions;

use App\Filament\Concerns\InteractsWithSalesServices;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class PaymentActions
{
    use InteractsWithSalesServices;

    public static function post(): Action
    {
        return Action::make('post_payment')
            ->label(__('admin.sales.payment_ui.post_action'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalHeading(__('admin.sales.payment_ui.post_heading'))
            ->modalDescription(__('admin.sales.payment_ui.post_description'))
            ->schema([
                Repeater::make('allocations')
                    ->label('Invoice allocations')
                    ->default(fn (Payment $record): array => self::defaultAllocation($record))
                    ->schema([
                        Select::make('invoice_id')
                            ->label('Invoice')
                            ->options(fn (Payment $record): array => self::invoiceOptions($record))
                            ->searchable()
                            ->required(),
                        TextInput::make('amount')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->required(),
                    ])
                    ->columns(2)
                    ->reorderable(false),
            ])
            ->visible(fn (Payment $record): bool => ! $record->isPosted() && self::can('post', $record))
            ->authorize(fn (Payment $record): bool => self::can('post', $record))
            ->action(function (Payment $record, array $data): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                $allocations = self::allocationsFrom($data['allocations'] ?? null);

                self::runSalesOperation(
                    fn (): Payment => app(PaymentService::class)->post($actor, $record, $allocations),
                );

                Notification::make()->success()->title(__('admin.sales.payment_ui.posted_notification'))->send();
            });
    }

    public static function reverse(): Action
    {
        return Action::make('reverse_payment')
            ->label(__('admin.sales.payment_ui.reverse_action'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('admin.sales.payment_ui.reverse_description'))
            ->visible(fn (Payment $record): bool => $record->isPosted() && ! $record->isReversed() && self::can('reverse', $record))
            ->authorize(fn (Payment $record): bool => self::can('reverse', $record))
            ->action(function (Payment $record): void {
                $actor = self::salesActor();

                if (! $actor instanceof User) {
                    return;
                }

                self::runSalesOperation(
                    fn (): Payment => app(PaymentService::class)->reverse($actor, $record),
                );

                Notification::make()->success()->title(__('admin.sales.payment_ui.reversed_notification'))->send();
            });
    }

    /** @return array<int, string> */
    private static function invoiceOptions(Payment $payment): array
    {
        return Invoice::query()
            ->where('customer_id', $payment->customer_id)
            ->whereNotNull('issued_at')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->outstandingAmount() > 0.00001)
            ->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->id => sprintf(
                    '%s — outstanding %.2f',
                    $invoice->invoice_number,
                    $invoice->outstandingAmount(),
                ),
            ])
            ->all();
    }

    /** @return list<array{invoice_id:int,amount:float}> */
    private static function defaultAllocation(Payment $payment): array
    {
        $invoiceId = request()->integer('invoice_id');

        if ($invoiceId <= 0) {
            return [];
        }

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->whereKey($invoiceId)
            ->where('customer_id', $payment->customer_id)
            ->whereNotNull('issued_at')
            ->first();

        if (! $invoice instanceof Invoice || $invoice->outstandingAmount() <= 0.00001) {
            return [];
        }

        return [[
            'invoice_id' => $invoice->id,
            'amount' => min((float) $payment->amount, $invoice->outstandingAmount()),
        ]];
    }

    /**
     * @return list<array{invoice_id:int,amount:float}>
     */
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
                'invoice_id' => self::integerFrom($row['invoice_id'] ?? null),
                'amount' => self::floatFrom($row['amount'] ?? null),
            ];
        }

        return $allocations;
    }

    private static function can(string $ability, Payment $payment): bool
    {
        return self::salesActor()?->can($ability, $payment) ?? false;
    }
}
