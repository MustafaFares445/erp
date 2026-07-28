<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants;

use App\Data\Inventory\VariantPricingData;
use App\Enums\InventoryPermission;
use App\Enums\ProductStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariantAttributeValues;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Filament\Resources\ProductVariants\Pages\ViewProductVariant;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\CountryNameResolver;
use App\Services\Inventory\InventoryIdentityGuard;
use App\Services\Inventory\ProductMediaSynchronizer;
use App\Services\Inventory\ProductPricingService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'sku';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.product_variants');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('product_id')->relationship('product', 'name')->required()->searchable()->preload(),
                Select::make('unit_id')->relationship('unit', 'name')->searchable()->preload(),
                TextInput::make('sku')->required()->maxLength(100),
                TextInput::make('barcode')->maxLength(100)->unique(ignoreRecord: true),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
                Select::make('status')->options(self::statusOptions())->default(ProductStatus::Active->value)->required(),
                Toggle::make('track_serials')
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Enable this when every physical unit must be tracked by a unique serial number.'),
                Toggle::make('track_expiry')
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Enable this when the variant has an expiry date that must be monitored.'),
            ]),
            Section::make('Pricing')
                ->visible(self::canViewPricing())
                ->columns(2)
                ->schema([
                    TextInput::make('cost_price')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->disabled(! self::canManagePricing())
                        ->saved(self::canManagePricing())
                        ->hintIcon(Heroicon::QuestionMarkCircle, 'The unit cost is used to calculate the suggested selling price and inventory value.'),
                    TextInput::make('markup_percent')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->disabled(! self::canManagePricing())
                        ->saved(self::canManagePricing())
                        ->hintIcon(Heroicon::QuestionMarkCircle, 'The markup percentage is added to the unit cost when calculating the base price.'),
                    TextInput::make('base_price')
                        ->numeric()
                        ->disabled()
                        ->saved(false),
                    TextInput::make('min_price')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->disabled(! self::canManagePricing())
                        ->saved(self::canManagePricing())
                        ->hintIcon(Heroicon::QuestionMarkCircle, 'This prevents selling the variant below the approved minimum price.'),
                ]),
            Repeater::make('attributeAssignments')
                ->relationship()
                ->defaultItems(0)
                ->schema([
                    Select::make('product_attribute_value_id')->relationship('attributeValue', 'value')->required()->searchable()->preload(),
                ])
                ->columnSpanFull(),
            FileUpload::make('images')
                ->disk('public')
                ->directory('product-images')
                ->visibility('public')
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->multiple()
                ->reorderable()
                ->appendFiles()
                ->maxSize(5120)
                ->preventFilePathTampering(
                    allowFilePathUsing: static function (?ProductVariant $record, string $file): bool {
                        if (! $record instanceof ProductVariant) {
                            return false;
                        }

                        return $record->getMedia('images')->contains(
                            static fn (Media $media): bool => $media->getPathRelativeToRoot() === $file,
                        );
                    },
                )
                ->afterStateHydrated(static function (FileUpload $component, ?ProductVariant $record): void {
                    if (! $record instanceof ProductVariant) {
                        return;
                    }

                    $component->state(
                        $record->getMedia('images')
                            ->map(static fn (Media $media): string => $media->getPathRelativeToRoot())
                            ->all(),
                    );
                })
                ->columnSpanFull(),
        ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('sku'),
                TextEntry::make('barcode'),
                TextEntry::make('name'),
                TextEntry::make('name_ar')->label('Arabic name'),
                TextEntry::make('product.name'),
                TextEntry::make('unit.symbol'),
                TextEntry::make('status')->badge(),
                TextEntry::make('base_price')
                    ->money('USD')
                    ->visible(self::canViewPricing()),
            ]),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('main_image')
                    ->getStateUsing(static fn (ProductVariant $record): ?string => $record->mainImageUrl()),
                TextColumn::make('sku')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('product.name')->searchable()->sortable(),
                TextColumn::make('unit.symbol')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                ToggleColumn::make('is_active'),
                TextColumn::make('base_price')->money('USD')->sortable()->visible(self::canViewPricing()),
                IconColumn::make('track_serials')->boolean(),
                IconColumn::make('track_expiry')->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
                SelectFilter::make('product_id')->relationship('product', 'name')->searchable()->preload(),
                TernaryFilter::make('track_serials'),
                TernaryFilter::make('track_expiry'),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), self::editAction(), DeleteAction::make(), RestoreAction::make()]);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'sku',
            'barcode',
            'name',
            'name_ar',
            'product.name',
            'product.name_ar',
            'product.brand.name',
            'product.brand.name_ar',
            'product.category.name',
            'product.category.name_ar',
            'supplierReferences.supplier.name',
            'supplierReferences.supplier_name',
            'supplierReferences.supplier_item_number',
            'supplierReferences.manufacturer',
            'supplierReferences.country_code',
        ];
    }

    #[\Override]
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof ProductVariant) {
            return [];
        }

        return [
            'Product' => $record->product->name ?? 'Unknown product',
            'Barcode' => $record->barcode ?? 'No barcode',
        ];
    }

    /** @param Builder<ProductVariant> $query */
    #[\Override]
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $query->where(function (Builder $searchQuery) use ($search): void {
            parent::applyGlobalSearchAttributeConstraints($searchQuery, $search);
            $countryCodes = app(CountryNameResolver::class)->matchingCodes($search);

            if ($countryCodes !== []) {
                $searchQuery->orWhereHas(
                    'supplierReferences',
                    fn (Builder $referenceQuery): Builder => $referenceQuery->whereIn('country_code', $countryCodes),
                );
            }
        });
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(static function (
                array $data,
                ProductPricingService $productPricingService,
                InventoryIdentityGuard $inventoryIdentityGuard,
            ): Model {
                $actor = self::actor();
                $inventoryIdentityGuard->ensureSkuAvailable(self::sku($data));
                $images = Arr::wrap(Arr::pull($data, 'images', []));

                return DB::transaction(function () use ($data, $images, $productPricingService, $actor): ProductVariant {
                    $variant = ProductVariant::query()->create(self::catalogData($data));

                    if (self::containsPricingData($data)) {
                        $variant = $productPricingService->updateVariantPricing(
                            variant: $variant,
                            pricing: VariantPricingData::from([
                                'costPrice' => $data['cost_price'] ?? null,
                                'markupPercent' => $data['markup_percent'] ?? null,
                                'minimumPrice' => $data['min_price'] ?? null,
                            ]),
                            actor: $actor,
                        );
                    }

                    app(ProductMediaSynchronizer::class)->sync($variant, $images);

                    return $variant;
                }, attempts: 5);
            });
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()
            ->using(static function (
                ProductVariant $record,
                array $data,
                ProductPricingService $productPricingService,
                InventoryIdentityGuard $inventoryIdentityGuard,
            ): Model {
                $actor = self::actor();
                $inventoryIdentityGuard->ensureSkuAvailable(self::sku($data), self::recordId($record));
                $images = Arr::wrap(Arr::pull($data, 'images', []));

                return DB::transaction(function () use ($record, $data, $images, $productPricingService, $actor): ProductVariant {
                    $record->update(self::catalogData($data));

                    if (self::containsPricingData($data)) {
                        $record = $productPricingService->updateVariantPricing(
                            variant: $record,
                            pricing: VariantPricingData::from([
                                'costPrice' => $data['cost_price'] ?? null,
                                'markupPercent' => $data['markup_percent'] ?? null,
                                'minimumPrice' => $data['min_price'] ?? null,
                            ]),
                            actor: $actor,
                        );
                    }

                    app(ProductMediaSynchronizer::class)->sync($record, $images);

                    return $record->refresh();
                }, attempts: 5);
            });
    }

    public static function canViewPricing(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(InventoryPermission::PricingView->value);
    }

    public static function canManagePricing(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $actor->can(InventoryPermission::PricingView->value)
            && $actor->can(InventoryPermission::PricingManage->value);
    }

    public static function parentProductVariantsUrl(ProductVariant $variant): string
    {
        return ProductResource::getUrl('variants', ['record' => $variant->product_id]);
    }

    /** @return array<NavigationItem> */
    #[\Override]
    public static function getRecordSubNavigation(Page $page): array
    {
        if (! $page instanceof ViewRecord && ! $page instanceof EditRecord && ! $page instanceof ManageRelatedRecords) {
            return [];
        }

        return array_merge(
            ViewProductVariant::getNavigationItems(['record' => $page->getRecord()]),
            ManageProductVariantAttributeValues::getNavigationItems(['record' => $page->getRecord()]),
        );
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $status): array => [$status->value => $status->name])->all();
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private static function catalogData(array $data): array
    {
        $catalogData = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (in_array($key, ['cost_price', 'markup_percent', 'base_price', 'min_price'], true)) {
                continue;
            }

            $catalogData[$key] = $value;
        }

        return $catalogData;
    }

    /** @param array<mixed> $data */
    private static function containsPricingData(array $data): bool
    {
        return Arr::hasAny($data, ['cost_price', 'markup_percent', 'min_price']);
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated pricing actor is required.');
        }

        return $actor;
    }

    /** @param array<mixed> $data */
    private static function sku(array $data): string
    {
        $sku = $data['sku'] ?? null;

        if (! is_string($sku) || $sku === '') {
            throw new LogicException('A product variant SKU is required.');
        }

        return $sku;
    }

    private static function recordId(ProductVariant $variant): int
    {
        $key = $variant->getKey();

        if (! is_int($key)) {
            throw new LogicException('Product variants must use integer identifiers.');
        }

        return $key;
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageProductVariants::route('/'),
            'view' => ViewProductVariant::route('/{record}'),
            'attributes' => ManageProductVariantAttributeValues::route('/{record}/attributes'),
        ];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['media', 'product.media']);
    }
}
