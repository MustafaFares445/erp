<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\EquipmentInstallation;
use App\Services\Support\EquipmentInstallationEvidenceService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private installation evidence collections to users who may view
 * the installation (never through a public path).
 */
final class EquipmentInstallationMediaController
{
    use StreamsModelMedia;

    public function preview(EquipmentInstallation $installation, Media $media): StreamedResponse
    {
        $this->authorizeMedia($installation, $media, EquipmentInstallationEvidenceService::Collections);

        return $this->stream($media, 'inline');
    }

    public function download(EquipmentInstallation $installation, Media $media): StreamedResponse
    {
        $this->authorizeMedia($installation, $media, EquipmentInstallationEvidenceService::Collections);

        return $this->stream($media, 'attachment');
    }
}
