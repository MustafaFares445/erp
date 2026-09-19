<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\InventoryOperation;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InventoryOperationMediaController
{
    use StreamsModelMedia;

    public function preview(InventoryOperation $operation, Media $media): StreamedResponse
    {
        $this->authorizeMedia($operation, $media, ['packing-list-pdf']);

        return $this->stream($media, 'inline');
    }

    public function download(InventoryOperation $operation, Media $media): StreamedResponse
    {
        $this->authorizeMedia($operation, $media, ['packing-list-pdf']);

        return $this->stream($media, 'attachment');
    }
}
