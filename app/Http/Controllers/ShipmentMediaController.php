<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\Shipment;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShipmentMediaController
{
    use StreamsModelMedia;

    public function preview(Shipment $shipment, Media $media): StreamedResponse
    {
        $this->authorizeMedia($shipment, $media, ['attachments']);

        return $this->stream($media, 'inline');
    }

    public function download(Shipment $shipment, Media $media): StreamedResponse
    {
        $this->authorizeMedia($shipment, $media, ['attachments']);

        return $this->stream($media, 'attachment');
    }
}
