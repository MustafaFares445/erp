<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('payment_number')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('customer_id')->relationship('customer', 'company_name')->searchable()->preload()->required(),
            Select::make('payment_method_id')
                ->relationship('paymentMethod', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true))
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('amount')->numeric()->minValue(0.01)->step(0.01)->required(),
            DatePicker::make('payment_date')->default(now())->required(),
            TextInput::make('external_reference')->maxLength(255),
            Textarea::make('notes')->columnSpanFull(),
        ])->columns(3);
    }
}
