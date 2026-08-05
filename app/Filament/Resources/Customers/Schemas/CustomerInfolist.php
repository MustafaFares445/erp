<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\CustomerProfile;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('customer_code')->label('Customer code'),
                        TextEntry::make('company_name')->label('Company name'),
                        TextEntry::make('user.name')->label('Account name'),
                        TextEntry::make('user.username')->label('Username')->placeholder('Not provided'),
                        TextEntry::make('user.email')->label('Account email'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make('Contact details')
                    ->schema([
                        TextEntry::make('email')->label('Company email')->placeholder('Not provided'),
                        TextEntry::make('phone')->placeholder('Not provided'),
                        TextEntry::make('country')->placeholder('Not provided'),
                        TextEntry::make('city')->placeholder('Not provided'),
                        TextEntry::make('address')->label('Address details')->placeholder('Not provided')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Delivery location')
                    ->schema([
                        TextEntry::make('latitude')->placeholder('Not provided'),
                        TextEntry::make('longitude')->placeholder('Not provided'),
                        TextEntry::make('map_link')
                            ->label('Map')
                            ->state(static fn (CustomerProfile $record): ?string => $record->latitude !== null && $record->longitude !== null
                                ? 'View on OpenStreetMap'
                                : null)
                            ->placeholder('Not provided')
                            ->url(static fn (CustomerProfile $record): ?string => $record->latitude !== null && $record->longitude !== null
                                ? "https://www.openstreetmap.org/?mlat={$record->latitude}&mlon={$record->longitude}#map=16/{$record->latitude}/{$record->longitude}"
                                : null, shouldOpenInNewTab: true),
                    ])
                    ->columns(3),
                Section::make('Accountant')
                    ->schema([
                        TextEntry::make('accountant_name')->label('Accountant\'s name')->placeholder('Not provided'),
                        TextEntry::make('accountant_phone')->label('Accountant\'s phone')->placeholder('Not provided'),
                        TextEntry::make('accountant_email')->label('Accountant\'s email')->placeholder('Not provided'),
                    ])
                    ->columns(3),
                Section::make('Contact person')
                    ->schema([
                        IconEntry::make('contact_is_self')->label('Uses own account as contact')->boolean(),
                        TextEntry::make('contact_name')->placeholder('Not provided')
                            ->visible(static fn (CustomerProfile $record): bool => ! $record->contact_is_self),
                        TextEntry::make('contact_phone')->placeholder('Not provided')
                            ->visible(static fn (CustomerProfile $record): bool => ! $record->contact_is_self),
                        TextEntry::make('contact_email')->placeholder('Not provided')
                            ->visible(static fn (CustomerProfile $record): bool => ! $record->contact_is_self),
                    ])
                    ->columns(3),
                Section::make('Documents')
                    ->schema([
                        ImageEntry::make('passport')
                            ->state(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('passport') ?: null),
                        ImageEntry::make('personal_identity')
                            ->label('Personal identity')
                            ->state(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('personal_identity') ?: null),
                        ImageEntry::make('accommodation')
                            ->state(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('accommodation') ?: null),
                        TextEntry::make('license')
                            ->state(static fn (CustomerProfile $record): string => $record->getFirstMedia('license') ? 'Download' : 'Not provided')
                            ->url(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('license') ?: null, shouldOpenInNewTab: true),
                        TextEntry::make('tax_certificate')
                            ->label('Tax certificate')
                            ->state(static fn (CustomerProfile $record): string => $record->getFirstMedia('tax_certificate') ? 'Download' : 'Not provided')
                            ->url(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('tax_certificate') ?: null, shouldOpenInNewTab: true),
                    ])
                    ->columns(3),
            ]);
    }
}
