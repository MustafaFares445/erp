<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerApprovalStatus;
use App\Enums\CustomerType;
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
                        TextEntry::make('customer_code')->label(__('Customer code')),
                        TextEntry::make('company_name')->label(__('Company name')),
                        TextEntry::make('user.name')->label(__('Account name')),
                        TextEntry::make('user.username')->label(__('Username'))->placeholder(__('Not provided')),
                        TextEntry::make('user.email')->label(__('Account email')),
                        IconEntry::make('is_active')->label(__('Active'))->boolean(),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make(__('Commercial profile'))
                    ->schema([
                        TextEntry::make('customer_type')
                            ->label(__('Customer Type'))
                            ->badge()
                            ->formatStateUsing(static fn (mixed $state): string => $state instanceof CustomerType ? $state->label() : '?'),
                        TextEntry::make('customerGroup.name')->label(__('Customer Group'))->placeholder(__('?')),
                        TextEntry::make('default_currency_code')->label(__('Default Currency'))->placeholder(__('?')),
                        TextEntry::make('defaultPriceList.name')->label(__('Default Price List'))->placeholder(__('?')),
                        TextEntry::make('defaultPaymentTerm.name')->label(__('Default Payment Terms'))->placeholder(__('?')),
                        TextEntry::make('assignedSalesEmployee.name')->label(__('Assigned Sales Employee'))->placeholder(__('?')),
                        TextEntry::make('tax_registration_number')->label(__('Tax Registration Number'))->placeholder(__('?')),
                        TextEntry::make('billing_address')->label(__('Billing Address'))->placeholder(__('?'))->columnSpanFull(),
                    ])
                    ->columns(4),
                Section::make(__('Review & commercial capability'))
                    ->schema([
                        TextEntry::make('approval_status')
                            ->label(__('Approval status'))
                            ->badge()
                            ->formatStateUsing(fn (CustomerApprovalStatus $state): string => $state->label())
                            ->color(fn (CustomerApprovalStatus $state): string => $state->color()),
                        TextEntry::make('reviewedBy.name')->label(__('Reviewed by'))->placeholder(__('Not reviewed yet')),
                        TextEntry::make('reviewed_at')->label(__('Reviewed at'))->dateTime()->placeholder(__('—')),
                        TextEntry::make('review_note')->label(__('Review note'))->placeholder(__('—'))->columnSpanFull(),
                        IconEntry::make('allow_direct_orders')->label(__('Direct orders allowed'))->boolean(),
                        TextEntry::make('deposit_balance')
                            ->label(__('Customer Deposit balance'))
                            ->state(static fn (CustomerProfile $record): float => $record->depositBalance())
                            ->money(),
                    ])
                    ->columns(4),
                Section::make(__('Contact details'))
                    ->schema([
                        TextEntry::make('email')->label(__('Company email'))->placeholder(__('Not provided')),
                        TextEntry::make('phone')->placeholder(__('Not provided')),
                        TextEntry::make('country')->placeholder(__('Not provided')),
                        TextEntry::make('city')->placeholder(__('Not provided')),
                        TextEntry::make('address')->label(__('Address details'))->placeholder(__('Not provided'))->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('Delivery location'))
                    ->schema([
                        TextEntry::make('latitude')->placeholder(__('Not provided')),
                        TextEntry::make('longitude')->placeholder(__('Not provided')),
                        TextEntry::make('map_link')
                            ->label(__('Map'))
                            ->state(static fn (CustomerProfile $record): ?string => $record->latitude !== null && $record->longitude !== null
                                ? (string) __('View on OpenStreetMap')
                                : null)
                            ->placeholder(__('Not provided'))
                            ->url(static fn (CustomerProfile $record): ?string => $record->latitude !== null && $record->longitude !== null
                                ? sprintf('https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=16/%s/%s', $record->latitude, $record->longitude, $record->latitude, $record->longitude)
                                : null, shouldOpenInNewTab: true),
                    ])
                    ->columns(3),
                Section::make(__('Accountant'))
                    ->schema([
                        TextEntry::make('accountant_name')->label(__("Accountant's name"))->placeholder(__('Not provided')),
                        TextEntry::make('accountant_phone')->label(__("Accountant's phone"))->placeholder(__('Not provided')),
                        TextEntry::make('accountant_email')->label(__("Accountant's email"))->placeholder(__('Not provided')),
                    ])
                    ->columns(3),
                Section::make(__('Contact person'))
                    ->schema([
                        IconEntry::make('contact_is_self')->label(__('Uses own account as contact'))->boolean(),
                        TextEntry::make('contact_name')->placeholder(__('Not provided'))
                            ->visible(static fn (CustomerProfile $record): bool => ! $record->contact_is_self),
                        TextEntry::make('contact_phone')->placeholder(__('Not provided'))
                            ->visible(static fn (CustomerProfile $record): bool => ! $record->contact_is_self),
                        TextEntry::make('contact_email')->placeholder(__('Not provided'))
                            ->visible(static fn (CustomerProfile $record): bool => ! $record->contact_is_self),
                    ])
                    ->columns(3),
                Section::make(__('Documents'))
                    ->schema([
                        ImageEntry::make('passport')
                            ->state(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('passport') ?: null),
                        ImageEntry::make('personal_identity')
                            ->label(__('Personal identity'))
                            ->state(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('personal_identity') ?: null),
                        ImageEntry::make('accommodation')
                            ->state(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('accommodation') ?: null),
                        TextEntry::make('license')
                            ->state(static fn (CustomerProfile $record): string => $record->getFirstMedia('license') ? 'Download' : 'Not provided')
                            ->url(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('license') ?: null, shouldOpenInNewTab: true),
                        TextEntry::make('tax_certificate')
                            ->label(__('Tax certificate'))
                            ->state(static fn (CustomerProfile $record): string => $record->getFirstMedia('tax_certificate') ? 'Download' : 'Not provided')
                            ->url(static fn (CustomerProfile $record): ?string => $record->getFirstMediaUrl('tax_certificate') ?: null, shouldOpenInNewTab: true),
                    ])
                    ->columns(3),
            ]);
    }
}
