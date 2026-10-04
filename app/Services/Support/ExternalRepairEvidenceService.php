<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\MaintenanceExternalRepair;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Attaches private supplier repair evidence (RMA documents,
 * supplier reports and shipping documents) from Filament's scratch-disk upload paths into
 * the supplier repair's Media Library collections, mirroring
 * {@see TicketAttachmentSynchronizer}. No file path is stored on a column.
 */
final class ExternalRepairEvidenceService
{
    public const string Disk = 'local';

    public const string UploadDirectory = 'rma-evidence/';

    public const int MaximumFileSizeInBytes = 10 * 1024 * 1024;

    /** @var list<string> */
    public const array AcceptedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /** @var list<string> */
    public const array Collections = [
        MaintenanceExternalRepair::MEDIA_RMA,
        MaintenanceExternalRepair::MEDIA_SUPPLIER_REPORTS,
        MaintenanceExternalRepair::MEDIA_SHIPPING,
    ];

    /**
     * @param  array<array-key, mixed>  $paths  untrusted Filament scratch paths
     * @return int number of files attached
     */
    public function attach(MaintenanceExternalRepair $repair, string $collection, array $paths, User $actor): int
    {
        Gate::forUser($actor)->authorize('update', $repair);

        if (! in_array($collection, self::Collections, true)) {
            throw ValidationException::withMessages(['collection' => 'Unknown evidence collection.']);
        }

        $paths = array_values(array_unique(array_filter(
            Arr::wrap($paths),
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        )));

        $disk = Storage::disk(self::Disk);

        foreach ($paths as $path) {
            if (! Str::startsWith($path, self::UploadDirectory) || Str::contains($path, '..') || ! $disk->exists($path)) {
                throw ValidationException::withMessages(['files' => 'The uploaded file could not be found.']);
            }

            if ($disk->size($path) > self::MaximumFileSizeInBytes) {
                throw ValidationException::withMessages(['files' => 'The evidence file may not be greater than 10 MB.']);
            }

            if (! in_array($disk->mimeType($path), self::AcceptedMimeTypes, true)) {
                throw ValidationException::withMessages(['files' => 'The evidence must be a JPEG, PNG, WebP, or PDF file.']);
            }
        }

        foreach ($paths as $path) {
            $repair
                ->addMediaFromDisk($path, self::Disk)
                ->toMediaCollection($collection, self::Disk);

            $disk->delete($path);
        }

        if ($paths !== []) {
            activity()
                ->performedOn($repair)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'collection' => $collection, 'files' => count($paths)])
                ->log('support.rma.evidence_attached');
        }

        return count($paths);
    }

    /** @return array<string, int> evidence count per collection */
    public function counts(MaintenanceExternalRepair $repair): array
    {
        $repair->loadMissing('media');

        return collect(self::Collections)
            ->mapWithKeys(static fn (string $collection): array => [
                $collection => $repair->media->where('collection_name', $collection)->count(),
            ])
            ->all();
    }

    public function remove(MaintenanceExternalRepair $repair, Media $media, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $repair);

        if ($media->model_type !== $repair->getMorphClass() || $media->model_id !== $repair->getKey()) {
            throw ValidationException::withMessages(['media' => 'This file does not belong to the supplier repair.']);
        }

        $media->delete();

        activity()
            ->performedOn($repair)
            ->causedBy($actor)
            ->withProperties(['source_channel' => 'dashboard', 'collection' => $media->collection_name])
            ->log('support.rma.evidence_removed');
    }
}
