<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('invoice_number')
                ->label(__('admin.sales.fields.invoice_number'))
                ->required()
                ->maxLength(100)
                ->unique(ignoreRecord: true),
            Select::make('customer_id')
                ->label(__('admin.sales.fields.customer'))
                ->relationship('customer', 'company_name')
                ->searchable()
                ->preload()
                ->required(),
            Select::make('payment_term_id')
                ->label(__('admin.sales.fields.payment_term'))
                ->relationship('paymentTerm', 'name')
                ->searchable()
                ->preload(),
            DatePicker::make('invoice_date')
                ->label(__('admin.sales.fields.issue_date'))
                ->default(now())
                ->required(),
            DatePicker::make('due_date')
                ->label(__('admin.sales.fields.due_date'))
                ->afterOrEqual('invoice_date'),
            TextInput::make('subtotal')->numeric()->minValue(0)->required()->default(0),
            TextInput::make('tax_total')->numeric()->minValue(0)->required()->default(0),
            TextInput::make('total_amount')->numeric()->minValue(0)->required()->default(0),
            Textarea::make('description')
                ->label(__('admin.sales.fields.description'))
                ->columnSpanFull(),
        ])->columns(3);
    }
}
