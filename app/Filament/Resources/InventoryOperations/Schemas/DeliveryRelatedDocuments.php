<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Schemas;

use App\Filament\Resources\DeliveryNotes\Schemas\DeliveryNoteInfolist;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\ManualPaymentRecord;
use App\Models\Payment;
use App\Models\Quotation;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The five documents a Delivery genuinely relates to (see the ADR at
 * `Docs/adr/0012-origin-domain-owns-business-facts.md`): a generated packing
 * list, and links to the invoice, quotation, payment and receipt number the
 * ERP already owns as first-class records. Shared by
 * {@see InventoryOperationInfolist}
 * and {@see DeliveryNoteInfolist}, which view
 * the same {@see InventoryOperation} record through different resources.
 */
final class DeliveryRelatedDocuments
{
    /** @return array<int, TextEntry> */
    public static function make(): array
    {
        return [
            self::packingListEntry(),
            self::invoiceEntry(),
            self::quotationEntry(),
            self::paymentEntry(),
            self::receiptVoucherEntry(),
        ];
    }

    private static function packingListEntry(): TextEntry
    {
        return TextEntry::make('packing_list')
            ->label(__('admin.inventory.operation.related_documents.packing_list'))
            ->state(function (InventoryOperation $record): string {
                $media = $record->getFirstMedia('packing-list-pdf');

                return $media instanceof Media ? $media->file_name : __('admin.inventory.operation.related_documents.not_generated');
            })
            ->url(fn (InventoryOperation $record): ?string => self::mediaRoute($record, $record->getFirstMedia('packing-list-pdf')))
            ->openUrlInNewTab()
            ->suffixAction(
                Action::make('download_packing_list')
                    ->label(__('admin.inventory.operation.related_documents.download'))
                    ->icon(Heroicon::ArrowDownTray)
                    ->url(fn (InventoryOperation $record): ?string => self::mediaRoute($record, $record->getFirstMedia('packing-list-pdf'), 'download'))
                    ->openUrlInNewTab()
                    ->visible(fn (InventoryOperation $record): bool => $record->getFirstMedia('packing-list-pdf') instanceof Media),
            )
            ->color(fn (InventoryOperation $record): string => $record->getFirstMedia('packing-list-pdf') instanceof Media ? 'success' : 'warning');
    }

    private static function invoiceEntry(): TextEntry
    {
        return TextEntry::make('related_invoice')
            ->label(__('admin.inventory.operation.related_documents.original_invoice'))
            ->state(function (InventoryOperation $record): string {
                $invoice = $record->relatedInvoice();

                return $invoice instanceof Invoice ? $invoice->invoice_number : __('admin.inventory.operation.related_documents.not_invoiced');
            })
            ->url(function (InventoryOperation $record): ?string {
                $invoice = $record->relatedInvoice();

                return $invoice instanceof Invoice ? InvoiceResource::getUrl('view', ['record' => $invoice]) : null;
            })
            ->suffixAction(
                Action::make('download_invoice')
                    ->label(__('admin.inventory.operation.related_documents.download'))
                    ->icon(Heroicon::ArrowDownTray)
                    ->url(function (InventoryOperation $record): ?string {
                        $invoice = $record->relatedInvoice();
                        $media = $invoice?->getFirstMedia('invoice-pdf');

                        return $media instanceof Media
                            ? route('admin.invoices.media.download', ['invoice' => $invoice, 'media' => $media])
                            : null;
                    })
                    ->openUrlInNewTab()
                    ->visible(function (InventoryOperation $record): bool {
                        $invoice = $record->relatedInvoice();

                        return $invoice instanceof Invoice && $invoice->getFirstMedia('invoice-pdf') instanceof Media;
                    }),
            )
            ->color(fn (InventoryOperation $record): string => $record->relatedInvoice() instanceof Invoice ? 'success' : 'warning');
    }

