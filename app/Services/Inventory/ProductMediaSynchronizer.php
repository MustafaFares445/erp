<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductMediaSynchronizer
{
    private const string Collection = 'images';

    private const string Disk = 'public';

    private const string UploadDirectory = 'product-images/';

    private const int MaximumFileSizeInBytes = 5 * 1024 * 1024;

    /** @var array<string> */
    private const array AcceptedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param  array<array-key, mixed>  $paths
     */
    public function sync(Product|ProductVariant $record, array $paths): void
    {
        $paths = $this->normalizePaths($paths);
        $currentMedia = $record->getMedia(self::Collection);
        $currentPaths = $currentMedia
            ->map(static fn (Media $media): string => $media->getPathRelativeToRoot())
            ->values()
            ->all();

        if ($currentPaths === $paths) {
            return;
        }

        $mediaByPath = $currentMedia->keyBy(static fn (Media $media): string => $media->getPathRelativeToRoot());
        $newPaths = array_values(array_diff($paths, $currentPaths));

        $this->ensureNewPathsAreValid($newPaths);

        foreach ($newPaths as $path) {
            $media = $record
                ->addMediaFromDisk($path, self::Disk)
                ->toMediaCollection(self::Collection, self::Disk);

            Storage::disk(self::Disk)->delete($path);
            $mediaByPath->put($path, $media);
        }

        $record->updateMedia(
            collect($paths)
                ->map(static fn (string $path): array => [
                    'id' => $mediaByPath->get($path)?->getKey(),
                ])
                ->all(),
            self::Collection,
        );
    }

    /**
     * @param  array<array-key, mixed>  $paths
     * @return array<string>
     */
    private function normalizePaths(array $paths): array
    {
        return array_values(array_unique(array_filter(
            Arr::wrap($paths),
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        )));
    }

    /** @param array<string> $paths */
    private function ensureNewPathsAreValid(array $paths): void
    {
        $disk = Storage::disk(self::Disk);

        foreach ($paths as $path) {
            if (! Str::startsWith($path, self::UploadDirectory) || ! $disk->exists($path)) {
                throw ValidationException::withMessages([
                    'images' => 'The uploaded image could not be found.',
                ]);
            }

            if ($disk->size($path) > self::MaximumFileSizeInBytes) {
                throw ValidationException::withMessages([
                    'images' => 'The images may not be greater than 5 MB.',
                ]);
            }

            $mimeType = $disk->mimeType($path);

            if (! in_array($mimeType, self::AcceptedMimeTypes, true)) {
                throw ValidationException::withMessages([
                    'images' => 'The images must be JPEG, PNG, or WebP files.',
                ]);
            }
        }
    }
}
