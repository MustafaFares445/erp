<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\CreditNote;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CreditNoteMediaController
{
    use StreamsModelMedia;

    public function preview(CreditNote $creditNote, Media $media): StreamedResponse
    {
        $this->authorizeMedia($creditNote, $media, ['credit-note-pdf']);

        return $this->stream($media, 'inline');
    }

    public function download(CreditNote $creditNote, Media $media): StreamedResponse
    {
        $this->authorizeMedia($creditNote, $media, ['credit-note-pdf']);

        return $this->stream($media, 'attachment');
    }
}
