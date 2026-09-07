<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryConditionChange;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds uploaded evidence files to a disposal document's `disposal-evidence`
 * media collection (GAP-UI-06 / IN-09). Mirrors the upload-then-sync pattern
 * already used for shipment and ticket attachments in this codebase.
 */
final class DisposalEvidenceSynchronizer
{
    private const string Disk = 'local';

    private const string UploadDirectoryPrefix = 'disposal-evidence/';

    private const int MaximumFileSizeInBytes = 10 * 1024 * 1024;

    /** @var array<string> */
    private const array AllowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** @param list<string> $paths */
    public function sync(InventoryConditionChange $change, array $paths): void
    {
        foreach (array_values(array_unique($paths)) as $path) {
            $this->ensurePathIsValid($path);
            $change->addMediaFromDisk($path, self::Disk)->toMediaCollection('disposal-evidence', self::Disk);
            Storage::disk(self::Disk)->delete($path);
        }
    }

    private function ensurePathIsValid(string $path): void
    {
        $disk = Storage::disk(self::Disk);

        if (! Str::startsWith($path, self::UploadDirectoryPrefix) || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'evidence' => 'The uploaded disposal evidence could not be found.',
            ]);
        }

        if ($disk->size($path) > self::MaximumFileSizeInBytes) {
            throw ValidationException::withMessages([
                'evidence' => 'The disposal evidence may not be greater than 10 MB.',
            ]);
        }

        if (! in_array($disk->mimeType($path), self::AllowedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'evidence' => 'The disposal evidence type is not supported.',
            ]);
        }
    }
}
