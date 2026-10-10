<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\InventoryReturnStatus;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
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
                ->default(fn (): ?int => request()->integer('customer_id') ?: null)
                ->live()
                ->afterStateUpdated(static fn (Set $set): mixed => $set('invoice_id', null))
                ->required(),
            Select::make('invoice_id')
                ->relationship(
                    name: 'invoice',
                    titleAttribute: 'invoice_number',
                    modifyQueryUsing: static fn (Builder $query, Get $get): Builder => $query
                        ->where('customer_id', (int) $get('customer_id'))
                        ->whereNotNull('issued_at'),
                )
                ->searchable()
                ->preload()
                ->optionsLimit(50)
                ->disabled(static fn (Get $get): bool => blank($get('customer_id')))
                ->helperText(static fn (Get $get): string => blank($get('customer_id'))
                    ? __('Select a customer first')
                    : __('Only issued invoices belonging to the selected customer are shown. Leave blank for a standalone credit note.'))
                ->default(fn (): ?int => request()->integer('invoice_id') ?: null),
            DatePicker::make('issue_date')->default(now())->required(),
            Select::make('reason_category')
                ->label(__('admin.sales.fields.reason_category'))
                ->options(array_combine(
                    array_map(fn (CreditNoteReason $reason): string => $reason->value, CreditNoteReason::cases()),
                    array_map(fn (CreditNoteReason $reason): string => $reason->label(), CreditNoteReason::cases()),
                ))
                ->default(fn (): string => (string) request()->query('reason_category', CreditNoteReason::Other->value))
                ->required(),
            Radio::make('stock_consequence')
                ->label(__('admin.sales.fields.stock_consequence'))
                ->options(collect(CreditNoteStockConsequence::cases())
                    ->mapWithKeys(fn (CreditNoteStockConsequence $consequence): array => [
                        $consequence->value => $consequence->label(),
                    ])
                    ->all())
                ->default(fn (): string => (string) request()->query(
                    'stock_consequence',
                    CreditNoteStockConsequence::NotApplicable->value,
                ))
                ->live()
                ->required()
                ->columnSpanFull(),
            Select::make('inventory_return_id')
                ->label(__('admin.sales.fields.inventory_return'))
                ->options(function (Get $get): array {
                    $query = InventoryReturn::query()
                        ->where('status', InventoryReturnStatus::Posted->value)
                        ->whereNotNull('customer_id');
                    $customerId = $get('customer_id');

                    if (! is_numeric($customerId)) {
                        return [];
                    }

                    $query->where('customer_id', (int) $customerId);

                    return $query
                        ->orderByDesc('posted_at')
                        ->limit(200)
                        ->pluck('return_number', 'id')
                        ->all();
                })
                ->default(fn (): ?int => request()->integer('inventory_return_id') ?: null)
                ->searchable()
                ->preload()
                ->disabled(fn (Get $get): bool => blank($get('customer_id')))
                ->visible(fn (Get $get): bool => $get('stock_consequence') === CreditNoteStockConsequence::GoodsReturned->value)
                ->required(fn (Get $get): bool => $get('stock_consequence') === CreditNoteStockConsequence::GoodsReturned->value),
            Textarea::make('reason')
                ->default(fn (): ?string => is_string(request()->query('reason')) ? request()->query('reason') : null)
                ->required()
                ->columnSpanFull(),
            TextInput::make('subtotal')->numeric()->disabled()->dehydrated(false),
            TextInput::make('tax_total')->numeric()->disabled()->dehydrated(false),
            TextInput::make('grand_total')->numeric()->disabled()->dehydrated(false),
        ])->columns(3);
    }
}
