<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('customer_code')->label('Customer code'),
                TextEntry::make('company_name')->label('Company name'),
                TextEntry::make('user.name')->label('Account name'),
                TextEntry::make('user.email')->label('Account email'),
                TextEntry::make('address'),
                IconEntry::make('is_active')->label('Active')->boolean(),
                TextEntry::make('created_at')->dateTime(),
            ]);
    }
}
