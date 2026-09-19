<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A {@see HasMedia} model that also uses Spatie's `InteractsWithMedia` trait.
 * `HasMedia` alone only declares the collection-registration contract, not
 * the trait's runtime media-fetching/media-adding API, so
 * {@see DocumentUploadSynchronizer} needs this narrower contract to call
 * {@see self::getFirstMedia()} and {@see self::addMediaFromDisk()} without
 * losing static type information.
 */
interface StoresDocumentUploads extends HasMedia
{
    /** @param  array<string, mixed>|callable  $filters */
    public function getFirstMedia(string $collectionName = 'default', array|callable $filters = []): ?Media;

    public function addMediaFromDisk(string $key, ?string $disk = null): FileAdder;
}
