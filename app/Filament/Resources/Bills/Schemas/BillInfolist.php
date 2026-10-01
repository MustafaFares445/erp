<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Schemas;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Models\BillLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class BillInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Current accounting state'))
                ->description(__('The next Accounting action for this supplier payable document.'))
                ->columns(3)
                ->schema([
                    TextEntry::make('workflow_state')
                        ->label(__('Current state'))
                        ->state(fn (Bill $record): string => $record->status->label())
                        ->badge(),
                    TextEntry::make('workflow_blocker')
                        ->label(__('Blocker'))
                        ->state(fn (Bill $record): ?string => self::blocker($record))
                        ->placeholder(__('No active blocker'))
                        ->badge(),
                    TextEntry::make('workflow_owner')
                        ->label(__('Next owner'))
                        ->state(fn (Bill $record): string => self::nextOwner($record)),
                    TextEntry::make('workflow_action')
                        ->label(__('Next action'))
                        ->state(fn (Bill $record): string => self::nextAction($record))
                        ->columnSpanFull(),
                ]),
            Section::make(__('Bill details'))->columns(3)->schema([
                TextEntry::make('bill_number')->label(__('Bill number')),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('resolvedSupplier.name')->label(__('Supplier')),
                TextEntry::make('supplier_reference')->label(__('Supplier reference')),
                TextEntry::make('supplier_reference_source')
                    ->label(__('Reference evidence'))
                    ->state(fn (Bill $record): string => $record->supplier_reference_backfilled_at === null
                        ? 'Supplier provided'
                        : 'Backfilled reference')
                    ->badge(),
                TextEntry::make('purchaseOrder.purchase_order_number')->label(__('Purchase order'))->placeholder(__('Not linked')),
                TextEntry::make('paymentTerm.name')->label(__('Payment term'))->placeholder(__('Not provided')),
                TextEntry::make('bill_date')->label(__('Bill date'))->date(),
                TextEntry::make('due_date')->label(__('Due date'))->date()->placeholder(__('Not provided')),
                TextEntry::make('subtotal')->label(__('Subtotal'))->money(),
                TextEntry::make('tax_total')->label(__('Input tax'))->money(),
                TextEntry::make('grand_total')->label(__('Grand total'))->money(),
                TextEntry::make('paid_amount')->label(__('Paid amount'))->money(),
                TextEntry::make('description')->label(__('Description'))->columnSpanFull(),
            ]),
            Section::make(__('Lines and three-way match'))->schema([
                RepeatableEntry::make('lines')->label('')->columns(8)->schema([
                    TextEntry::make('description')->label(__('Description')),
                    TextEntry::make('quantity')->label(__('Billed quantity'))->numeric(decimalPlaces: 3),
                    TextEntry::make('unit_price')->label(__('Billed unit price'))->money(),
                    TextEntry::make('ordered_quantity')
                        ->label(__('Ordered quantity'))
                        ->state(static fn (BillLine $record): string => self::orderedQuantity($record)),
                    TextEntry::make('received_quantity')
                        ->label(__('Received quantity'))
                        ->state(static fn (BillLine $record): string => number_format($record->receivedQuantity(), 3, '.', '')),
                    TextEntry::make('cumulative_billed_quantity')
                        ->label(__('Cumulative billed'))
                        ->state(static fn (BillLine $record): string => number_format($record->cumulativeBilledQuantity(), 3, '.', '')),
                    TextEntry::make('quantity_variance')
                        ->label(__('Quantity variance'))
                        ->badge()
                        ->state(static fn (BillLine $record): string => $record->hasQuantityVariance() ? 'Variance' : 'Matched'),
                    TextEntry::make('price_variance')
                        ->label(__('Unit-price variance'))
                        ->badge()
                        ->state(static fn (BillLine $record): string => $record->hasUnitPriceVariance() ? 'Variance' : 'Matched'),
                ]),
            ]),
        ]);
    }

    private static function blocker(Bill $bill): ?string
    {
        if ($bill->status === BillStatus::Draft
            && str_starts_with($bill->supplier_reference, 'PO-AUTO:')) {
            return 'Replace the provisional reference with the supplier invoice reference';
        }

        $bill->loadMissing('lines');

        if ($bill->lines->contains(static fn (BillLine $line): bool => $line->hasQuantityVariance() || $line->hasUnitPriceVariance())) {
            return 'Three-way match variance requires review';
        }

        return null;
    }

    private static function nextOwner(Bill $bill): string
    {
        return match ($bill->status) {
            BillStatus::Draft => 'Accounting',
            BillStatus::Approved, BillStatus::PartiallyPaid => 'Accounts Payable',
            BillStatus::Paid, BillStatus::Cancelled => 'None',
        };
    }

    private static function nextAction(Bill $bill): string
    {
        return match ($bill->status) {
            BillStatus::Draft => 'Review supplier reference and three-way match, then approve the bill',
            BillStatus::Approved, BillStatus::PartiallyPaid => 'Record and allocate supplier payment',
            BillStatus::Paid => 'Completed',
            BillStatus::Cancelled => 'No further accounting action',
        };
    }

    private static function orderedQuantity(BillLine $line): string
    {
        $purchaseOrderLine = $line->purchaseOrderLine;

        return $purchaseOrderLine === null
            ? 'Not matched'
            : number_format((float) $purchaseOrderLine->quantity_ordered, 3, '.', '');
    }
}
