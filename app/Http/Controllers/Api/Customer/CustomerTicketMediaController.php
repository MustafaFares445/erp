<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CustomerTicketMediaController
{
    public function download(Request $request, Ticket $ticket, Media $media): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->customerProfile instanceof CustomerProfile, 403);
        abort_unless($ticket->customer_id === $user->customerProfile->getKey(), 404);
        abort_unless(
            $media->model_type === $ticket->getMorphClass()
            && $media->model_id === $ticket->getKey()
            && $media->collection_name === 'ticket-attachments'
            && $media->getCustomProperty('visibility') === 'customer',
            404,
        );

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->file_name,
        );
    }
}
