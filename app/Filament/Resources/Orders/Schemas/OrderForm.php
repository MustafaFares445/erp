<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Services\Sales\QuotationConversionService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

/**
 * Governs the Edit page only (FR-028) — the order's totals and lines are
 * copied verbatim at conversion by {@see QuotationConversionService}
 * and are not recomputed here, so nothing that would disturb them is exposed
 * as an editable field. Creation still goes through {@see CreateOrder}'s
 * own wizard, which never touches this schema.
 */
final class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('payment_term_id')
                    ->label(__('admin.sales.fields.payment_term'))
                    ->relationship('paymentTerm', 'name')
                    ->searchable()
                    ->preload(),
                Textarea::make('notes')
                    ->label(__('admin.sales.fields.notes'))
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
