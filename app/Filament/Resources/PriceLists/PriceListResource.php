<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceLists;

use App\Enums\DashboardRole;
use App\Enums\InventoryPermission;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\PriceLists\Pages\ManagePriceLists;
use App\Filament\Support\CurrencySelect;
use App\Models\CustomerProfile;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('Price Lists');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Price List'))
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(150)
                        ->unique(PriceList::class, 'name', ignoreRecord: true),
                    CurrencySelect::make('currency_code')
                        ->label(__('Currency'))
                        ->required(),
                    Toggle::make('is_active')
                        ->label(__('Active'))
                        ->default(true),
                ])
                ->columns(3),
            Section::make(__('Assignments'))
                ->description(__('Assign the list directly to customers or to reusable customer groups. A customer default price list has priority during resolution.'))
                ->schema([
                    Select::make('customers')
                        ->label(__('Customers'))
                        ->relationship('customers', 'company_name')
                        ->getOptionLabelFromRecordUsing(
                            static fn (CustomerProfile $record): string => $record->company_name ?: $record->customer_code,
                        )
                        ->multiple()
                        ->searchable()
                        ->preload(),
                    Select::make('customerGroups')
                        ->label(__('Customer groups'))
                        ->relationship('customerGroups', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ])
                ->columns(2),
            Section::make(__('Quantity Pricing'))
                ->description(__('Add product-wide prices or variant-specific breaks. The most specific eligible quantity break wins inside this list.'))
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->defaultItems(0)
                        ->schema([
                            Select::make('product_id')
                                ->label(__('Product'))
                                ->options(fn (): array => Product::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->afterStateUpdated(static fn (Set $set): mixed => $set('product_variant_id', null)),
                            Select::make('product_variant_id')
                                ->label(__('Variant'))
                                ->options(function (Get $get): array {
                                    $productId = $get('product_id');

                                    if (! is_numeric($productId)) {
                                        return [];
                                    }

                                    return ProductVariant::query()
                                        ->where('product_id', (int) $productId)
                                        ->where('is_active', true)
                                        ->orderBy('sku')
                                        ->pluck('sku', 'id')
                                        ->all();
                                })
                                ->searchable()
                                ->preload()
                                ->helperText(__('Leave empty to price every variant of the selected product.')),
                            TextInput::make('minimum_quantity')
                                ->label(__('Minimum Quantity'))
                                ->numeric()
                                ->minValue(0.000001)
                                ->helperText(__('Optional base-quantity threshold.')),
                            TextInput::make('price')
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            DatePicker::make('valid_from')
                                ->label(__('Valid From')),
                            DatePicker::make('valid_to')
                                ->label(__('Valid To'))
                                ->afterOrEqual('valid_from'),
                            Toggle::make('is_active')
                                ->label(__('Active'))
                                ->default(true),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('currency_code')->label(__('Currency'))->badge()->sortable(),
                TextColumn::make('items_count')->label(__('Items'))->sortable(),
                TextColumn::make('customers_count')->label(__('Customers'))->sortable(),
                TextColumn::make('customer_groups_count')->label(__('Groups'))->sortable(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => self::canManagePricing()),
                DeleteAction::make()->visible(fn (): bool => self::canManagePricing()),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManagePriceLists::route('/')];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['items', 'customers', 'customerGroups']);
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return self::canViewPricing();
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return self::canManagePricing();
    }

    #[\Override]
    public static function canEdit(Model $record): bool
    {
        return self::canManagePricing();
    }

    #[\Override]
    public static function canDelete(Model $record): bool
    {
        return self::canManagePricing();
    }

    public static function canManagePricing(): bool
    {
        return self::actorCan(InventoryPermission::PricingManage);
    }

    private static function canViewPricing(): bool
    {
        return self::actorCan(InventoryPermission::PricingView);
    }

    private static function actorCan(InventoryPermission $permission): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        if ($actor->isAdmin() && ! $actor->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $actor->can($permission->value);
    }
}
