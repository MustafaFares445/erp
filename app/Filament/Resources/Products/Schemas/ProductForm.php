<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\Unit;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            Select::make('category_id')->relationship('category', 'name')->searchable()->preload()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Categories group related products for browsing, reporting, and product setup.'),
            Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Select the manufacturer or commercial brand used to identify this product.'),
            Select::make('unit_ids')
                ->label('Transition-only unit allow-list')
                ->options(static fn (): array => Unit::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->multiple()
                ->searchable()
                ->preload()
                ->disabled()
                ->dehydrated(false)
                ->hintIcon(Heroicon::QuestionMarkCircle, 'This legacy allow-list is displayed for transition only. Configure stock units and conversions on each variant.')
                ->afterStateHydrated(static function (Select $component, ?Product $record): void {
                    if ($record instanceof Product) {
                        $component->state($record->units()->pluck('units.id')->all());
                    }
                }),
            Select::make('default_unit_id')
                ->label('Legacy default unit')
                ->options(static fn (Get $get): array => Unit::query()->whereIn('id', (array) $get('unit_ids'))->pluck('name', 'id')->all())
                ->visible(static fn (Get $get): bool => count((array) $get('unit_ids')) > 1)
                ->disabled()
                ->dehydrated(false)
                ->afterStateHydrated(static function (Select $component, ?Product $record): void {
                    if ($record instanceof Product) {
                        $component->state($record->units()->wherePivot('is_default', true)->value('units.id'));
                    }
                }),
            Select::make('status')->options(self::statusOptions())->default(ProductStatus::Active->value)->required()
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Only active products should be used in new inventory workflows.'),
            // A radio rather than a select: three mutually exclusive options whose consequences
            // need explaining at the point of choice, because the choice is later locked.
            Radio::make('product_type')
                ->label(__('admin.inventory.product_type.label'))
                ->options(ProductType::options())
                ->descriptions(self::productTypeDescriptions())
                ->default(ProductType::Grain->value)
                ->required()
                // Locked once the product has stock history: the type fixes how its variants
                // are tracked, so changing it would orphan existing lots or serialized units.
                // Not the integrity boundary — ProductObserver refuses the change regardless of
                // what a tampered request submits. This only keeps the UI honest.
                ->disabled(static fn (?Product $record): bool => $record?->hasStockHistory() === true)
                ->helperText(static fn (?Product $record): string => $record?->hasStockHistory() === true
                    ? __('admin.inventory.product_type.errors.immutable')
                    : __('admin.inventory.product_type.help'))
                ->columnSpanFull(),
            Textarea::make('description')->columnSpanFull(),
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
                ->hintIcon(Heroicon::QuestionMarkCircle, 'Upload clear product images. You can add multiple images and drag them to choose their display order.')
                ->preventFilePathTampering(
                    allowFilePathUsing: static function (?Product $record, string $file): bool {
                        if (! $record instanceof Product) {
                            return false;
                        }

                        return $record->getMedia('images')->contains(
                            static fn (Media $media): bool => $media->getPathRelativeToRoot() === $file,
                        );
                    },
                )
                ->afterStateHydrated(static function (FileUpload $component, ?Product $record): void {
                    if (! $record instanceof Product) {
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

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ProductStatus::cases())
            ->mapWithKeys(static fn (ProductStatus $status): array => [$status->value => $status->name])
            ->all();
    }

    /** @return array<string, string> */
    private static function productTypeDescriptions(): array
    {
        return collect(ProductType::cases())
            ->mapWithKeys(static fn (ProductType $type): array => [$type->value => $type->description()])
            ->all();
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public static function productData(array $data): array
    {
        $productData = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (! in_array($key, (new Product)->getFillable(), true)) {
                continue;
            }

            $productData[$key] = $value;
        }

        return $productData;
    }

    public static function normalizeUnitId(mixed $unitId): int|string|null
    {
        return is_int($unitId) || is_string($unitId) ? $unitId : null;
    }
}
