<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\MaintenanceExternalRepair;
use App\Services\Support\ExternalRepairEvidenceService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private supplier repair evidence collections to users who may view
 * the supplier repair (never through a public path).
 */
final class ExternalRepairMediaController
{
    use StreamsModelMedia;

    public function preview(MaintenanceExternalRepair $repair, Media $media): StreamedResponse
    {
        $this->authorizeMedia($repair, $media, ExternalRepairEvidenceService::Collections);

        return $this->stream($media, 'inline');
    }

    public function download(MaintenanceExternalRepair $repair, Media $media): StreamedResponse
    {
        $this->authorizeMedia($repair, $media, ExternalRepairEvidenceService::Collections);

        return $this->stream($media, 'attachment');
    }
}
