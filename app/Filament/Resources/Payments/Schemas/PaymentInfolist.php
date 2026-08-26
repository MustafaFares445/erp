<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('payment_number'),
            TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
            TextEntry::make('paymentMethod.name')->label(__('admin.sales.fields.payment_method')),
            TextEntry::make('amount')->money(),
            TextEntry::make('payment_date')->date(),
            TextEntry::make('status')->badge(),
            TextEntry::make('external_reference')->placeholder('—'),
            TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
        ])->columns(3);
    }
}
