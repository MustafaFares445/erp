<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStockConsequence;
use App\Enums\InventoryReturnStatus;
use App\Models\InventoryReturn;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                ->default(fn (): string => request()->query('reason_category', CreditNoteReason::Other->value))
                ->required(),
            Radio::make('stock_consequence')
                ->label(__('admin.sales.fields.stock_consequence'))
                ->options(collect(CreditNoteStockConsequence::cases())
                    ->mapWithKeys(fn (CreditNoteStockConsequence $consequence): array => [
                        $consequence->value => $consequence->label(),
                    ])
                    ->all())
                ->default(fn (): string => request()->query(
                    'stock_consequence',
                    CreditNoteStockConsequence::NotApplicable->value,
                ))
                ->live()
                ->required()
                ->columnSpanFull(),
            Select::make('inventory_return_id')
                ->label(__('admin.sales.fields.inventory_return'))
                ->options(fn (Get $get): array => InventoryReturn::query()
                    ->where('status', InventoryReturnStatus::Posted->value)
                    ->whereNotNull('customer_id')
                    ->when(
                        is_numeric($get('customer_id')),
                        fn (Builder $query): Builder => $query->where('customer_id', (int) $get('customer_id')),
                    )
                    ->orderByDesc('posted_at')
                    ->limit(200)
                    ->pluck('return_number', 'id')
                    ->all())
                ->default(fn (): ?int => request()->integer('inventory_return_id') ?: null)
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => $get('stock_consequence') === CreditNoteStockConsequence::GoodsReturned->value)
                ->required(fn (Get $get): bool => $get('stock_consequence') === CreditNoteStockConsequence::GoodsReturned->value),
            Textarea::make('reason')
                ->default(fn (): ?string => request()->query('reason'))
                ->required()
                ->columnSpanFull(),
            TextInput::make('subtotal')->numeric()->disabled()->dehydrated(false),
            TextInput::make('tax_total')->numeric()->disabled()->dehydrated(false),
            TextInput::make('grand_total')->numeric()->disabled()->dehydrated(false),
        ])->columns(3);
    }
}
