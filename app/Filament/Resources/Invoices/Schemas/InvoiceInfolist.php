<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Enums\ResolvedPriceSource;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\Sales\InvoiceBalanceService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invoice')
                ->columns(3)
                ->schema([
                    TextEntry::make('invoice_number')->label(__('admin.sales.fields.invoice_number')),
                    TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                        ->color(fn (InvoiceStatus $state): string => $state->color()),
                    TextEntry::make('payment_status')
                        ->label('Payment status')
                        ->badge()
                        ->state(fn (Invoice $record): string => app(InvoiceBalanceService::class)->status($record))
                        ->color(fn (string $state): string => match ($state) {
                            'paid', 'credited' => 'success',
                            'partially_paid' => 'warning',
                            default => 'gray',
                        }),
                    TextEntry::make('invoice_date')->date(),
                    TextEntry::make('due_date')->date()->placeholder('—'),
                    TextEntry::make('order.order_number')->label('Order')->placeholder('—'),
                    TextEntry::make('subtotal')->money(),
                    TextEntry::make('tax_total')->money(),
                    TextEntry::make('total_amount')->money()->weight('bold'),
                    TextEntry::make('amount_paid')->label('Paid')->money()->color('success'),
                    TextEntry::make('credited_amount')->label('Credited')->money(),
                    TextEntry::make('outstanding_amount')
                        ->label('Outstanding')
                        ->state(fn (Invoice $record): float => $record->outstandingAmount())
                        ->money()
                        ->weight('bold')
                        ->color(fn (Invoice $record): string => $record->outstandingAmount() > 0.0 ? 'danger' : 'success'),
                    TextEntry::make('electronic_document')
                        ->label('Electronic document')
                        ->state(fn (Invoice $record): string => $record->getFirstMedia('invoice-pdf') instanceof Media ? 'Available' : 'Not generated yet')
                        ->badge()
                        ->color(fn (Invoice $record): string => $record->getFirstMedia('invoice-pdf') instanceof Media ? 'success' : 'gray'),
                    TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                ]),
            Section::make('Payment allocations')
                ->description('Every posted payment/deposit amount applied to this invoice.')
                ->schema([
                    RepeatableEntry::make('paymentAllocations')
                        ->label('')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('payment.payment_number')->label('Payment'),
                            TextEntry::make('payment.payment_date')->label('Date')->date(),
                            TextEntry::make('amount')->label('Allocated amount')->money(),
                        ])
                        ->placeholder('No payments have been allocated to this invoice yet.'),
                ])
                ->collapsed(fn (Invoice $record): bool => $record->paymentAllocations->isEmpty()),
            Section::make('Reconciliation warning')
                ->description('Automatic customer-deposit application failed after this invoice was issued. The invoice itself is unaffected — use "Retry deposit application" once the underlying issue is fixed.')
                ->visible(fn (Invoice $record): bool => $record->depositApplicationIssues()->whereNull('resolved_at')->exists())
                ->schema([
                    RepeatableEntry::make('depositApplicationIssues')
                        ->label('')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('occurred_at')->label('Occurred')->dateTime(),
                            TextEntry::make('error_message')->label('Error')->columnSpanFull(),
                        ]),
                ]),
            Section::make('Line price evidence')
                ->description('Frozen at document creation; later pricing-policy changes do not rewrite these values.')
                ->schema([
                    RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                        TextEntry::make('productVariant.sku')->label('Product')->placeholder('Service'),
                        TextEntry::make('description')->label('Description'),
                        TextEntry::make('quantity')->label('Quantity'),
                        TextEntry::make('unit_price')->label('Actual price')->money(),
                        TextEntry::make('resolved_price_source')
                            ->label('Price source')
                            ->formatStateUsing(static fn (?ResolvedPriceSource $state): ?string => $state?->value)
                            ->placeholder('Service / legacy'),
                        TextEntry::make('resolvedPriceTier.name')->label('Pricing tier')->placeholder('—'),
                        TextEntry::make('list_price_minor')
                            ->label('List price snapshot')
                            ->state(static fn (InvoiceLine $record): ?float => $record->list_price_minor === null ? null : $record->list_price_minor / 100)
                            ->money()
                            ->placeholder('—'),
                        TextEntry::make('floor_price_minor')
                            ->label('Floor snapshot')
                            ->state(static fn (InvoiceLine $record): ?float => $record->floor_price_minor === null ? null : $record->floor_price_minor / 100)
                            ->money()
                            ->placeholder('—'),
                        TextEntry::make('priceFloorOverride.approvedBy.name')->label('Floor override approved by')->placeholder('—'),
                        TextEntry::make('tax_amount')->label('Tax')->money(),
                        TextEntry::make('line_total')->label('Line total')->money(),
                    ]),
                ]),
            Section::make('Delivered')
                ->description('Every delivery this invoice covers — one for a single-delivery invoice, several for a consolidated one.')
                ->schema([
                    RepeatableEntry::make('deliveryLinks')->label('')->columns(3)->schema([
                        TextEntry::make('inventoryOperation.operation_number')->label('Delivery'),
                        TextEntry::make('inventoryOperation.completed_at')->label('Completed')->dateTime()->placeholder('—'),
                        TextEntry::make('inventoryOperation.customer.company_name')->label('Customer'),
                    ]),
                ])
                ->collapsed(fn (Invoice $record): bool => $record->deliveryLinks->isEmpty())
                ->visible(fn (Invoice $record): bool => $record->deliveryLinks->isNotEmpty()),
            Section::make('Receipt confirmation (internal/legacy evidence)')
                ->description('Invoices are electronic documents available automatically once issued; there is no customer "confirm receipt" step in the normal flow. This section is retained for legacy/internal evidence only.')
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextEntry::make('received_confirmation_type')
                        ->label('Type')
                        ->badge()
                        ->formatStateUsing(fn (?InvoiceConfirmationType $state): ?string => $state?->label())
                        ->placeholder('Not confirmed'),
                    TextEntry::make('received_confirmed_at')
                        ->label('Confirmed at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('receivedConfirmedBy.name')
                        ->label('Confirmed by')
                        ->placeholder('—'),
                    TextEntry::make('receipt_signature')
                        ->label('Signature evidence')
                        ->state(fn (Invoice $record): string => $record->confirmations()
                            ->orderByDesc('confirmed_at')
                            ->orderByDesc('id')
                            ->first()?->getFirstMedia('invoice-confirmation-signature')->file_name ?? 'No signature attached')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
