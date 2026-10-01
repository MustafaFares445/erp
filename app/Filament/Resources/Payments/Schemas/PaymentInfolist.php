<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Schemas;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\TaxRecognitionEntry;
use App\Services\Settings\CurrencyCatalogService;
use App\Support\MoneyFormatter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

final class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            self::hero(),
            self::statusCallout(),
            Grid::make(12)->schema([
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 8])
                    ->schema([
                        self::paymentDetails(),
                        self::allocations(),
                        self::paymentSource(),
                        self::taxDetails(),
                        self::reversalDetails(),
                    ]),
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 4])
                    ->schema([
                        self::financialSummary(),
                        self::customerDeposit(),
                    ]),
            ]),
        ]);
    }

    private static function hero(): Section
    {
        return Section::make(fn (Payment $record): string => __('admin.sales.payment_ui.title', [
            'number' => $record->payment_number,
        ]))
            ->schema([
                TextEntry::make('customer.company_name')
                    ->label(__('admin.sales.fields.customer'))
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold),
                TextEntry::make('paymentMethod.name')
                    ->label(__('admin.sales.fields.payment_method')),
                TextEntry::make('source_label')
                    ->label(__('admin.sales.payment_ui.source'))
                    ->state(fn (Payment $record): string => $record->providerTransaction?->provider->label()
                        ?? __('admin.sales.payment_ui.manual')),
                TextEntry::make('amount')
                    ->label(__('admin.sales.payment_ui.received'))
                    ->money(fn (Payment $record): string => $record->currency)
                    ->weight(FontWeight::Bold)
                    ->size(TextSize::Large),
                TextEntry::make('status')
                    ->label(__('admin.sales.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => $state->color()),
            ])
            ->columns(['default' => 1, 'md' => 2, 'lg' => 5]);
    }

    private static function statusCallout(): Callout
    {
        return Callout::make(fn (Payment $record): string => self::bannerMeta($record)['heading'])
            ->description(fn (Payment $record): string => self::bannerMeta($record)['description'])
            ->status(fn (Payment $record): string => self::bannerMeta($record)['status']);
    }

    /** @return array{status: string, heading: string, description: string} */
    private static function bannerMeta(Payment $payment): array
    {
        if ($payment->isReversed()) {
            return [
                'status' => 'warning',
                'heading' => __('admin.sales.payment_ui.reversed_heading'),
                'description' => __('admin.sales.payment_ui.reversed_description'),
            ];
        }

        if (! $payment->isPosted()) {
            return [
                'status' => 'info',
                'heading' => __('admin.sales.payment_ui.draft_heading'),
                'description' => __('admin.sales.payment_ui.draft_description'),
            ];
        }

        $applied = MoneyFormatter::format($payment->allocatedAmountMinor(), $payment->currency);
        $deposit = MoneyFormatter::format($payment->customerDepositMinor(), $payment->currency);

        if ($payment->customerDepositMinor() > 0) {
            return [
                'status' => 'success',
                'heading' => __('admin.sales.payment_ui.posted_heading'),
                'description' => __('admin.sales.payment_ui.posted_with_deposit', [
                    'applied' => $applied,
                    'deposit' => $deposit,
                ]),
            ];
        }

        return [
            'status' => 'success',
            'heading' => __('admin.sales.payment_ui.posted_heading'),
            'description' => __('admin.sales.payment_ui.posted_fully_applied', ['applied' => $applied]),
        ];
    }

    private static function paymentDetails(): Section
    {
        return Section::make(__('admin.sales.payment_ui.details'))
            ->schema([
                TextEntry::make('payment_date')->label(__('admin.sales.fields.payment_date'))->date(),
                TextEntry::make('external_reference')
                    ->label(__('admin.sales.fields.external_reference'))
                    ->placeholder(__('—')),
                TextEntry::make('posted_at')->label(__('admin.sales.payment_ui.posted_at'))->dateTime()->placeholder(__('—')),
                TextEntry::make('notes')->label(__('admin.sales.fields.notes'))->columnSpanFull()->placeholder(__('—')),
            ])
            ->columns(2);
    }

    private static function allocations(): Section
    {
        return Section::make(__('admin.sales.payment_ui.applied_to_invoices'))
            ->description(fn (Payment $record): string => $record->allocations->isEmpty()
                ? ($record->isPosted()
                    ? __('admin.sales.payment_ui.no_allocations_deposit')
                    : __('admin.sales.payment_ui.no_allocations_draft'))
                : __('admin.sales.payment_ui.current_invoice_balances'))
            ->schema([
                RepeatableEntry::make('allocations')
                    ->label('')
                    ->table([
                        TableColumn::make(__('admin.sales.fields.invoice_number')),
                        TableColumn::make(__('admin.sales.payment_ui.applied_amount')),
                        TableColumn::make(__('admin.sales.payment_ui.current_invoice_outstanding')),
                    ])
                    ->schema([
                        TextEntry::make('invoice.invoice_number')
                            ->label(__('admin.sales.fields.invoice_number'))
                            ->url(fn (PaymentAllocation $record): string => InvoiceResource::getUrl('view', [
                                'record' => $record->invoice_id,
                            ])),
                        TextEntry::make('amount')
                            ->label(__('admin.sales.payment_ui.applied_amount'))
                            ->money(fn (PaymentAllocation $record): string => $record->payment->currency ?? app(CurrencyCatalogService::class)->defaultCode()),
                        TextEntry::make('current_outstanding')
                            ->label(__('admin.sales.payment_ui.current_invoice_outstanding'))
                            ->state(fn (PaymentAllocation $record): float => $record->invoice?->outstandingAmount() ?? 0.0)
                            ->money(fn (PaymentAllocation $record): string => $record->payment->currency ?? app(CurrencyCatalogService::class)->defaultCode()),
                    ])
                    ->visible(fn (Payment $record): bool => $record->allocations->isNotEmpty()),
            ]);
    }

    private static function paymentSource(): Section
    {
        return Section::make(__('admin.sales.payment_ui.source_details'))
            ->description(__('admin.sales.payment_ui.source_description'))
            ->schema([
                TextEntry::make('providerTransaction.provider')
                    ->label(__('admin.sales.payment_ui.provider'))
                    ->formatStateUsing(fn (?PaymentProvider $state): string => $state?->label() ?? '—'),
                TextEntry::make('providerTransaction.purpose_type')
                    ->label(__('admin.sales.payment_ui.purpose'))
                    ->state(fn (Payment $record): string => $record->providerTransaction?->purposeLabel() ?? '—'),
                TextEntry::make('provider_status')
                    ->label(__('admin.sales.payment_ui.provider_status'))
                    ->state(fn (Payment $record): string => $record->providerTransaction?->status->label() ?? '—'),
                TextEntry::make('settlement_status')
                    ->label(__('admin.sales.payment_ui.erp_settlement'))
                    ->state(fn (Payment $record): string => $record->providerTransaction?->settlementState()->label() ?? '—'),
                TextEntry::make('providerTransaction.checkout_session_id')
                    ->label(__('admin.sales.payment_ui.checkout_session'))
                    ->placeholder(__('—'))
                    ->visible(fn (Payment $record): bool => filled($record->providerTransaction?->checkout_session_id)),
                TextEntry::make('providerTransaction.payment_intent_id')
                    ->label(__('admin.sales.payment_ui.payment_intent'))
                    ->placeholder(__('—'))
                    ->visible(fn (Payment $record): bool => filled($record->providerTransaction?->payment_intent_id)),
            ])
            ->columns(2)
            ->visible(fn (Payment $record): bool => $record->providerTransaction !== null);
    }

    private static function taxDetails(): Section
    {
        return Section::make(__('admin.sales.payment_ui.accounting_tax_details'))
            ->description(__('admin.sales.payment_ui.tax_recognition_description'))
            ->collapsible()
            ->collapsed()
            ->schema([
                TextEntry::make('recognized_tax_total')
                    ->label(__('admin.sales.payment_ui.total_tax_recognized'))
                    ->state(fn (Payment $record): float => self::recognizedTaxTotal($record))
                    ->money(fn (Payment $record): string => $record->currency)
                    ->weight(FontWeight::Bold),
                RepeatableEntry::make('taxRecognitionEntries')
                    ->label('')
                    ->table([
                        TableColumn::make(__('admin.sales.fields.invoice_number')),
                        TableColumn::make(__('admin.sales.payment_ui.tax_recognized')),
                    ])
                    ->schema([
                        TextEntry::make('invoice.invoice_number')
                            ->label(__('admin.sales.fields.invoice_number'))
                            ->placeholder(__('admin.sales.payment_ui.invoice_unavailable')),
                        TextEntry::make('recognised_tax_amount')
                            ->label(__('admin.sales.payment_ui.tax_recognized'))
                            ->money(fn (TaxRecognitionEntry $record): string => $record->payment->currency ?? app(CurrencyCatalogService::class)->defaultCode()),
                    ])
                    ->visible(fn (Payment $record): bool => $record->taxRecognitionEntries->isNotEmpty()),
                TextEntry::make('no_tax_recognition')
                    ->state(__('admin.sales.payment_ui.no_tax_recognition'))
                    ->visible(fn (Payment $record): bool => $record->taxRecognitionEntries->isEmpty()),
            ]);
    }

    private static function recognizedTaxTotal(Payment $payment): float
    {
        $total = 0.0;

        foreach ($payment->taxRecognitionEntries as $entry) {
            if (is_numeric($entry->recognised_tax_amount)) {
                $total += (float) $entry->recognised_tax_amount;
            }
        }

        return $total;
    }

    private static function customerDeposit(): Section
    {
        return Section::make(__('admin.sales.payment_ui.customer_deposit'))
            ->schema([
                TextEntry::make('customer_deposit_available')
                    ->label(__('admin.sales.payment_ui.available_amount'))
                    ->state(fn (Payment $record): float => $record->customerDepositMinor() / 100)
                    ->money(fn (Payment $record): string => $record->currency)
                    ->weight(FontWeight::Bold),
                TextEntry::make('deposit_explanation')
                    ->state(__('admin.sales.payment_ui.deposit_explanation'))
                    ->visible(fn (Payment $record): bool => $record->customerDepositMinor() > 0),
                TextEntry::make('deposit_empty_state')
                    ->state(__('admin.sales.payment_ui.no_deposit_remains'))
                    ->visible(fn (Payment $record): bool => $record->customerDepositMinor() === 0),
            ]);
    }

    private static function financialSummary(): Section
    {
        return Section::make(__('admin.sales.payment_ui.summary'))
            ->schema([
                TextEntry::make('received_summary')
                    ->label(__('admin.sales.payment_ui.received'))
                    ->state(fn (Payment $record): float => (float) $record->amount)
                    ->money(fn (Payment $record): string => $record->currency)
                    ->weight(FontWeight::Bold),
                TextEntry::make('applied_summary')
                    ->label(__('admin.sales.payment_ui.applied_amount'))
                    ->state(fn (Payment $record): float => $record->allocatedAmountMinor() / 100)
                    ->money(fn (Payment $record): string => $record->currency),
                TextEntry::make('deposit_summary')
                    ->label(__('admin.sales.payment_ui.customer_deposit'))
                    ->state(fn (Payment $record): float => $record->customerDepositMinor() / 100)
                    ->money(fn (Payment $record): string => $record->currency),
                TextEntry::make('payment_method_summary')
                    ->label(__('admin.sales.fields.payment_method'))
                    ->state(fn (Payment $record): string => $record->paymentMethod->name ?? '—'),
            ]);
    }

    private static function reversalDetails(): Section
    {
        return Section::make(__('admin.sales.payment_ui.reversal_details'))
            ->description(__('admin.sales.payment_ui.reversed_description'))
            ->schema([
                TextEntry::make('reversed_at')->label(__('admin.sales.payment_ui.reversed_at'))->dateTime(),
                TextEntry::make('reversedBy.name')->label(__('admin.sales.payment_ui.reversed_by'))->placeholder(__('—')),
            ])
            ->columns(2)
            ->visible(fn (Payment $record): bool => $record->isReversed());
    }
}
