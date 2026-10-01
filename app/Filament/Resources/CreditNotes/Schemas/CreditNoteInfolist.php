<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\JournalEntryStatus;
use App\Enums\RefundStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Support\MoneyFormatter;
use App\Support\QuantityFormatter;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CreditNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            self::hero(),
            self::stateCallout(),
            self::refundNotice(),
            self::overpaymentNotice(),
            Grid::make(12)->schema([
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 8])
                    ->schema([
                        self::lines(),
                        self::stockImpact(),
                        self::invoiceImpact(),
                        self::refunds(),
                        self::accountingImpact(),
                    ]),
                Group::make()
                    ->columnSpan(['default' => 12, 'lg' => 4])
                    ->schema([
                        self::summary(),
                        self::reason(),
                        self::document(),
                    ]),
            ]),
        ]);
    }

    private static function hero(): Grid
    {
        return Grid::make(12)->schema([
            Group::make()
                ->columnSpan(['default' => 12, 'md' => 8])
                ->schema([
                    TextEntry::make('credit_note_number')
                        ->hiddenLabel()
                        ->size('lg')
                        ->weight(FontWeight::Bold),
                    TextEntry::make('customer.company_name')
                        ->hiddenLabel()
                        ->color('gray'),
                    TextEntry::make('source_invoice')
                        ->label(__('admin.sales.credit_note_ui.source_invoice'))
                        ->state(fn (CreditNote $record): string => $record->invoice->invoice_number
                            ?? __('admin.sales.credit_note_ui.no_source_invoice'))
                        ->url(fn (CreditNote $record): ?string => $record->invoice instanceof Invoice
                            ? InvoiceResource::getUrl('view', ['record' => $record->invoice->getKey()])
                            : null),
                ]),
            Group::make()
                ->columnSpan(['default' => 12, 'md' => 4])
                ->schema([
                    TextEntry::make('status')
                        ->hiddenLabel()
                        ->badge()
                        ->formatStateUsing(fn (CreditNoteStatus $state): string => $state->label())
                        ->color(fn (CreditNoteStatus $state): string => self::statusColor($state)),
                    TextEntry::make('grand_total')
                        ->hiddenLabel()
                        ->money()
                        ->size('lg')
                        ->weight(FontWeight::Bold),
                    TextEntry::make('reason_category')
                        ->label(__('admin.sales.fields.reason_category'))
                        ->badge()
                        ->formatStateUsing(fn (CreditNoteReason $state): string => $state->label()),
                ]),
        ]);
    }

    private static function stateCallout(): Callout
    {
        return Callout::make(fn (CreditNote $record): string => self::bannerMeta($record)['heading'])
            ->description(fn (CreditNote $record): string => self::bannerMeta($record)['description'])
            ->status(fn (CreditNote $record): string => self::bannerMeta($record)['status']);
    }

    /** @return array{status: string, heading: string, description: string} */
    private static function bannerMeta(CreditNote $record): array
    {
        if ($record->isReversed()) {
            return [
                'status' => 'warning',
                'heading' => __('admin.sales.credit_note_ui.reversed_heading'),
                'description' => __('admin.sales.credit_note_ui.reversed_description'),
            ];
        }

        if (! $record->isConfirmed()) {
            return [
                'status' => 'info',
                'heading' => __('admin.sales.credit_note_ui.draft_heading'),
                'description' => __('admin.sales.credit_note_ui.draft_description'),
            ];
        }

        $description = $record->invoice instanceof Invoice
            ? __('admin.sales.credit_note_ui.confirmed_invoice_effect', [
                'amount' => MoneyFormatter::formatAmount($record->grand_total),
            ])
            : __('admin.sales.credit_note_ui.confirmed_account_effect', [
                'amount' => MoneyFormatter::formatAmount($record->grand_total),
            ]);

        return [
            'status' => 'success',
            'heading' => __('admin.sales.credit_note_ui.confirmed_heading'),
            'description' => $description,
        ];
    }

    private static function lines(): Section
    {
        return Section::make(__('admin.sales.credit_note_ui.lines'))
            ->schema([
                RepeatableEntry::make('lines')
                    ->label('')
                    ->table([
                        TableColumn::make(__('admin.sales.fields.description')),
                        TableColumn::make(__('admin.sales.fields.quantity')),
                        TableColumn::make(__('admin.sales.fields.unit_price')),
                        TableColumn::make(__('admin.sales.fields.tax')),
                        TableColumn::make(__('admin.sales.fields.line_total')),
                        TableColumn::make(__('admin.sales.credit_note_ui.source_reference')),
                    ])
                    ->schema([
                        TextEntry::make('description')->label(__('admin.sales.fields.description')),
                        TextEntry::make('quantity')
                            ->label(__('admin.sales.fields.quantity'))
                            ->state(fn (CreditNoteLine $record): string => QuantityFormatter::display($record->quantity)),
                        TextEntry::make('unit_price')->label(__('admin.sales.fields.unit_price'))->money(),
                        TextEntry::make('tax_amount')->label(__('admin.sales.credit_note_ui.tax_credit'))->money(),
                        TextEntry::make('line_total')->label(__('admin.sales.credit_note_ui.line_credit'))->money(),
                        TextEntry::make('source_reference')
                            ->label(__('admin.sales.credit_note_ui.source_reference'))
                            ->state(fn (CreditNoteLine $record): string => self::lineSource($record))
                            ->url(fn (CreditNoteLine $record): ?string => $record->inventoryReturnLine?->inventoryReturn instanceof InventoryReturn
                                ? ReturnResource::getUrl('view', ['record' => $record->inventoryReturnLine->inventoryReturn->getKey()])
                                : ($record->creditNote?->invoice instanceof Invoice
                                    ? InvoiceResource::getUrl('view', ['record' => $record->creditNote->invoice->getKey()])
                                    : null)),
                    ]),
            ]);
    }

    private static function lineSource(CreditNoteLine $line): string
    {
        $returnLine = $line->inventoryReturnLine;

        if ($returnLine !== null) {
            $returnNumber = $returnLine->inventoryReturn->return_number ?? __('admin.sales.credit_note_ui.inventory_return');
            $sku = $returnLine->productVariant?->sku;
            $quantity = QuantityFormatter::display($returnLine->transaction_quantity);
            $unit = $returnLine->transactionUnit?->name;
            $product = filled($sku) ? $sku : $line->description;
            $quantityLabel = filled($unit) ? "{$quantity} {$unit}" : $quantity;

            return __('admin.sales.credit_note_ui.return_line_reference', [
                'return' => $returnNumber,
                'product' => $product,
                'quantity' => $quantityLabel,
            ]);
        }

        return $line->invoiceLine->description ?? __('admin.sales.credit_note_ui.no_source_line');
    }

    private static function stockImpact(): Section
    {
        return Section::make(__('admin.sales.credit_note_ui.stock_impact'))
            ->description(fn (CreditNote $record): string => self::stockDescription($record))
            ->schema([
                TextEntry::make('stock_consequence')
                    ->label(__('admin.sales.credit_note_ui.stock_effect'))
                    ->badge()
                    ->formatStateUsing(fn (CreditNoteStockConsequence $state): string => $state->label()),
                TextEntry::make('inventory_return_number')
                    ->label(__('admin.sales.fields.inventory_return'))
                    ->state(fn (CreditNote $record): string => $record->inventoryReturn->return_number ?? '—')
                    ->url(fn (CreditNote $record): ?string => $record->inventoryReturn !== null
                        ? ReturnResource::getUrl('view', ['record' => $record->inventoryReturn->getKey()])
                        : null)
                    ->visible(fn (CreditNote $record): bool => $record->inventoryReturn !== null),
                TextEntry::make('inventory_return_status')
                    ->label(__('admin.sales.fields.return_status'))
                    ->state(fn (CreditNote $record): string => $record->inventoryReturn?->status->label() ?? '—')
                    ->badge()
                    ->visible(fn (CreditNote $record): bool => $record->inventoryReturn !== null),
            ])
            ->columns(2);
    }

    private static function stockDescription(CreditNote $record): string
    {
        return match ($record->stock_consequence) {
            CreditNoteStockConsequence::NotApplicable => __('admin.sales.credit_note_ui.no_inventory_movement'),
            CreditNoteStockConsequence::CustomerRetained => __('admin.sales.credit_note_ui.customer_retained_description'),
            CreditNoteStockConsequence::GoodsReturned => __('admin.sales.credit_note_ui.goods_returned_description'),
        };
    }

    private static function invoiceImpact(): Section
    {
        return Section::make(__('admin.sales.credit_note_ui.invoice_impact'))
            ->description(__('admin.sales.credit_note_ui.creditable_vs_outstanding'))
            ->schema([
                TextEntry::make('invoice.invoice_number')
                    ->label(__('admin.sales.credit_note_ui.source_invoice'))
                    ->url(fn (CreditNote $record): ?string => $record->invoice instanceof Invoice
                        ? InvoiceResource::getUrl('view', ['record' => $record->invoice->getKey()])
                        : null),
                TextEntry::make('invoice_total')
                    ->label(__('admin.sales.credit_note_ui.original_invoice_total'))
                    ->state(fn (CreditNote $record): float => $record->invoice instanceof Invoice ? (float) $record->invoice->total_amount : 0.0)
                    ->money()
                    ->visible(fn (CreditNote $record): bool => $record->invoice instanceof Invoice),
                TextEntry::make('previously_credited')
                    ->label(__('admin.sales.credit_note_ui.previously_credited'))
                    ->state(fn (CreditNote $record): float => self::previouslyCredited($record))
                    ->money()
                    ->visible(fn (CreditNote $record): bool => $record->invoice instanceof Invoice),
                TextEntry::make('this_credit')
                    ->label(__('admin.sales.credit_note_ui.this_credit'))
                    ->state(fn (CreditNote $record): float => (float) $record->grand_total)
                    ->money()
                    ->visible(fn (CreditNote $record): bool => $record->invoice instanceof Invoice),
                TextEntry::make('credited_after_confirmation')
                    ->label(__('admin.sales.credit_note_ui.total_credited_after'))
                    ->state(fn (CreditNote $record): float => self::creditedAfterConfirmation($record))
                    ->money()
                    ->visible(fn (CreditNote $record): bool => $record->invoice instanceof Invoice),
                TextEntry::make('remaining_creditable')
                    ->label(__('admin.sales.credit_note_ui.remaining_creditable'))
                    ->state(fn (CreditNote $record): float => $record->invoice instanceof Invoice
                        ? max(0.0, (float) $record->invoice->total_amount - (float) $record->invoice->credited_amount)
                        : 0.0)
                    ->money()
                    ->visible(fn (CreditNote $record): bool => $record->invoice instanceof Invoice),
                TextEntry::make('current_outstanding')
                    ->label(__('admin.sales.credit_note_ui.current_outstanding'))
                    ->state(fn (CreditNote $record): float => $record->invoice instanceof Invoice
                        ? $record->invoice->outstandingAmount()
                        : 0.0)
                    ->money()
                    ->weight(FontWeight::Bold)
                    ->visible(fn (CreditNote $record): bool => $record->invoice instanceof Invoice),
            ])
            ->columns(2)
            ->visible(fn (CreditNote $record): bool => $record->invoice instanceof Invoice);
    }

    private static function previouslyCredited(CreditNote $record): float
    {
        if (! $record->invoice instanceof Invoice) {
            return 0.0;
        }

        $credited = (float) $record->invoice->credited_amount;

        return $record->isConfirmed() && ! $record->isReversed()
            ? max(0.0, $credited - (float) $record->grand_total)
            : $credited;
    }

    private static function creditedAfterConfirmation(CreditNote $record): float
    {
        if (! $record->invoice instanceof Invoice) {
            return 0.0;
        }

        $credited = (float) $record->invoice->credited_amount;

        return $record->isReversed() ? $credited + (float) $record->grand_total : $credited;
    }

    private static function summary(): Section
    {
        return Section::make(__('admin.sales.credit_note_ui.summary'))
            ->schema([
                TextEntry::make('subtotal')->label(__('admin.sales.fields.subtotal'))->money(),
                TextEntry::make('tax_total')->label(__('admin.sales.fields.tax'))->money(),
                TextEntry::make('grand_total')
                    ->label(__('admin.sales.credit_note_ui.total_credit'))
                    ->money()
                    ->weight(FontWeight::Bold),
                TextEntry::make('issue_date')->label(__('admin.sales.fields.date'))->date(),
                TextEntry::make('confirmed_at')->label(__('admin.sales.credit_note_ui.confirmed_at'))->dateTime()->placeholder(__('—')),
                TextEntry::make('reversed_at')->label(__('admin.sales.credit_note_ui.reversed_at'))->dateTime()->placeholder(__('—')),
            ]);
    }

    private static function reason(): Section
    {
        return Section::make(__('admin.sales.credit_note_ui.reason'))
            ->schema([
                TextEntry::make('reason_category')
                    ->hiddenLabel()
                    ->badge()
                    ->formatStateUsing(fn (CreditNoteReason $state): string => $state->label()),
                TextEntry::make('reason')->hiddenLabel()->placeholder(__('—')),
            ]);
    }

    private static function refundNotice(): Callout
    {
        return Callout::make(__('admin.sales.credit_note_ui.refund_heading'))
            ->description(fn (CreditNote $record): string => $record->refunds->isNotEmpty()
                ? __('admin.sales.credit_note_ui.refund_records_explanation')
                : __('admin.sales.credit_note_ui.refund_explanation'))
            ->status('info');
    }

    private static function refunds(): Section
    {
        return Section::make(__('admin.sales.credit_note_ui.refund_records'))
            ->description(__('admin.sales.credit_note_ui.refund_records_description'))
            ->schema([
                RepeatableEntry::make('refunds')
                    ->label('')
                    ->table([
                        TableColumn::make(__('admin.sales.credit_note_ui.refund_number')),
                        TableColumn::make(__('admin.sales.fields.date')),
                        TableColumn::make(__('admin.sales.credit_note_ui.refund_amount')),
                        TableColumn::make(__('admin.sales.fields.status')),
                        TableColumn::make(__('admin.sales.credit_note_ui.refund_paid_at')),
                    ])
                    ->schema([
                        TextEntry::make('refund_number')->label(__('admin.sales.credit_note_ui.refund_number')),
                        TextEntry::make('refund_date')->label(__('admin.sales.fields.date'))->date(),
                        TextEntry::make('amount')->label(__('admin.sales.credit_note_ui.refund_amount'))->money(),
                        TextEntry::make('status')
                            ->label(__('admin.sales.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (RefundStatus $state): string => $state->label())
                            ->color(fn (RefundStatus $state): string => $state->color()),
                        TextEntry::make('paid_at')->label(__('admin.sales.credit_note_ui.refund_paid_at'))->dateTime()->placeholder(__('—')),
                    ])
                    ->visible(fn (CreditNote $record): bool => $record->refunds->isNotEmpty()),
            ])
            ->visible(fn (CreditNote $record): bool => $record->refunds->isNotEmpty());
    }

    private static function overpaymentNotice(): Callout
    {
        return Callout::make(__('admin.sales.credit_note_ui.possible_customer_credit_heading'))
            ->description(__('admin.sales.credit_note_ui.possible_customer_credit_description'))
            ->status('warning')
            ->visible(fn (CreditNote $record): bool => self::mayNeedRefundOrAccountCredit($record));
    }

    private static function mayNeedRefundOrAccountCredit(CreditNote $record): bool
    {
        if (! $record->isConfirmed() || $record->isReversed() || ! $record->invoice instanceof Invoice) {
            return false;
        }

        $invoice = $record->invoice;
        $claimMinor = max(0, $invoice->receivableClaimMinor() - $invoice->writtenOffAmountMinor());

        return $invoice->amountPaidMinor() > $claimMinor;
    }

    private static function document(): Section
    {
        return Section::make(__('admin.sales.credit_note_ui.document'))
            ->schema([
                TextEntry::make('credit_note_pdf')
                    ->label(__('admin.sales.credit_note_ui.pdf_status'))
                    ->state(fn (CreditNote $record): string => $record->getFirstMedia('credit-note-pdf') instanceof Media
                        ? __('admin.sales.credit_note_ui.pdf_available')
                        : __('admin.sales.credit_note_ui.pdf_not_generated'))
                    ->badge()
                    ->color(fn (CreditNote $record): string => $record->getFirstMedia('credit-note-pdf') instanceof Media ? 'success' : 'gray')
                    ->url(fn (CreditNote $record): ?string => self::pdfRoute($record, 'preview'))
                    ->openUrlInNewTab()
                    ->suffixAction(
                        Action::make('download_credit_note_pdf')
                            ->label(__('admin.sales.credit_note_ui.download_pdf'))
                            ->icon(Heroicon::ArrowDownTray)
                            ->url(fn (CreditNote $record): ?string => self::pdfRoute($record, 'download'))
                            ->openUrlInNewTab()
                            ->visible(fn (CreditNote $record): bool => $record->getFirstMedia('credit-note-pdf') instanceof Media),
                    ),
            ]);
    }

    private static function accountingImpact(): Section
    {
        return Section::make(__('admin.sales.credit_note_ui.accounting_impact'))
            ->description(__('admin.sales.credit_note_ui.accounting_description'))
            ->collapsible()
            ->collapsed()
            ->schema([
                RepeatableEntry::make('journalEntries')
                    ->label('')
                    ->table([
                        TableColumn::make(__('admin.accounting.fields.entry_number')),
                        TableColumn::make(__('admin.accounting.fields.date')),
                        TableColumn::make(__('admin.accounting.fields.status')),
                    ])
                    ->schema([
                        TextEntry::make('entry_number')->label(__('admin.accounting.fields.entry_number')),
                        TextEntry::make('entry_date')->label(__('admin.accounting.fields.date'))->date(),
                        TextEntry::make('status')
                            ->label(__('admin.accounting.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (JournalEntryStatus $state): string => $state->label()),
                        RepeatableEntry::make('lines')
                            ->label(__('admin.accounting.fields.lines'))
                            ->columns(4)
                            ->schema([
                                TextEntry::make('chartAccount.name')->label(__('admin.accounting.fields.account')),
                                TextEntry::make('chartAccount.code')->label(__('admin.accounting.fields.code')),
                                TextEntry::make('debit')->label(__('admin.accounting.fields.debit'))->money(),
                                TextEntry::make('credit')->label(__('admin.accounting.fields.credit'))->money(),
                            ]),
                    ]),
            ])
            ->visible(fn (CreditNote $record): bool => $record->isConfirmed());
    }

    private static function pdfRoute(CreditNote $record, string $action): ?string
    {
        $media = $record->getFirstMedia('credit-note-pdf');

        return $media instanceof Media
            ? route('admin.credit-notes.media.'.$action, ['creditNote' => $record, 'media' => $media])
            : null;
    }

    private static function statusColor(CreditNoteStatus $status): string
    {
        return match ($status) {
            CreditNoteStatus::Draft => 'gray',
            CreditNoteStatus::Confirmed => 'success',
            CreditNoteStatus::Reversed => 'warning',
            CreditNoteStatus::Cancelled => 'gray',
        };
    }
}
