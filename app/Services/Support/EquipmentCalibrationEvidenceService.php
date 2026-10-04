<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\EquipmentCalibration;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Attaches private calibration evidence (certificates and
 * supporting evidence) from Filament's scratch-disk upload paths into
 * the calibration's Media Library collections, mirroring
 * {@see TicketAttachmentSynchronizer}. No file path is stored on a column.
 */
final class EquipmentCalibrationEvidenceService
{
    public const string Disk = 'local';

    public const string UploadDirectory = 'calibration-evidence/';

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
        EquipmentCalibration::MEDIA_CERTIFICATES,
        EquipmentCalibration::MEDIA_EVIDENCE,
    ];

    /**
     * @param  array<array-key, mixed>  $paths  untrusted Filament scratch paths
     * @return int number of files attached
     */
    public function attach(EquipmentCalibration $calibration, string $collection, array $paths, User $actor): int
    {
        Gate::forUser($actor)->authorize('update', $calibration);

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
            $calibration
                ->addMediaFromDisk($path, self::Disk)
                ->toMediaCollection($collection, self::Disk);

            $disk->delete($path);
        }

        if ($paths !== []) {
            activity()
                ->performedOn($calibration)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'collection' => $collection, 'files' => count($paths)])
                ->log('support.calibration.evidence_attached');
        }

        return count($paths);
    }

    /** @return array<string, int> evidence count per collection */
    public function counts(EquipmentCalibration $calibration): array
    {
        $calibration->loadMissing('media');

        return collect(self::Collections)
            ->mapWithKeys(static fn (string $collection): array => [
                $collection => $calibration->media->where('collection_name', $collection)->count(),
            ])
            ->all();
    }

    public function remove(EquipmentCalibration $calibration, Media $media, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $calibration);

        if ($media->model_type !== $calibration->getMorphClass() || $media->model_id !== $calibration->getKey()) {
            throw ValidationException::withMessages(['media' => 'This file does not belong to the calibration.']);
        }

        $media->delete();

        activity()
            ->performedOn($calibration)
            ->causedBy($actor)
            ->withProperties(['source_channel' => 'dashboard', 'collection' => $media->collection_name])
            ->log('support.calibration.evidence_removed');
    }
}
