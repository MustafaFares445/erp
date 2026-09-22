<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions\Schemas;

use App\Enums\PaymentProvider;
use App\Enums\PaymentTransactionStatus;
use App\Models\PaymentTransaction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PaymentTransactionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Provider')
                ->columns(3)
                ->schema([
                    TextEntry::make('provider')->label('Provider')->formatStateUsing(fn (PaymentProvider $state): string => $state->value),
                    TextEntry::make('checkout_session_id')->label('Checkout session')->placeholder('—'),
                    TextEntry::make('payment_intent_id')->label('PaymentIntent')->placeholder('—'),
                    TextEntry::make('provider_charge_id')->label('Charge')->placeholder('—'),
                    TextEntry::make('status')
                        ->label('Provider status')
                        ->badge()
                        ->formatStateUsing(fn (PaymentTransactionStatus $state): string => $state->label())
                        ->color(fn (PaymentTransactionStatus $state): string => $state->color()),
                    TextEntry::make('last_provider_event_id')->label('Last event')->placeholder('—'),
                    TextEntry::make('failure_code')->label('Failure code')->placeholder('—'),
                    TextEntry::make('failure_message')->label('Failure message')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('Amount & customer')
                ->columns(3)
                ->schema([
                    TextEntry::make('customer.company_name')->label('Customer'),
                    TextEntry::make('purpose_type')->label('Purpose type')->formatStateUsing(fn (string $state): string => class_basename($state)),
                    TextEntry::make('purpose_id')->label('Purpose ID'),
                    TextEntry::make('amount')->label('Amount')->state(fn (PaymentTransaction $record): float => $record->amount())->money(fn (PaymentTransaction $record): string => $record->currency),
                    TextEntry::make('idempotency_key')->label('Idempotency key'),
                ]),
            Section::make('ERP settlement')
                ->columns(3)
                ->schema([
                    TextEntry::make('payment.payment_number')->label('ERP payment')->placeholder('Not settled yet'),
                    TextEntry::make('payment.status')->label('ERP payment status')->placeholder('—'),
                    TextEntry::make('payment.allocations_summary')
                        ->label('Allocations / deposit remainder')
                        ->state(function (PaymentTransaction $record): string {
                            $payment = $record->payment;

                            if ($payment === null) {
                                return '—';
                            }

                            $allocated = (float) $payment->allocations()->sum('amount');
                            $remainder = round((float) $payment->amount - $allocated, 2);

                            return $remainder > 0.0
                                ? sprintf('%.2f allocated, %.2f as customer deposit', $allocated, $remainder)
                                : sprintf('%.2f allocated', $allocated);
                        }),
                ]),
            Section::make('Timestamps')
                ->columns(4)
                ->schema([
                    TextEntry::make('succeeded_at')->dateTime()->placeholder('—'),
                    TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
                    TextEntry::make('refunded_at')->dateTime()->placeholder('—'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
        ]);
    }
}
