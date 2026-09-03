<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                    TextEntry::make('invoice_date')->date(),
                    TextEntry::make('due_date')->date()->placeholder('—'),
                    TextEntry::make('subtotal')->money(),
                    TextEntry::make('tax_total')->money(),
                    TextEntry::make('total_amount')->money(),
                    TextEntry::make('amount_paid')->money(),
                    TextEntry::make('credited_amount')->money(),
                    TextEntry::make('description')->columnSpanFull()->placeholder('—'),
                ]),
            Section::make('Receipt confirmation')
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
                            ->first()?->getFirstMedia('invoice-confirmation-signature')?->file_name ?? 'No signature attached')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
