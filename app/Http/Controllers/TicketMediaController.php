<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\Ticket;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private `ticket-attachments` media collection (FR-035),
 * mirroring {@see VisitMediaController}.
 */
final class TicketMediaController
{
    use StreamsModelMedia;

    public function preview(Ticket $ticket, Media $media): StreamedResponse
    {
        $this->authorizeMedia($ticket, $media, ['ticket-attachments']);

        return $this->stream($media, 'inline');
    }

    public function download(Ticket $ticket, Media $media): StreamedResponse
    {
        $this->authorizeMedia($ticket, $media, ['ticket-attachments']);

        return $this->stream($media, 'attachment');
    }
}
