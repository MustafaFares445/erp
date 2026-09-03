<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\CreditNoteStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CreditNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('credit_note_number'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (CreditNoteStatus $state): string => $state->label())
                    ->color(static fn (CreditNoteStatus $state): string => match ($state) {
                        CreditNoteStatus::Draft => 'gray',
                        CreditNoteStatus::Confirmed => 'success',
                        CreditNoteStatus::Reversed, CreditNoteStatus::Cancelled => 'danger',
                    }),
                TextEntry::make('issue_date')->date(),
                TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                TextEntry::make('reason_category')->badge()->formatStateUsing(static fn (CreditNoteReason $state): string => $state->label()),
                TextEntry::make('stock_consequence')
                    ->label(__('admin.sales.fields.stock_consequence'))
                    ->badge()
                    ->formatStateUsing(static fn (CreditNoteStockConsequence $state): string => $state->label()),
                TextEntry::make('confirmed_at')->dateTime()->placeholder('—'),
                TextEntry::make('reversed_at')->dateTime()->placeholder('—'),
                TextEntry::make('grand_total')->money(),
                TextEntry::make('subtotal')->money(),
                TextEntry::make('tax_total')->money(),
                TextEntry::make('reason')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->label('')->columns(6)->schema([
                    TextEntry::make('description'),
                    TextEntry::make('invoiceLine.description')->label(__('admin.sales.fields.invoice_line'))->placeholder('—'),
                    TextEntry::make('inventoryReturnLine.id')
                        ->label(__('admin.sales.fields.inventory_return_line'))
                        ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? 'Return line #'.(int) $state : '—')
                        ->placeholder('—'),
                    TextEntry::make('quantity')->numeric(decimalPlaces: 3),
                    TextEntry::make('unit_price')->label(__('admin.sales.fields.unit_price'))->money(),
                    TextEntry::make('line_total')->label(__('admin.sales.fields.line_total'))->money(),
                ]),
            ]),
            Section::make('Stock consequence')
                ->columns(3)
                ->schema([
                    TextEntry::make('stock_consequence')
                        ->label(__('admin.sales.fields.stock_consequence'))
                        ->formatStateUsing(static fn (CreditNoteStockConsequence $state): string => $state->label()),
                    TextEntry::make('inventoryReturn.return_number')
                        ->label(__('admin.sales.fields.inventory_return'))
                        ->placeholder(fn (CreditNote $record): string => $record->stock_consequence === CreditNoteStockConsequence::CustomerRetained
                            ? 'Customer retained the goods — no stock consequence.'
                            : '—'),
                    TextEntry::make('inventoryReturn.status')
                        ->label(__('admin.sales.fields.return_status'))
                        ->badge()
                        ->placeholder('—'),
                ]),
            Section::make('Linked invoice')
                ->visible(fn (CreditNote $record): bool => $record->invoice instanceof Invoice)
                ->columns(3)
                ->schema([
                    TextEntry::make('invoice.invoice_number')->label(__('admin.sales.fields.invoice_number')),
                    TextEntry::make('invoice.total_amount')->label(__('admin.sales.fields.grand_total'))->money(),
                    TextEntry::make('invoice.credited_amount')->label(__('admin.sales.fields.credited_amount'))->money(),
                    TextEntry::make('invoice.status')->label(__('admin.sales.fields.status'))->badge(),
                    TextEntry::make('invoice_remaining')
                        ->label(__('admin.sales.hints.uncredited_remainder'))
                        ->state(fn (CreditNote $record): string => $record->invoice instanceof Invoice
                            ? number_format(max(0.0, (float) $record->invoice->total_amount - (float) $record->invoice->credited_amount), 2)
                            : '—'),
                ]),
            Section::make('Accounting impact')
                ->visible(fn (CreditNote $record): bool => $record->isConfirmed())
                ->schema([
                    RepeatableEntry::make('journalEntries')->label('')->schema([
                        TextEntry::make('entry_number'),
                        TextEntry::make('entry_date')->date(),
                        TextEntry::make('status')->badge(),
                        RepeatableEntry::make('lines')->label('')->columns(4)->schema([
                            TextEntry::make('chartAccount.name')->label('Account'),
                            TextEntry::make('chartAccount.code')->label('Code'),
                            TextEntry::make('debit')->money(),
                            TextEntry::make('credit')->money(),
                        ]),
                    ]),
                ]),
        ]);
    }
}
