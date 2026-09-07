<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\TaxRecognitionEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment')
                ->schema([
                    TextEntry::make('payment_number'),
                    TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                    TextEntry::make('paymentMethod.name')->label(__('admin.sales.fields.payment_method')),
                    TextEntry::make('amount')->money(),
                    TextEntry::make('payment_date')->date(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('external_reference')->placeholder('—'),
                    TextEntry::make('posted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('reversed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
                ])
                ->columns(3),
            Section::make('Allocation and accounting')
                ->schema([
                    TextEntry::make('allocated_amount')
                        ->label('Allocated amount')
                        ->state(fn (Payment $record): string => number_format(
                            (float) $record->allocations()->sum('amount'),
                            2,
                            '.',
                            '',
                        )),
                    TextEntry::make('unallocated_amount')
                        ->label('Customer deposit')
                        ->state(fn (Payment $record): string => number_format(
                            max(0.0, (float) $record->amount - (float) $record->allocations()->sum('amount')),
                            2,
                            '.',
                            '',
                        )),
                    TextEntry::make('allocations_summary')
                        ->label('Invoice allocations')
                        ->state(fn (Payment $record): string => self::allocationSummary($record))
                        ->columnSpanFull(),
                    TextEntry::make('tax_summary')
                        ->label('Tax recognition')
                        ->state(fn (Payment $record): string => self::taxSummary($record))
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    private static function allocationSummary(Payment $payment): string
    {
        return $payment->allocations()
            ->with('invoice:id,invoice_number')
            ->orderBy('invoice_id')
            ->get()
            ->map(static fn (PaymentAllocation $allocation): string => sprintf(
                '%s: %.2f',
                $allocation->invoice?->invoice_number ?? (string) $allocation->invoice_id,
                (float) $allocation->amount,
            ))
            ->implode(' · ') ?: 'No invoice allocations — the whole payment is a customer deposit.';
    }

    private static function taxSummary(Payment $payment): string
    {
        return $payment->taxRecognitionEntries()
            ->orderBy('invoice_id')
            ->get()
            ->map(static fn (TaxRecognitionEntry $entry): string => sprintf(
                'Invoice %s: %.2f',
                (string) $entry->invoice_id,
                (float) $entry->recognised_tax_amount,
            ))
            ->implode(' · ') ?: 'No tax recognized by this payment.';
    }
}
