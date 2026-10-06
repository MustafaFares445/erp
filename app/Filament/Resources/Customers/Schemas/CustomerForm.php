<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Enums\UserType;
use App\Filament\Forms\Components\CustomerLocationPicker;
use App\Filament\Support\CurrencySelect;
use App\Models\CustomerGroup;
use App\Models\CustomerProfile;
use App\Models\PaymentTerm;
use App\Models\PriceList;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Account'))
                    ->description(__('Creates the login the customer will use. The username and login email cannot be changed here afterwards.'))
                    ->schema([
                        TextInput::make('account_name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->visibleOn('create'),
                        TextInput::make('username')
                            ->required()
                            ->alphaDash()
                            ->maxLength(50)
                            ->unique(User::class, 'username')
                            ->visibleOn('create'),
                        TextInput::make('login_email')
                            ->label(__('Login email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(User::class, 'email')
                            ->visibleOn('create'),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->visibleOn('create'),
                        TextInput::make('password_confirmation')
                            ->label(__('Confirm password'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->same('password')
                            ->dehydrated(false)
                            ->visibleOn('create'),
                        Placeholder::make('account_username')
                            ->label(__('Username'))
                            ->content(static function (?CustomerProfile $record): string {
                                $user = $record?->user;

                                return $user instanceof User ? ($user->username ?? '—') : '—';
                            })
                            ->visibleOn('edit'),
                        Placeholder::make('account_login_email')
                            ->label(__('Login email'))
                            ->content(static function (?CustomerProfile $record): string {
                                $user = $record?->user;

                                return $user instanceof User ? ($user->email ?? '—') : '—';
                            })
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
                Section::make()
                    ->schema([
                        TextInput::make('customer_code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),
                        TextInput::make('company_name')
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->default(true),
                    ]),
                Section::make(__('Commercial profile'))
                    ->description(__('Default B2B pricing, payment, tax, and account ownership used by Sales documents.'))
                    ->schema([
                        Select::make('customer_type')
                            ->label(__('Customer Type'))
                            ->options(CustomerType::options())
                            ->default(CustomerType::Other->value)
                            ->required(),
                        Select::make('customer_group_id')
                            ->label(__('Customer Group'))
                            ->options(fn (): array => CustomerGroup::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload(),
                        CurrencySelect::make('default_currency_code')
                            ->label(__('Default Currency'))
                            ->live(),
                        Select::make('default_price_list_id')
                            ->label(__('Default Price List'))
                            ->options(function (Get $get): array {
                                $query = PriceList::query()->active()->orderBy('name');
                                $currency = $get('default_currency_code');

                                if (is_string($currency) && $currency !== '') {
                                    $query->where('currency_code', mb_strtoupper($currency));
                                }

                                return $query->pluck('name', 'id')->all();
                            })
                            ->searchable()
                            ->preload(),
                        Select::make('default_payment_term_id')
                            ->label(__('Default Payment Terms'))
                            ->options(fn (): array => PaymentTerm::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),
                        Select::make('assigned_sales_employee_id')
                            ->label(__('Assigned Sales Employee'))
                            ->options(fn (): array => User::query()
                                ->where('user_type', UserType::Employee)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload(),
                        Textarea::make('billing_address')
                            ->label(__('Billing Address'))
                            ->columnSpanFull(),
                        TextInput::make('tax_registration_number')
                            ->label(__('Tax Registration Number'))
                            ->maxLength(100),
                    ])
                    ->columns(2),
                Section::make(__('Commercial capability'))
                    ->description(__('Whether this customer may place orders directly, bypassing the default quotation-led flow.'))
                    ->schema([
                        Toggle::make('allow_direct_orders')
                            ->label(__('Allow direct orders'))
                            ->default(false),
                    ]),
                Section::make(__('Review status'))
                    ->visibleOn('edit')
                    ->schema([
                        Placeholder::make('approval_status_display')
                            ->label(__('Approval status'))
                            ->content(static fn (?CustomerProfile $record): string => $record instanceof CustomerProfile ? $record->approval_status->label() : '—'),
                        Placeholder::make('reviewed_by_display')
                            ->label(__('Reviewed by'))
                            ->content(static function (?CustomerProfile $record): string {
                                $reviewer = $record?->reviewedBy;

                                return $reviewer instanceof User ? $reviewer->name : '—';
                            }),
                        Placeholder::make('reviewed_at_display')
                            ->label(__('Reviewed at'))
                            ->content(static fn (?CustomerProfile $record): string => $record?->reviewed_at?->toDayDatetimeString() ?? '—'),
                        Placeholder::make('review_note_display')
                            ->label(__('Review note'))
                            ->content(static fn (?CustomerProfile $record): string => $record instanceof CustomerProfile ? ($record->review_note ?? '—') : '—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make(__('Contact details'))
                    ->schema([
                        TextInput::make('email')
                            ->label(__('Company email'))
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->maxLength(50),
                        TextInput::make('country')
                            ->maxLength(255),
                        TextInput::make('city')
                            ->maxLength(255),
                        Textarea::make('address')
                            ->label(__('Address details'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('Delivery location'))
                    ->schema([
                        CustomerLocationPicker::make('latitude')
                            ->label(__('Delivery location'))
                            ->rules(['nullable', 'numeric', 'between:-90,90'])
                            ->columnSpanFull(),
                        Hidden::make('longitude')
                            ->rules(['nullable', 'numeric', 'between:-180,180']),
                    ]),
                Section::make(__('Delivery addresses'))
                    ->description(__('Manage active delivery destinations without replacing the legacy profile address.'))
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
                Section::make(__('Accountant'))
                    ->description(__('Optional, but recommended for invoicing correspondence.'))
                    ->schema([
                        TextInput::make('accountant_name')
                            ->label(__("Accountant's name"))
                            ->maxLength(255)
                            ->hintIcon(Heroicon::ExclamationTriangle, __('Not required, but helps route invoicing questions correctly.'))
                            ->hintColor('warning'),
                        TextInput::make('accountant_phone')
                            ->label(__("Accountant's phone"))
                            ->tel()
                            ->maxLength(50)
                            ->hintIcon(Heroicon::ExclamationTriangle, __('Not required, but helps route invoicing questions correctly.'))
                            ->hintColor('warning'),
                        TextInput::make('accountant_email')
                            ->label(__("Accountant's email"))
                            ->email()
                            ->maxLength(255)
                            ->hintIcon(Heroicon::ExclamationTriangle, __('Not required, but helps route invoicing questions correctly.'))
                            ->hintColor('warning'),
                    ])
                    ->columns(3),
                Section::make(__('Contact person'))
                    ->schema([
                        Toggle::make('contact_is_self')
                            ->label(__('Use my own account as the contact'))
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
                Section::make(__('Documents'))
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
