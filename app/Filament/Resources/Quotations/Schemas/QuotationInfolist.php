<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class QuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('quotation_number')->label(__('admin.sales.fields.quotation_number')),
            TextEntry::make('customer.company_name')->label(__('admin.sales.fields.customer')),
            TextEntry::make('status')->label(__('admin.sales.fields.status'))->badge(),
            TextEntry::make('issue_date')->label(__('admin.sales.fields.issue_date'))->date(),
            TextEntry::make('expires_at')->label(__('admin.sales.fields.expires_at'))->date(),
            TextEntry::make('subtotal')->label(__('admin.sales.fields.subtotal'))->money(),
            TextEntry::make('tax_total')->label(__('admin.sales.fields.tax_total'))->money(),
            TextEntry::make('grand_total')->label(__('admin.sales.fields.grand_total'))->money(),
            TextEntry::make('decision_note')->label(__('admin.sales.fields.decision_note'))->placeholder('—'),
            RepeatableEntry::make('lines')
                ->label(__('admin.sales.fields.lines'))
                ->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.sales.fields.product_variant')),
                    TextEntry::make('quantity')->label(__('admin.sales.fields.quantity')),
                    TextEntry::make('unit.name')->label(__('admin.sales.fields.unit'))->placeholder('—'),
                    TextEntry::make('unit_price')->label(__('admin.sales.fields.unit_price'))->money(),
                    TextEntry::make('resolved_price_source')->label(__('admin.sales.fields.resolved_price_source'))->placeholder('—'),
                    TextEntry::make('tax_amount')->label(__('admin.sales.fields.tax_amount'))->money(),
                    TextEntry::make('line_total')->label(__('admin.sales.fields.line_total'))->money(),
                ])
                ->columns(7)
                ->columnSpanFull(),
        ])->columns(3);
    }
}
