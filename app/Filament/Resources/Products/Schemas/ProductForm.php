<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('name_ar')->label('Arabic name')->maxLength(255),
            Select::make('category_id')->relationship('category', 'name')->searchable()->preload(),
            Select::make('brand_id')->relationship('brand', 'name')->searchable()->preload(),
            Select::make('status')->options(self::statusOptions())->default(ProductStatus::Active->value)->required(),
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
}
