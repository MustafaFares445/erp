<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmployeeVoiceNote;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private `voice-note-audio` media collection through a
 * temporary signed URL (FR-083, D1, H1) — never a public disk path.
 */
final class VoiceNoteMediaController
{
    public function play(EmployeeVoiceNote $voiceNote, Media $media): StreamedResponse
    {
        abort_unless(
            $media->model_type === $voiceNote->getMorphClass()
                && $media->model_id === $voiceNote->getKey()
                && $media->collection_name === 'voice-note-audio',
            Response::HTTP_NOT_FOUND,
        );

        Gate::authorize('play', $voiceNote);

        return Storage::disk($media->disk)->response(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            [],
            'inline',
        );
    }
}
