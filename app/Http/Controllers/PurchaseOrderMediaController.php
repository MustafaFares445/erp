<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderDocument;
use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\PurchaseOrder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PurchaseOrderMediaController
{
    use StreamsModelMedia;

    public function preview(PurchaseOrder $purchaseOrder, Media $media): StreamedResponse
    {
        $this->authorizeMedia($purchaseOrder, $media, $this->allowedCollections());

        return $this->stream($media, 'inline');
    }

    public function download(PurchaseOrder $purchaseOrder, Media $media): StreamedResponse
    {
        $this->authorizeMedia($purchaseOrder, $media, $this->allowedCollections());

        return $this->stream($media, 'attachment');
    }

    /**
     * @return array<int, string>
     */
    private function allowedCollections(): array
    {
        return array_map(
            static fn (PurchaseOrderDocument $document): string => $document->value,
            PurchaseOrderDocument::cases(),
        );
    }
}
