<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Draft commercial metadata')
                ->description('Only Draft customer orders are editable. Warehouse, lot, serial, reservation and shipment fields are intentionally absent.')
                ->schema([
                    DatePicker::make('scheduled_at')
                        ->label('Requested delivery date')
                        ->native(false),
                    Select::make('payment_term_id')
                        ->label(__('admin.sales.fields.payment_term'))
                        ->relationship('paymentTerm', 'name')
                        ->searchable()
                        ->preload(),
                    Textarea::make('notes')
                        ->label(__('admin.sales.fields.notes'))
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
