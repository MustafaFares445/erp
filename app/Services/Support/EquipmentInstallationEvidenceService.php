<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\EquipmentInstallation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Attaches private installation evidence (photos, commissioning documents,
 * customer-acceptance proof) from Filament's scratch-disk upload paths into
 * the installation's Media Library collections, mirroring
 * {@see TicketAttachmentSynchronizer}. No file path is stored on a column.
 */
final class EquipmentInstallationEvidenceService
{
    public const string Disk = 'local';

    public const string UploadDirectory = 'installation-evidence/';

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
        EquipmentInstallation::MEDIA_PHOTOS,
        EquipmentInstallation::MEDIA_COMMISSIONING,
        EquipmentInstallation::MEDIA_ACCEPTANCE,
    ];

    /**
     * @param  array<array-key, mixed>  $paths  untrusted Filament scratch paths
     * @return int number of files attached
     */
    public function attach(EquipmentInstallation $installation, string $collection, array $paths, User $actor): int
    {
        Gate::forUser($actor)->authorize('update', $installation);

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
            $installation
                ->addMediaFromDisk($path, self::Disk)
                ->toMediaCollection($collection, self::Disk);

            $disk->delete($path);
        }

        if ($paths !== []) {
            activity()
                ->performedOn($installation)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'collection' => $collection, 'files' => count($paths)])
                ->log('support.installation.evidence_attached');
        }

        return count($paths);
    }

    /** @return array<string, int> evidence count per collection */
    public function counts(EquipmentInstallation $installation): array
    {
        $installation->loadMissing('media');

        return collect(self::Collections)
            ->mapWithKeys(static fn (string $collection): array => [
                $collection => $installation->media->where('collection_name', $collection)->count(),
            ])
            ->all();
    }

    public function remove(EquipmentInstallation $installation, Media $media, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $installation);

        if ($media->model_type !== $installation->getMorphClass() || $media->model_id !== $installation->getKey()) {
            throw ValidationException::withMessages(['media' => 'This file does not belong to the installation.']);
        }

        $media->delete();

        activity()
            ->performedOn($installation)
            ->causedBy($actor)
            ->withProperties(['source_channel' => 'dashboard', 'collection' => $media->collection_name])
            ->log('support.installation.evidence_removed');
    }
}
