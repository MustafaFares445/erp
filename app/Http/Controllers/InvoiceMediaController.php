<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\Invoice;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoiceMediaController
{
    use StreamsModelMedia;

    public function preview(Invoice $invoice, Media $media): StreamedResponse
    {
        $this->authorizeMedia($invoice, $media, ['invoice-pdf']);

        return $this->stream($media, 'inline');
    }

    public function download(Invoice $invoice, Media $media): StreamedResponse
    {
        $this->authorizeMedia($invoice, $media, ['invoice-pdf']);

        return $this->stream($media, 'attachment');
    }
}
