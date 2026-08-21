<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CustomerVisit;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private `visit-attachments` media collection (FR-043, D1),
 * mirroring {@see ShipmentMediaController}.
 */
final class VisitMediaController
{
    public function preview(CustomerVisit $visit, Media $media): StreamedResponse
    {
        $this->authorizeMedia($visit, $media);

        return $this->stream($media, 'inline');
    }

    public function download(CustomerVisit $visit, Media $media): StreamedResponse
    {
        $this->authorizeMedia($visit, $media);

        return $this->stream($media, 'attachment');
    }

    private function authorizeMedia(CustomerVisit $visit, Media $media): void
    {
        abort_unless(
            $media->model_type === $visit->getMorphClass()
                && $media->model_id === $visit->getKey()
                && $media->collection_name === 'visit-attachments',
            Response::HTTP_NOT_FOUND,
        );

        Gate::authorize('view', $visit);
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
