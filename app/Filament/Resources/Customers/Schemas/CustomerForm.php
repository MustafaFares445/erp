<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\UserType;
use App\Filament\Forms\Components\CustomerLocationPicker;
use App\Models\CustomerProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('user_id')
                            ->label('Customer account')
                            ->relationship(
                                name: 'user',
                                titleAttribute: 'name',
                                modifyQueryUsing: static fn (Builder $query): Builder => $query
                                    ->where('user_type', UserType::Customer->value),
                            )
                            ->searchable()
                            ->preload()
                            ->required()
                            ->unique(CustomerProfile::class, 'user_id', ignoreRecord: true)
                            ->disabledOn('edit'),
                        TextInput::make('customer_code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        TextInput::make('company_name')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->default(false),
                    ]),
                Section::make('Contact details')
                    ->schema([
                        TextInput::make('email')
                            ->label('Company email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->maxLength(50),
                        TextInput::make('country')
                            ->maxLength(255),
                        TextInput::make('city')
                            ->maxLength(255),
                        Textarea::make('address')
                            ->label('Address details')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Delivery location')
                    ->schema([
                        CustomerLocationPicker::make('latitude')
                            ->label('Delivery location')
                            ->rules(['nullable', 'numeric', 'between:-90,90'])
                            ->columnSpanFull(),
                        Hidden::make('longitude')
                            ->rules(['nullable', 'numeric', 'between:-180,180']),
                    ]),
                Section::make('Delivery addresses')
                    ->description('Manage active delivery destinations without replacing the legacy profile address.')
                    ->schema([
                        Repeater::make('deliveryAddresses')
                            ->relationship()
                            ->defaultItems(0)
                            ->schema([
                                TextInput::make('label')->maxLength(100)->required(),
                                Textarea::make('address')->columnSpanFull()->required(),
                                TextInput::make('country')->maxLength(255),
                                TextInput::make('city')->maxLength(255),
                                TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->required(),
                                TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->required(),
                                TextInput::make('contact_name')->maxLength(255),
                                TextInput::make('contact_phone')->maxLength(50),
                                Toggle::make('is_active')->default(true),
                                Toggle::make('is_default')->default(false),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Accountant')
                    ->description('Optional, but recommended for invoicing correspondence.')
                    ->schema([
                        TextInput::make('accountant_name')
                            ->label('Accountant\'s name')
                            ->maxLength(255)
                            ->hintIcon(Heroicon::ExclamationTriangle, 'Not required, but helps route invoicing questions correctly.')
                            ->hintColor('warning'),
                        TextInput::make('accountant_phone')
                            ->label('Accountant\'s phone')
                            ->tel()
                            ->maxLength(50)
                            ->hintIcon(Heroicon::ExclamationTriangle, 'Not required, but helps route invoicing questions correctly.')
                            ->hintColor('warning'),
                        TextInput::make('accountant_email')
                            ->label('Accountant\'s email')
                            ->email()
                            ->maxLength(255)
                            ->hintIcon(Heroicon::ExclamationTriangle, 'Not required, but helps route invoicing questions correctly.')
                            ->hintColor('warning'),
                    ])
                    ->columns(3),
                Section::make('Contact person')
                    ->schema([
                        Toggle::make('contact_is_self')
                            ->label('Use my own account as the contact')
                            ->default(true)
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('contact_name')
                            ->maxLength(255)
                            ->visible(static fn (Get $get): bool => ! $get('contact_is_self'))
                            ->required(static fn (Get $get): bool => ! $get('contact_is_self')),
                        TextInput::make('contact_phone')
                            ->maxLength(50)
                            ->visible(static fn (Get $get): bool => ! $get('contact_is_self'))
                            ->required(static fn (Get $get): bool => ! $get('contact_is_self')),
                        TextInput::make('contact_email')
                            ->email()
                            ->maxLength(255)
                            ->visible(static fn (Get $get): bool => ! $get('contact_is_self'))
                            ->required(static fn (Get $get): bool => ! $get('contact_is_self')),
                    ])
                    ->columns(3),
                Section::make('Documents')
                    ->schema([
                        self::documentUpload('license', 'License')->acceptedFileTypes(self::documentMimeTypes()),
                        self::documentUpload('tax_certificate', 'Tax certificate')->acceptedFileTypes(self::documentMimeTypes()),
                        self::documentUpload('passport', 'Passport')->image(),
                        self::documentUpload('personal_identity', 'Personal identity')->image(),
                        self::documentUpload('accommodation', 'Accommodation')->image(),
                    ])
                    ->columns(2),
            ]);
    }

    private static function documentUpload(string $collection, string $label): FileUpload
    {
        return FileUpload::make($collection)
            ->label($label)
            ->disk('local')
            ->directory('customer-documents/'.$collection)
            ->visibility('private')
            ->maxSize(5120)
            ->preventFilePathTampering(
                allowFilePathUsing: static function (?CustomerProfile $record, string $file) use ($collection): bool {
                    if (! $record instanceof CustomerProfile) {
                        return false;
                    }

                    return $record->getFirstMedia($collection)?->getPathRelativeToRoot() === $file;
                },
            )
            ->afterStateHydrated(static function (FileUpload $component, ?CustomerProfile $record) use ($collection): void {
                if (! $record instanceof CustomerProfile) {
                    return;
                }

                $media = $record->getFirstMedia($collection);

                $component->state($media instanceof Media ? [$media->getPathRelativeToRoot()] : []);
            });
    }

    /** @return array<string> */
    private static function documentMimeTypes(): array
    {
        return ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    }
}
