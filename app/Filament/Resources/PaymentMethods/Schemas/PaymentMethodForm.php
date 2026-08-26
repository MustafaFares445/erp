<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('type')->options([
                'cash' => 'Cash',
                'bank_transfer' => 'Bank transfer',
                'cheque' => 'Cheque',
                'other' => 'Other',
            ])->required()->default('bank_transfer'),
            Select::make('chart_account_id')
                ->relationship('chartAccount', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                    ->where('is_postable', true)
                    ->where('is_active', true))
                ->searchable()
                ->preload()
                ->required(),
            Toggle::make('is_active')->default(true),
            Toggle::make('requires_proof'),
        ])->columns(2);
    }
}
