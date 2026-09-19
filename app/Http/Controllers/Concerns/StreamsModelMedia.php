<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared authorization + streaming shape for controllers that serve a
 * single model's private media collections.
 */
trait StreamsModelMedia
{
    /**
     * @param  array<int, string>  $allowedCollections
     */
    private function authorizeMedia(Model $owner, Media $media, array $allowedCollections, string $ability = 'view'): void
    {
        abort_unless(
            $media->model_type === $owner->getMorphClass()
                && $media->model_id === $owner->getKey()
                && in_array($media->collection_name, $allowedCollections, true),
            Response::HTTP_NOT_FOUND,
        );

        Gate::authorize($ability, $owner);
    }

    private function stream(Media $media, string $disposition): StreamedResponse
    {
        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            [],
            $disposition,
        );
    }
}