    private static function quotationEntry(): TextEntry
    {
        return TextEntry::make('related_quotation')
            ->label(__('admin.inventory.operation.related_documents.quotation'))
            ->state(function (InventoryOperation $record): string {
                $quotation = $record->relatedQuotation();

                return $quotation instanceof Quotation ? $quotation->quotation_number : __('admin.inventory.operation.related_documents.no_quotation');
            })
            ->url(function (InventoryOperation $record): ?string {
                $quotation = $record->relatedQuotation();

                return $quotation instanceof Quotation ? QuotationResource::getUrl('view', ['record' => $quotation]) : null;
            })
            ->suffixAction(
                Action::make('download_quotation')
                    ->label(__('admin.inventory.operation.related_documents.download'))
                    ->icon(Heroicon::ArrowDownTray)
                    ->url(function (InventoryOperation $record): ?string {
                        $quotation = $record->relatedQuotation();
                        $media = $quotation?->getFirstMedia('quotation-pdf');

                        return $media instanceof Media
                            ? route('admin.quotations.media.download', ['quotation' => $quotation, 'media' => $media])
                            : null;
                    })
                    ->openUrlInNewTab()
                    ->visible(function (InventoryOperation $record): bool {
                        $quotation = $record->relatedQuotation();

                        return $quotation instanceof Quotation && $quotation->getFirstMedia('quotation-pdf') instanceof Media;
                    }),
            )
            ->color(fn (InventoryOperation $record): string => $record->relatedQuotation() instanceof Quotation ? 'success' : 'warning');
    }

    private static function paymentEntry(): TextEntry
    {
        return TextEntry::make('related_payment')
            ->label(__('admin.inventory.operation.related_documents.payment_receipt'))
            ->state(function (InventoryOperation $record): string {
                $payment = $record->relatedPayment();

                return $payment instanceof Payment ? $payment->payment_number : __('admin.inventory.operation.related_documents.no_payment');
            })
            ->url(function (InventoryOperation $record): ?string {
                $payment = $record->relatedPayment();

                return $payment instanceof Payment ? PaymentResource::getUrl('view', ['record' => $payment]) : null;
            })
            ->suffixAction(
                Action::make('view_payment_proof')
                    ->label(__('admin.inventory.operation.related_documents.view_proof'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(function (InventoryOperation $record): ?string {
                        $payment = $record->relatedPayment();
                        $media = $payment?->getFirstMedia('payment-proof');

                        return $media instanceof Media
                            ? route('admin.payments.media.preview', ['payment' => $payment, 'media' => $media])
                            : null;
                    })
                    ->openUrlInNewTab()
                    ->visible(function (InventoryOperation $record): bool {
                        $payment = $record->relatedPayment();

                        return $payment instanceof Payment && $payment->getFirstMedia('payment-proof') instanceof Media;
                    }),
            )
            ->color(fn (InventoryOperation $record): string => $record->relatedPayment() instanceof Payment ? 'success' : 'warning');
    }

    private static function receiptVoucherEntry(): TextEntry
    {
        return TextEntry::make('receipt_voucher')
            ->label(__('admin.inventory.operation.related_documents.receipt_voucher'))
            ->state(function (InventoryOperation $record): string {
                $payment = $record->relatedPayment();

                if (! $payment instanceof Payment) {
                    return __('admin.inventory.operation.related_documents.no_payment');
                }

                $reference = $payment->manualRecord instanceof ManualPaymentRecord ? $payment->manualRecord->reference : null;

                return filled($reference)
                    ? sprintf('%s (%s)', $payment->payment_number, $reference)
                    : $payment->payment_number;
            })
            ->color(fn (InventoryOperation $record): string => $record->relatedPayment() instanceof Payment ? 'success' : 'warning');
    }

    private static function mediaRoute(InventoryOperation $record, ?Media $media, string $action = 'preview'): ?string
    {
        return $media instanceof Media
            ? route('admin.inventory-operations.media.'.$action, ['operation' => $record, 'media' => $media])
            : null;
    }
}
