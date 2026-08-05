<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Shipment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ShipmentAttachmentSynchronizer
{
    private const string Disk = 'local';

    private const string UploadDirectoryPrefix = 'shipment-attachments/';

    private const int MaximumFileSizeInBytes = 5 * 1024 * 1024;

    /** @var array<string> */
    private const array AllowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** @param list<string> $paths */
    public function sync(Shipment $shipment, array $paths): void
    {
        $existingMedia = $shipment->getMedia('attachments');
        $existingPaths = $existingMedia->mapWithKeys(
            static fn (Media $media): array => [$media->getPathRelativeToRoot() => true],
        )->all();

        foreach (array_values(array_unique($paths)) as $path) {
            if (isset($existingPaths[$path])) {
                continue;
            }

            $this->ensurePathIsValid($path);
            $shipment->addMediaFromDisk($path, self::Disk)->toMediaCollection('attachments', self::Disk);
            Storage::disk(self::Disk)->delete($path);
        }

        foreach ($existingMedia as $media) {
            if (! in_array($media->getPathRelativeToRoot(), $paths, true)) {
                $media->delete();
            }
        }
    }

    private function ensurePathIsValid(string $path): void
    {
        $disk = Storage::disk(self::Disk);

        if (! Str::startsWith($path, self::UploadDirectoryPrefix) || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'attachments' => 'The uploaded shipment attachment could not be found.',
            ]);
        }

        if ($disk->size($path) > self::MaximumFileSizeInBytes) {
            throw ValidationException::withMessages([
                'attachments' => 'The shipment attachment may not be greater than 5 MB.',
            ]);
        }

        if (! in_array($disk->mimeType($path), self::AllowedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'attachments' => 'The shipment attachment type is not supported.',
            ]);
        }
    }
}
