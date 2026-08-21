<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private `ticket-attachments` media collection (FR-035),
 * mirroring {@see VisitMediaController}.
 */
final class TicketMediaController
{
    public function preview(Ticket $ticket, Media $media): StreamedResponse
    {
        $this->authorizeMedia($ticket, $media);

        return $this->stream($media, 'inline');
    }

    public function download(Ticket $ticket, Media $media): StreamedResponse
    {
        $this->authorizeMedia($ticket, $media);

        return $this->stream($media, 'attachment');
    }

    private function authorizeMedia(Ticket $ticket, Media $media): void
    {
        abort_unless(
            $media->model_type === $ticket->getMorphClass()
                && $media->model_id === $ticket->getKey()
                && $media->collection_name === 'ticket-attachments',
            Response::HTTP_NOT_FOUND,
        );

        Gate::authorize('view', $ticket);
    }

    private function stream(Media $media, string $disposition): StreamedResponse
    {
        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            [],
            $disposition,
        );
    }
}
