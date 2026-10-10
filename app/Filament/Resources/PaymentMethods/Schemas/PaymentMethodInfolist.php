<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentMethods\Schemas;

use App\Enums\PaymentMethodType;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class PaymentMethodInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            TextEntry::make('type')
                ->badge()
                ->formatStateUsing(static fn (PaymentMethodType $state): string => $state->label()),
            TextEntry::make('chartAccount.name')->label(__('Collection account')),
            IconEntry::make('is_active')->boolean(),
            IconEntry::make('requires_proof')->boolean(),
        ])->columns(2);
    }
}
