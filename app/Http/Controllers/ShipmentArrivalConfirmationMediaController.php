<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\ShipmentArrivalConfirmation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShipmentArrivalConfirmationMediaController
{
    public function preview(Shipment $shipment, Media $media): StreamedResponse
    {
        return $this->respond($shipment, $media, 'inline');
    }

    public function download(Shipment $shipment, Media $media): StreamedResponse
    {
        return $this->respond($shipment, $media, 'attachment');
    }

    private function respond(Shipment $shipment, Media $media, string $disposition): StreamedResponse
    {
        Gate::authorize('view', $shipment);

        $confirmation = $shipment->arrivalConfirmation;

        abort_unless(
            $confirmation instanceof ShipmentArrivalConfirmation
                && $media->model_type === $confirmation->getMorphClass()
                && $media->model_id === $confirmation->getKey()
                && $media->collection_name === 'delivery-confirmation-photos',
            404,
        );

        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            [],
            $disposition,
        );
    }
}
