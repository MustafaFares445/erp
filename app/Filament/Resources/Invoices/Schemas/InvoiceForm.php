<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\ProductVariant;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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
                ->required(fn (string $operation): bool => $operation !== 'create')
                ->hidden(fn (string $operation): bool => $operation === 'create')
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
            Repeater::make('lines')
                ->label(__('Invoice lines'))
                ->helperText(__('Add at least one product or service line. Totals and invoice number are calculated when saved.'))
                ->visible(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    Select::make('product_variant_id')
                        ->label(__('Product variant (optional for service)'))
                        ->options(fn (): array => ProductVariant::query()->orderBy('sku')->limit(50)->pluck('sku', 'id')->all())
                        ->getSearchResultsUsing(static fn (string $search): array => ProductVariant::query()
                            ->where(static fn ($q) => $q->where('sku', 'like', '%'.$search.'%')
                                ->orWhere('name', 'like', '%'.$search.'%'))
                            ->orderBy('sku')->limit(50)->pluck('sku', 'id')->all())
                        ->getOptionLabelUsing(static fn (mixed $value): ?string => is_numeric($value)
                            ? ProductVariant::query()->whereKey((int) $value)->value('sku')
                            : null)
                        ->searchable()
                        ->preload()
                        ->optionsLimit(50),
                    TextInput::make('description')
                        ->label(__('Description'))
                        ->helperText(__('Required for service lines without a product variant.'))
                        ->maxLength(1000),
                    TextInput::make('quantity')->label(__('Quantity'))
                        ->numeric()->minValue(0.000001)->required()->default(1),
                    TextInput::make('unit_price')->label(__('Unit price'))
                        ->numeric()->minValue(0)
                        ->helperText(__('Leave blank to use product pricing; required for service lines.')),
                    TextInput::make('tax_amount')->label(__('Tax amount'))
                        ->numeric()->minValue(0)->required()->default(0),
                ])
                ->columns(2)
                ->minItems(1)
                ->defaultItems(1)
                ->columnSpanFull(),
            TextInput::make('subtotal')->numeric()->minValue(0)->required()->default(0)
                ->hidden(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('tax_total')->numeric()->minValue(0)->required()->default(0)
                ->hidden(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('total_amount')->numeric()->minValue(0)->required()->default(0)
                ->hidden(fn (string $operation): bool => $operation === 'create'),
            Textarea::make('description')
                ->label(__('admin.sales.fields.description'))
                ->columnSpanFull(),
        ])->columns(3);
    }
}
