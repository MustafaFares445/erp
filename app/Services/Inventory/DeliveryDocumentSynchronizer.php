<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryOperation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DeliveryDocumentSynchronizer
{
    private const string Disk = 'local';

    private const string UploadDirectoryPrefix = 'delivery-documents/';

    private const int MaximumFileSizeInBytes = 5 * 1024 * 1024;

    /** @var array<string> */
    private const array DocumentMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function sync(InventoryOperation $operation, string $collection, ?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if ($operation->getFirstMedia($collection)?->getPathRelativeToRoot() === $path) {
            return;
        }

        $this->ensurePathIsValid($collection, $path);

        $operation->addMediaFromDisk($path, self::Disk)->toMediaCollection($collection, self::Disk);

        Storage::disk(self::Disk)->delete($path);
    }

    private function ensurePathIsValid(string $collection, string $path): void
    {
        $disk = Storage::disk(self::Disk);

        if (! Str::startsWith($path, self::UploadDirectoryPrefix) || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                $collection => 'The uploaded delivery document could not be found.',
            ]);
        }

        if ($disk->size($path) > self::MaximumFileSizeInBytes) {
            throw ValidationException::withMessages([
                $collection => 'The delivery document may not be greater than 5 MB.',
            ]);
        }

        if (! in_array($disk->mimeType($path), self::DocumentMimeTypes, true)) {
            throw ValidationException::withMessages([
                $collection => 'The delivery document type is not supported.',
            ]);
        }
    }
}
