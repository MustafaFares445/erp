<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\EquipmentCalibration;
use App\Services\Support\EquipmentCalibrationEvidenceService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private calibration evidence collections to users who may view
 * the calibration (never through a public path).
 */
final class EquipmentCalibrationMediaController
{
    use StreamsModelMedia;

    public function preview(EquipmentCalibration $calibration, Media $media): StreamedResponse
    {
        $this->authorizeMedia($calibration, $media, EquipmentCalibrationEvidenceService::Collections);

        return $this->stream($media, 'inline');
    }

    public function download(EquipmentCalibration $calibration, Media $media): StreamedResponse
    {
        $this->authorizeMedia($calibration, $media, EquipmentCalibrationEvidenceService::Collections);

        return $this->stream($media, 'attachment');
    }
}
