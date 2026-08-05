<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\CustomerProfile;
use App\Services\Inventory\ProductMediaSynchronizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Moves a Filament-uploaded temp file into the matching single-file media
 * collection on {@see CustomerProfile}, mirroring
 * {@see ProductMediaSynchronizer} for the admin CRM's
 * KYC/delivery documents (license, tax certificate, passport, personal
 * identity, accommodation), all stored on the private `local` disk.
 */
final class CustomerDocumentSynchronizer
{
    private const string Disk = 'local';

    private const string UploadDirectoryPrefix = 'customer-documents/';

    private const int MaximumFileSizeInBytes = 5 * 1024 * 1024;

    /** @var array<string> */
    private const array DocumentMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** @var array<string> */
    private const array ImageMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];

    public function sync(CustomerProfile $profile, string $collection, ?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $currentMedia = $profile->getFirstMedia($collection);

        if ($currentMedia?->getPathRelativeToRoot() === $path) {
            return;
        }

        $this->ensurePathIsValid($collection, $path);

        $profile->addMediaFromDisk($path, self::Disk)->toMediaCollection($collection, self::Disk);

        Storage::disk(self::Disk)->delete($path);
    }

    private function ensurePathIsValid(string $collection, string $path): void
    {
        $disk = Storage::disk(self::Disk);

        if (! Str::startsWith($path, self::UploadDirectoryPrefix) || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                $collection => 'The uploaded file could not be found.',
            ]);
        }

        if ($disk->size($path) > self::MaximumFileSizeInBytes) {
            throw ValidationException::withMessages([
                $collection => 'The file may not be greater than 5 MB.',
            ]);
        }

        if (! in_array($disk->mimeType($path), self::acceptedMimeTypes($collection), true)) {
            throw ValidationException::withMessages([
                $collection => 'The file type is not supported for this document.',
            ]);
        }
    }

    /** @return array<string> */
    private static function acceptedMimeTypes(string $collection): array
    {
        return match ($collection) {
            'license', 'tax_certificate' => self::DocumentMimeTypes,
            default => self::ImageMimeTypes,
        };
    }
}
