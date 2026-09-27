<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoiceFinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ResolvedPriceSource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\InvoiceLine;
use App\Models\PaymentAllocation;
use App\Services\Sales\InvoiceBalanceService;
use App\Services\Sales\InvoiceNextActionResolver;
use App\Support\MoneyFormatter;
use App\Support\QuantityFormatter;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            self::heroSection(),
            self::statusCallout(),
            Grid::make(12)->schema([
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 8])
                    ->schema([
                        self::productsSection(),
                        self::paymentsSection(),
                        self::deliveriesSection(),
                        self::reconciliationSection(),
                    ]),
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 4])
                    ->schema([
                        self::invoiceDetailsSection(),
                        self::financialSummarySection(),
                        self::documentSection(),
                    ]),
            ]),
        ]);
    }

    private static function heroSection(): Grid
    {
        return Grid::make(12)->schema([
            Group::make()
                ->columnSpan(['default' => 12, 'md' => 8])
                ->schema([
                    TextEntry::make('invoice_number')
                        ->hiddenLabel()
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold),
                    TextEntry::make('customer.company_name')
                        ->hiddenLabel()
                        ->color('gray'),
                ]),
            Group::make()
                ->columnSpan(['default' => 12, 'md' => 4])
                ->schema([
                    TextEntry::make('status')
                        ->hiddenLabel()
                        ->badge()
                        ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                        ->color(fn (InvoiceStatus $state): string => $state->color()),
                    TextEntry::make('total_amount')
                        ->hiddenLabel()
                        ->money()
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold),
                    TextEntry::make('outstanding_amount')
                        ->label('Outstanding')
                        ->state(fn (Invoice $record): float => $record->outstandingAmount())
                        ->money()
                        ->weight(FontWeight::Bold)
                        ->color(fn (Invoice $record): string => $record->outstandingAmount() > 0.0 ? 'danger' : 'success'),
                ]),
        ]);
    }

    private static function statusCallout(): Callout
    {
        return Callout::make(fn (Invoice $record): string => self::bannerMeta($record)['heading'])
            ->description(fn (Invoice $record): string => self::bannerMeta($record)['description'])
            ->status(fn (Invoice $record): string => self::bannerMeta($record)['status']);
    }

    /** @return array{status: string, heading: string, description: string} */
    private static function bannerMeta(Invoice $record): array
    {
        if ($record->status === InvoiceStatus::Cancelled) {
            return ['status' => 'danger', 'heading' => 'Cancelled', 'description' => 'This invoice was cancelled and is no longer active.'];
        }

        if ($record->status === InvoiceStatus::WrittenOff) {
            return ['status' => 'gray', 'heading' => 'Written off', 'description' => 'The remaining receivable was written off; no further collection is required.'];
        }

        if ($record->depositApplicationIssues()->whereNull('resolved_at')->exists()) {
            return [
                'status' => 'warning',
                'heading' => 'Reconciliation issue',
                'description' => mb_trim('A previously collected customer deposit could not be applied automatically. The invoice remains valid. '.self::nextStepSentence($record)),
            ];
        }

        $outstanding = MoneyFormatter::format($record->outstandingMinor());

        [$status, $heading, $description] = match (app(InvoiceBalanceService::class)->financialStatus($record)) {
            InvoiceFinancialStatus::NotPayableYet => ['gray', 'Draft invoice', 'This invoice has not been issued yet and is not financially active.'],
            InvoiceFinancialStatus::Unpaid => ['warning', 'Payment pending', "{$outstanding} remains outstanding."],
            InvoiceFinancialStatus::PartiallyPaid => ['warning', 'Partially paid', "{$outstanding} remains outstanding."],
            InvoiceFinancialStatus::Overdue => ['danger', 'Overdue', "This invoice is past its due date with {$outstanding} still outstanding."],
            InvoiceFinancialStatus::Paid, InvoiceFinancialStatus::Credited => ['success', 'Financially settled', 'No outstanding balance remains.'],
        };

        return [
            'status' => $status,
            'heading' => $heading,
            'description' => mb_trim($description.' '.self::nextStepSentence($record)),
        ];
    }

    private static function nextStepSentence(Invoice $record): string
    {
        $label = app(InvoiceNextActionResolver::class)->resolve($record);

        return in_array($label, ['No action required', 'No collection required'], true) ? '' : "Next: {$label}.";
    }

    private static function productsSection(): Section
    {
        return Section::make('Products')
            ->description('Frozen at document creation; later pricing-policy changes do not rewrite these values.')
            ->schema([
                RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                    TextEntry::make('productVariant.sku')->label('Product')->placeholder('Service'),
                    TextEntry::make('description')->label('Description'),
                    TextEntry::make('quantity')
                        ->label('Quantity')
                        ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                    TextEntry::make('unit_price')->label('Unit price')->money(),
                    TextEntry::make('tax_amount')->label('Tax')->money(),
                    TextEntry::make('line_total')->label('Line total')->money()->weight(FontWeight::Bold),
                    self::pricingDetailsSection(),
                ]),
            ]);
    }

    private static function pricingDetailsSection(): Section
    {
        return Section::make('Pricing details')
            ->columnSpanFull()
            ->collapsible()
            ->collapsed(static fn (InvoiceLine $record): bool => ! self::isBelowFloor($record))
            ->schema([
                TextEntry::make('resolved_price_source')
                    ->label('Price source')
                    ->badge()
                    ->formatStateUsing(static fn (?ResolvedPriceSource $state): ?string => $state?->label())
                    ->placeholder('Service / legacy'),
                TextEntry::make('resolvedPriceTier.name')->label('Pricing tier')->placeholder('—'),
                TextEntry::make('list_price_minor')
                    ->label('List price at invoice creation')
                    ->state(static fn (InvoiceLine $record): ?float => $record->list_price_minor === null ? null : $record->list_price_minor / 100)
                    ->money()
                    ->placeholder('—'),
                TextEntry::make('floor_price_minor')
                    ->label('Minimum allowed price')
                    ->state(static fn (InvoiceLine $record): ?float => $record->floor_price_minor === null ? null : $record->floor_price_minor / 100)
                    ->money()
                    ->placeholder('—'),
                TextEntry::make('unit_price')->label('Actual unit price')->money(),
                TextEntry::make('pricing_status')
                    ->label('Pricing status')
                    ->state(static fn (InvoiceLine $record): string => self::pricingStatusLabel($record))
                    ->badge()
                    ->color(static fn (InvoiceLine $record): string => self::isBelowFloor($record) ? 'warning' : 'gray'),
            ]);
    }

    private static function isBelowFloor(InvoiceLine $record): bool
    {
        $floorPriceMinor = $record->priceProvenanceAttributes()['floor_price_minor'];

        if ($floorPriceMinor === null) {
            return false;
        }

        return (float) $record->unit_price < ($floorPriceMinor / 100);
    }

    private static function pricingStatusLabel(InvoiceLine $record): string
    {
        if ($record->priceProvenanceAttributes()['floor_price_minor'] === null) {
            return 'Not applicable';
        }

        if (! self::isBelowFloor($record)) {
            return 'Within allowed pricing range';
        }

        $approver = $record->priceFloorOverride?->approvedBy?->name;

        return $approver !== null ? "Approved exception (override by {$approver})" : 'Approved exception (pending approval record)';
    }

    private static function paymentsSection(): Section
    {
        return Section::make('Payments applied')
            ->schema([
                RepeatableEntry::make('paymentAllocations')
                    ->label('')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('payment.payment_number')->label('Payment'),
                        TextEntry::make('payment.payment_date')->label('Date')->date(),
                        TextEntry::make('amount')->label('Amount')->money(),
                        TextEntry::make('deposit_note')
                            ->label('')
                            ->state(static fn (PaymentAllocation $record): ?string => self::depositNote($record))
                            ->visible(static fn (PaymentAllocation $record): bool => self::depositNote($record) !== null)
                            ->color('info')
                            ->size(TextSize::Small)
                            ->columnSpanFull(),
                    ])
                    ->placeholder('No payment has been applied to this invoice yet.'),
                TextEntry::make('total_applied')
                    ->label('Total applied')
                    ->state(fn (Invoice $record): float => $record->paymentAllocations->sum(static fn (PaymentAllocation $allocation): float => (float) $allocation->amount))
                    ->money()
                    ->weight(FontWeight::Bold)
                    ->visible(fn (Invoice $record): bool => $record->paymentAllocations->isNotEmpty()),
            ])
            ->collapsed(fn (Invoice $record): bool => $record->paymentAllocations->isEmpty());
    }

    private static function depositNote(PaymentAllocation $record): ?string
    {
        $issuedAt = $record->invoice?->issued_at;
        $paymentDate = $record->payment?->payment_date;

        if (! $issuedAt instanceof Carbon || ! $paymentDate instanceof Carbon || ! $paymentDate->lessThan($issuedAt)) {
            return null;
        }

        return 'Customer deposit applied — collected before this invoice was issued and automatically applied to it.';
    }

    private static function deliveriesSection(): Section
    {
        return Section::make('Related deliveries')
            ->description('Delivery operations covered by this invoice.')
            ->schema([
                RepeatableEntry::make('deliveryLinks')->label('')->columns(3)->schema([
                    TextEntry::make('inventoryOperation.operation_number')
                        ->label('Delivery')
                        ->url(static fn (InvoiceDeliveryLink $record): ?string => $record->inventoryOperation instanceof InventoryOperation
                            ? InventoryOperationResource::getUrl('view', ['record' => $record->inventoryOperation])
                            : null),
                    TextEntry::make('inventoryOperation.completed_at')->label('Completed')->dateTime()->placeholder('—'),
                    TextEntry::make('inventoryOperation.customer.company_name')->label('Customer'),
                ]),
            ])
            ->collapsed(fn (Invoice $record): bool => $record->deliveryLinks->isEmpty())
            ->visible(fn (Invoice $record): bool => $record->deliveryLinks->isNotEmpty());
    }

    private static function reconciliationSection(): Section
    {
        return Section::make('Reconciliation issue')
            ->description('A previously collected customer deposit could not be applied automatically. The invoice remains valid — use "Retry deposit application" once the underlying issue is fixed.')
            ->visible(fn (Invoice $record): bool => $record->depositApplicationIssues()->whereNull('resolved_at')->exists())
            ->schema([
                RepeatableEntry::make('depositApplicationIssues')
                    ->label('')
                    ->schema([
                        TextEntry::make('occurred_at')->label('Occurred')->dateTime(),
                        Section::make('Technical details')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                TextEntry::make('error_message')->label('Error')->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    private static function invoiceDetailsSection(): Section
    {
        return Section::make('Invoice details')
            ->columns(2)
            ->schema([
                TextEntry::make('invoice_number')->label(__('admin.sales.fields.invoice_number')),
                TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                TextEntry::make('order.order_number')->label('Order')->placeholder('—'),
                TextEntry::make('order.quotation.quotation_number')->label('Quotation')->placeholder('—'),
                TextEntry::make('invoice_date')->date(),
                TextEntry::make('due_date')->date()->placeholder('—'),
                TextEntry::make('paymentTerm.name')->label('Payment term')->placeholder('—'),
                TextEntry::make('status')
                    ->label('Document status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state): string => $state->color()),
                TextEntry::make('description')->columnSpanFull()->placeholder('—'),
            ]);
    }

    private static function financialSummarySection(): Section
    {
        return Section::make('Financial summary')
            ->schema([
                TextEntry::make('subtotal')->money(),
                TextEntry::make('tax_total')->label('Tax')->money(),
                TextEntry::make('total_amount')->label('Total')->money()->weight(FontWeight::Bold),
                TextEntry::make('amount_paid')->label('Paid')->money()->color('success'),
                TextEntry::make('credited_amount')->label('Credits')->money(),
                TextEntry::make('written_off')
                    ->label('Written off')
                    ->state(fn (Invoice $record): float => $record->writtenOffAmountMinor() / 100)
                    ->money()
                    ->visible(fn (Invoice $record): bool => $record->writtenOffAmountMinor() > 0),
                TextEntry::make('outstanding_amount')
                    ->label('Outstanding')
                    ->state(fn (Invoice $record): float => $record->outstandingAmount())
                    ->money()
                    ->weight(FontWeight::Bold)
                    ->color(fn (Invoice $record): string => $record->outstandingAmount() > 0.0 ? 'danger' : 'success'),
                TextEntry::make('financial_status')
                    ->label('Financial status')
                    ->state(fn (Invoice $record): InvoiceFinancialStatus => app(InvoiceBalanceService::class)->financialStatus($record))
                    ->badge()
                    ->formatStateUsing(fn (InvoiceFinancialStatus $state): string => $state->label())
                    ->color(fn (InvoiceFinancialStatus $state): string => $state->color()),
            ]);
    }

    private static function documentSection(): Section
    {
        return Section::make('Document')
            ->schema([
                TextEntry::make('invoice_pdf')
                    ->label('Invoice PDF')
                    ->state(fn (Invoice $record): string => $record->getFirstMedia('invoice-pdf') instanceof Media ? 'Available' : 'Not generated yet')
                    ->badge()
                    ->color(fn (Invoice $record): string => $record->getFirstMedia('invoice-pdf') instanceof Media ? 'success' : 'gray')
                    ->url(fn (Invoice $record): ?string => self::pdfRoute($record, 'preview'))
                    ->openUrlInNewTab()
                    ->suffixAction(
                        Action::make('download_invoice_pdf')
                            ->label('Download')
                            ->icon(Heroicon::ArrowDownTray)
                            ->url(fn (Invoice $record): ?string => self::pdfRoute($record, 'download'))
                            ->openUrlInNewTab()
                            ->visible(fn (Invoice $record): bool => $record->getFirstMedia('invoice-pdf') instanceof Media),
                    ),
                TextEntry::make('email_status')
                    ->label('Email copy')
                    ->state(fn (Invoice $record): string => $record->sent_at instanceof Carbon ? 'Sent '.$record->sent_at->translatedFormat('M j, Y') : 'Not sent')
                    ->color(fn (Invoice $record): string => $record->sent_at !== null ? 'success' : 'gray'),
                TextEntry::make('customer_app_status')
                    ->label('Customer App')
                    ->state(fn (Invoice $record): string => $record->isIssued() ? 'Available' : 'Not available yet')
                    ->badge()
                    ->color(fn (Invoice $record): string => $record->isIssued() ? 'success' : 'gray'),
            ]);
    }

    private static function pdfRoute(Invoice $record, string $action): ?string
    {
        $media = $record->getFirstMedia('invoice-pdf');

        return $media instanceof Media ? route('admin.invoices.media.'.$action, ['invoice' => $record, 'media' => $media]) : null;
    }
}
