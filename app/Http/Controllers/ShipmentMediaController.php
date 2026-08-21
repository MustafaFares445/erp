<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shipment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShipmentMediaController
{
    public function preview(Shipment $shipment, Media $media): StreamedResponse
    {
        $this->authorizeMedia($shipment, $media);

        return $this->stream($media, 'inline');
    }

    public function download(Shipment $shipment, Media $media): StreamedResponse
    {
        $this->authorizeMedia($shipment, $media);

        return $this->stream($media, 'attachment');
    }

    private function authorizeMedia(Shipment $shipment, Media $media): void
    {
        abort_unless(
            $media->model_type === $shipment->getMorphClass()
                && $media->model_id === $shipment->getKey()
                && $media->collection_name === 'attachments',
            Response::HTTP_NOT_FOUND,
        );

        Gate::authorize('view', $shipment);
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
