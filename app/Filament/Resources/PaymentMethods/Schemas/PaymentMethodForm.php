<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Schemas;

use App\Enums\PaymentMethodType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('type')
                ->options(array_combine(
                    PaymentMethodType::values(),
                    array_map(static fn (PaymentMethodType $type): string => $type->label(), PaymentMethodType::cases()),
                ))
                ->live()
                ->required()
                ->default(PaymentMethodType::BankTransfer->value)
                ->helperText(static fn (Get $get): ?string => $get('type') === PaymentMethodType::Stripe->value
                    ? 'A Stripe method never requires payment proof and must stay active while used for online checkout.'
                    : null),
            Select::make('chart_account_id')
                ->label('Collection account')
                ->relationship('chartAccount', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                    ->where('is_postable', true)
                    ->where('is_active', true))
                ->searchable()
                ->preload()
                ->required(),
            Toggle::make('is_active')
                ->default(true)
                ->disabled(static fn (Get $get): bool => $get('type') === PaymentMethodType::Stripe->value)
                ->dehydrateStateUsing(static fn (Get $get, ?bool $state): bool => $get('type') === PaymentMethodType::Stripe->value ? true : (bool) $state)
                ->helperText(static fn (Get $get): ?string => $get('type') === PaymentMethodType::Stripe->value
                    ? 'Always active — Stripe is the online provider and cannot be deactivated from here.'
                    : null),
            Toggle::make('requires_proof')
                ->disabled(static fn (Get $get): bool => $get('type') === PaymentMethodType::Stripe->value)
                ->dehydrateStateUsing(static fn (Get $get, ?bool $state): bool => $get('type') === PaymentMethodType::Stripe->value ? false : (bool) $state),
        ])->columns(2);
    }
}
