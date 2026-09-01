<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants;

use App\Data\Inventory\VariantPricingData;
use App\Enums\InventoryPermission;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariantAttributeValues;
use App\Filament\Resources\ProductVariants\Pages\ManageProductVariants;
use App\Filament\Resources\ProductVariants\Pages\ViewProductVariant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\CountryNameResolver;
use App\Services\Inventory\InventoryIdentityGuard;
use App\Services\Inventory\ProductMediaSynchronizer;
use App\Services\Inventory\ProductPricingService;
use App\Services\Inventory\ProductTypeGuard;
use App\Services\Inventory\ProductVariantUomService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
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
                // Live so the tracking summary and the grain section react to the chosen product.
                Select::make('product_id')->relationship('product', 'name')->required()->searchable()->preload()->live(),
                Repeater::make('variant_uoms')
                    ->label('Variant units of measure')
                    ->helperText('Define one base unit and any explicit purchase, sale, or display conversions. Stock is always stored in the base unit.')
                    ->schema([
                        Select::make('unit_id')
                            ->label('Unit')
                            ->options(static fn (): array => Unit::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('factor_to_base')->label('Factor to base')->inputMode('decimal')->required(),
                        TextInput::make('rounding_increment')->label('Rounding increment')->inputMode('decimal')->required(),
                        Toggle::make('is_base')->label('Base unit')->distinct(),
                        Toggle::make('is_purchase')->label('Purchase'),
                        Toggle::make('is_sale')->label('Sale'),
                        Toggle::make('is_display')->label('Display'),
                        Toggle::make('permits_cross_family_conversion')->label('Explicit cross-family conversion'),
                        Toggle::make('is_active')->label('Active')->default(true),
                    ])
                    ->columns(3)
                    ->default([
                        [
                            'is_base' => true,
                            'is_purchase' => true,
                            'is_sale' => true,
                            'is_display' => true,
                            'factor_to_base' => '1',
                            'rounding_increment' => '0.001',
                            'is_active' => true,
                        ],
                    ])
                    ->minItems(1)
                    ->addActionLabel('Add variant unit')
                    ->disabled(static fn (?ProductVariant $record): bool => $record?->hasStockHistory() === true)
                    ->dehydrated(static fn (?ProductVariant $record): bool => $record?->hasStockHistory() !== true)
                    ->afterStateHydrated(static function (Repeater $component, ?ProductVariant $record): void {
                        if (! $record instanceof ProductVariant) {
                            return;
                        }

                        $component->state($record->variantUnits()
                            ->orderByDesc('is_base')
                            ->orderBy('unit_id')
                            ->get([
                                'unit_id',
                                'is_base',
                                'is_purchase',
                                'is_sale',
                                'is_display',
                                'factor_to_base',
                                'rounding_increment',
                                'permits_cross_family_conversion',
                                'is_active',
                            ])
                            ->map(static fn (ProductVariantUnit $variantUnit): array => [
                                'unit_id' => $variantUnit->unit_id,
                                'is_base' => $variantUnit->is_base,
                                'is_purchase' => $variantUnit->is_purchase,
                                'is_sale' => $variantUnit->is_sale,
                                'is_display' => $variantUnit->is_display,
                                'factor_to_base' => $variantUnit->factor_to_base,
                                'rounding_increment' => $variantUnit->rounding_increment,
                                'permits_cross_family_conversion' => $variantUnit->permits_cross_family_conversion,
                                'is_active' => $variantUnit->is_active,
                            ])
                            ->all());
                    })
                    ->columnSpanFull(),
                TextInput::make('sku')->required()->maxLength(100),
                TextInput::make('barcode')->maxLength(100)->unique(ignoreRecord: true),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
                Select::make('status')->options(self::statusOptions())->default(ProductStatus::Active->value)->required(),
                // Tracking is not an independent choice: the parent product's type fixes it.
                // Shown read-only so the operator can see what the chosen product implies.
                Placeholder::make('tracking')
                    ->label(__('admin.inventory.product_type.label'))
                    ->content(static fn (Get $get): string => self::trackingSummary($get('product_id')))
                    ->hintIcon(Heroicon::QuestionMarkCircle, __('admin.inventory.product_type.help')),
            ]),
            Section::make(__('admin.inventory.product_type.types.grain'))
                ->description(__('admin.inventory.product_type.descriptions.grain'))
                ->columns(2)
                ->visible(static fn (Get $get): bool => self::productTypeOf($get('product_id')) === ProductType::Grain)
                ->schema([
                    TextInput::make('net_weight')
                        ->label(__('admin.inventory.product_type.fields.net_weight'))
                        ->numeric()
                        ->minValue(0.001)
                        ->step(0.001)
                        ->required(static fn (Get $get): bool => self::productTypeOf($get('product_id')) === ProductType::Grain)
                        ->hintIcon(Heroicon::QuestionMarkCircle, 'The weight one stock unit represents. Total weight is this multiplied by the quantity on hand.'),
                    Select::make('weight_unit_id')
                        ->label(__('admin.inventory.product_type.fields.weight_unit'))
                        ->relationship('weightUnit', 'name', fn (Builder $query): Builder => $query->where('is_active', true)->where('allows_decimal', true))
                        ->searchable()
                        ->preload()
                        ->required(static fn (Get $get): bool => self::productTypeOf($get('product_id')) === ProductType::Grain),
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
                TextEntry::make('product.product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductType $state): string => $state->label())
                    ->color(static fn (ProductType $state): string => $state->color()),
                TextEntry::make('net_weight')
                    ->label(__('admin.inventory.product_type.fields.net_weight'))
                    ->numeric(decimalPlaces: 3)
                    ->suffix(static fn (ProductVariant $record): string => $record->weightSuffix())
                    ->visible(static fn (ProductVariant $record): bool => $record->productType() === ProductType::Grain),
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
                TextColumn::make('product.product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductType $state): string => $state->label())
                    ->color(static fn (ProductType $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('net_weight')
                    ->label(__('admin.inventory.product_type.fields.net_weight'))
                    ->numeric(decimalPlaces: 3)
                    ->suffix(static fn (ProductVariant $record): string => $record->weightSuffix())
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('track_serials')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('track_expiry')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
                SelectFilter::make('product_id')->relationship('product', 'name')->searchable()->preload(),
                SelectFilter::make('product_type')
                    ->label(__('admin.inventory.product_type.label'))
                    ->options(ProductType::options())
                    ->multiple()
                    // The type lives on the product, so a variant-level filter has to reach
                    // through the relation rather than filter a column of its own.
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        ProductType::fromFilterValues($data['values'] ?? []),
                        fn (Builder $variants, array $types): Builder => $variants->whereHas(
                            'product',
                            fn (Builder $products): Builder => $products->whereIn('product_type', $types),
                        ),
                    )),
                Filter::make('grain_missing_weight')
                    ->label(__('admin.inventory.product_type.filters.missing_weight'))
                    // Surfaces the variants the product-type backfill could not complete, so an
                    // administrator can find and finish them.
                    ->query(fn (Builder $query): Builder => $query
                        ->whereHas('product', fn (Builder $products): Builder => $products->where('product_type', ProductType::Grain->value))
                        ->where(fn (Builder $incomplete): Builder => $incomplete
                            ->whereNull('net_weight')
                            ->orWhereNull('weight_unit_id'))),
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
                ProductVariantUomService $productVariantUomService,
            ): Model {
                $actor = self::actor();
                $inventoryIdentityGuard->ensureSkuAvailable(self::sku($data));
                $images = Arr::wrap(Arr::pull($data, 'images', []));
                $variantUoms = Arr::wrap(Arr::pull($data, 'variant_uoms', []));

                return DB::transaction(function () use ($data, $images, $variantUoms, $productPricingService, $productVariantUomService, $actor): ProductVariant {
                    $variant = ProductVariant::query()->create(self::catalogData($data));
                    $variant = $productVariantUomService->sync($variant, $variantUoms);
                    self::assertTypeRulesHold($variant);

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
                ProductVariantUomService $productVariantUomService,
            ): Model {
                $actor = self::actor();
                $inventoryIdentityGuard->ensureSkuAvailable(self::sku($data), self::recordId($record));
                $images = Arr::wrap(Arr::pull($data, 'images', []));
                $variantUoms = Arr::pull($data, 'variant_uoms');

                return DB::transaction(function () use ($record, $data, $images, $variantUoms, $productPricingService, $productVariantUomService, $actor): ProductVariant {
                    $record->update(self::catalogData($data));
                    if (is_array($variantUoms) && self::containsUomConfiguration($variantUoms)) {
                        $record = $productVariantUomService->sync($record, $variantUoms);
                    }

                    self::assertTypeRulesHold($record);

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
     * The tracking the chosen product's type imposes, rendered read-only. Kept as prose rather
     * than two disabled toggles, because the operator's question is "what does this product
     * type mean for me", not "which two booleans are set".
     */
    private static function trackingSummary(mixed $productId): string
    {
        $type = self::productTypeOf($productId);

        if (! $type instanceof ProductType) {
            return __('admin.inventory.operation.placeholders.product');
        }

        return $type->label().' — '.$type->description();
    }

    /**
     * Runs inside the write transaction, so a variant that contradicts its product's type is
     * rolled back rather than half-saved. The form already marks the fields required, but a
     * required field is a client-side promise — this is the one that holds.
     *
     * @throws \DomainException
     */
    private static function assertTypeRulesHold(ProductVariant $variant): void
    {
        $guard = app(ProductTypeGuard::class);
        $variant->loadMissing(['product', 'unit']);

        $guard->assertUnitSuitsType($variant);
        $guard->assertWeightIsComplete($variant);
    }

    private static function productTypeOf(mixed $productId): ?ProductType
    {
        if (! is_numeric($productId)) {
            return null;
        }

        $type = Product::query()->withTrashed()->whereKey((int) $productId)->value('product_type');

        return $type instanceof ProductType ? $type : null;
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

    /** @param array<mixed> $variantUoms */
    private static function containsUomConfiguration(array $variantUoms): bool
    {
        return collect($variantUoms)->contains(
            static fn (mixed $definition): bool => is_array($definition) && isset($definition['unit_id']) && is_numeric($definition['unit_id']),
        );
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
        // `product` is loaded for its type — every type-aware column and guard reads it, so
        // eager-loading here is what keeps those surfaces free of an N+1.
        return parent::getEloquentQuery()->with(['media', 'product.media', 'weightUnit']);
    }
}
