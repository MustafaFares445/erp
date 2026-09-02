<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Enums\CreditNoteReason;
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
                ->preload()
                ->default(fn (): ?int => request()->integer('invoice_id') ?: null),
            DatePicker::make('issue_date')->default(now())->required(),
            Select::make('reason_category')
                ->label(__('admin.sales.fields.reason_category'))
                ->options(array_combine(
                    array_map(fn (CreditNoteReason $reason): string => $reason->value, CreditNoteReason::cases()),
                    array_map(fn (CreditNoteReason $reason): string => $reason->label(), CreditNoteReason::cases()),
                ))
                ->default(CreditNoteReason::Other->value)
                ->required(),
            Textarea::make('reason')->required()->columnSpanFull(),
            TextInput::make('subtotal')->numeric()->disabled()->dehydrated(false),
            TextInput::make('tax_total')->numeric()->disabled()->dehydrated(false),
            TextInput::make('grand_total')->numeric()->disabled()->dehydrated(false),
        ])->columns(3);
    }
}
