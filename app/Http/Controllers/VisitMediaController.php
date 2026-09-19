<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\CustomerVisit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private `visit-attachments` media collection (FR-043, D1),
 * mirroring {@see ShipmentMediaController}.
 */
final class VisitMediaController
{
    use StreamsModelMedia;

    public function preview(CustomerVisit $visit, Media $media): StreamedResponse
    {
        $this->authorizeMedia($visit, $media, ['visit-attachments']);

        return $this->stream($media, 'inline');
    }

    public function download(CustomerVisit $visit, Media $media): StreamedResponse
    {
        $this->authorizeMedia($visit, $media, ['visit-attachments']);

        return $this->stream($media, 'attachment');
    }
}
