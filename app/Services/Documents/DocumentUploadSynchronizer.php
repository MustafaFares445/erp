<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Moves a Filament `FileUpload`'s scratch-disk path into a model's Media
 * Library collection. Generalised from the delivery-only synchronizer this
 * replaced so a Purchase Order's customs documents can share the same rules.
 */
final class DocumentUploadSynchronizer
{
    private const string Disk = 'local';

    private const int MaximumFileSizeInBytes = 5 * 1024 * 1024;

    /** @var array<string> */
    private const array DocumentMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function sync(StoresDocumentUploads $owner, string $collection, ?string $path, string $allowedPrefix): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if ($owner->getFirstMedia($collection)?->getPathRelativeToRoot() === $path) {
            return;
        }

        $this->ensurePathIsValid($collection, $path, $allowedPrefix);

        $owner->addMediaFromDisk($path, self::Disk)->toMediaCollection($collection, self::Disk);

        Storage::disk(self::Disk)->delete($path);
    }

    private function ensurePathIsValid(string $collection, string $path, string $allowedPrefix): void
    {
        $disk = Storage::disk(self::Disk);

        if (! Str::startsWith($path, $allowedPrefix) || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                $collection => 'The uploaded document could not be found.',
            ]);
        }

        if ($disk->size($path) > self::MaximumFileSizeInBytes) {
            throw ValidationException::withMessages([
                $collection => 'The document may not be greater than 5 MB.',
            ]);
        }

        if (! in_array($disk->mimeType($path), self::DocumentMimeTypes, true)) {
            throw ValidationException::withMessages([
                $collection => 'The document type is not supported.',
            ]);
        }
    }
}
