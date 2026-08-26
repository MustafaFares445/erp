<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Schemas;

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
                TextEntry::make('status')->badge(),
                TextEntry::make('issue_date')->date(),
                TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                TextEntry::make('invoice.invoice_number')->label(__('admin.sales.fields.invoice_number')),
                TextEntry::make('grand_total')->money(),
                TextEntry::make('subtotal')->money(),
                TextEntry::make('tax_total')->money(),
                TextEntry::make('reason')->columnSpanFull(),
            ]),
        ]);
    }
}
