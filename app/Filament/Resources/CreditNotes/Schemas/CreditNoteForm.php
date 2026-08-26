<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class CreditNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('credit_note_number')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('customer_id')
                ->relationship('customer', 'company_name')
                ->searchable()
                ->preload()
                ->required(),
            Select::make('invoice_id')
                ->relationship('invoice', 'invoice_number')
                ->searchable()
                ->preload(),
            DatePicker::make('issue_date')->default(now())->required(),
            TextInput::make('subtotal')->numeric()->minValue(0)->required()->default(0),
            TextInput::make('tax_total')->numeric()->minValue(0)->required()->default(0),
            TextInput::make('grand_total')->numeric()->minValue(0)->required()->default(0),
            Textarea::make('reason')->required()->columnSpanFull(),
        ])->columns(3);
    }
}
