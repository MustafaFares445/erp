<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Ticket;
use App\Services\Inventory\ProductMediaSynchronizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Synchronizes the private `ticket-attachments` Media Library collection
 * from Filament's scratch-disk upload paths (FR-013/FR-035), mirroring
 * {@see ProductMediaSynchronizer}.
 */
final class TicketAttachmentSynchronizer
{
    private const string Collection = 'ticket-attachments';

    private const string Disk = 'local';

    private const string UploadDirectory = 'ticket-attachments/';

    private const int MaximumFileSizeInBytes = 10 * 1024 * 1024;

    /** @var array<string> */
    private const array AcceptedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /**
     * @param  array<array-key, mixed>  $paths
     */
    public function sync(Ticket $ticket, array $paths): void
    {
        $paths = $this->normalizePaths($paths);
        $currentMedia = $ticket->getMedia(self::Collection);
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
            $media = $ticket
                ->addMediaFromDisk($path, self::Disk)
                ->toMediaCollection(self::Collection, self::Disk);

            Storage::disk(self::Disk)->delete($path);
            $mediaByPath->put($path, $media);
        }

        $ticket->updateMedia(
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
                    'attachments' => 'The uploaded file could not be found.',
                ]);
            }

            if ($disk->size($path) > self::MaximumFileSizeInBytes) {
                throw ValidationException::withMessages([
                    'attachments' => 'The attachment may not be greater than 10 MB.',
                ]);
            }

            $mimeType = $disk->mimeType($path);

            if (! in_array($mimeType, self::AcceptedMimeTypes, true)) {
                throw ValidationException::withMessages([
                    'attachments' => 'The attachment must be a JPEG, PNG, WebP, or PDF file.',
                ]);
            }
        }
    }
}
