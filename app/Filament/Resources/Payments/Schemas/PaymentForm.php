<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\Payment;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'company_name')
                    ->default(fn (): ?int => request()->integer('customer_id') ?: null)
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('payment_method_id')
                    ->relationship(
                        'paymentMethod',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                    )
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('amount')->numeric()->minValue(0.01)->step(0.01)->required(),
                TextInput::make('currency')->default('USD')->length(3)->required(),
                DatePicker::make('payment_date')->default(now())->required(),
                TextInput::make('external_reference')->maxLength(255),
                FileUpload::make('payment_proof')
                    ->label('Payment proof')
                    ->disk('local')
                    ->directory('payment-proofs')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                    ->visible(fn (?Payment $record): bool => ! $record instanceof Payment),
                Textarea::make('notes')->columnSpanFull(),
            ])
            ->columns(3)
            ->disabled(fn (?Payment $record): bool => $record instanceof Payment && $record->isPosted());
    }
}
