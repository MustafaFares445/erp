<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\Quotation;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class QuotationMediaController
{
    use StreamsModelMedia;

    public function preview(Quotation $quotation, Media $media): StreamedResponse
    {
        $this->authorizeMedia($quotation, $media, ['quotation-pdf']);

        return $this->stream($media, 'inline');
    }

    public function download(Quotation $quotation, Media $media): StreamedResponse
    {
        $this->authorizeMedia($quotation, $media, ['quotation-pdf']);

        return $this->stream($media, 'attachment');
    }
}
