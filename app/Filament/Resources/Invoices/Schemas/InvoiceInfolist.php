<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('invoice_number')->label(__('admin.sales.fields.invoice_number')),
            TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
            TextEntry::make('status')->badge(),
            TextEntry::make('invoice_date')->date(),
            TextEntry::make('due_date')->date()->placeholder('—'),
            TextEntry::make('subtotal')->money(),
            TextEntry::make('tax_total')->money(),
            TextEntry::make('total_amount')->money(),
            TextEntry::make('amount_paid')->money(),
            TextEntry::make('credited_amount')->money(),
            TextEntry::make('description')->columnSpanFull()->placeholder('—'),
        ])->columns(3);
    }
}
