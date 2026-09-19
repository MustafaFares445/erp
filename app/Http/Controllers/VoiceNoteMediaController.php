<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsModelMedia;
use App\Models\EmployeeVoiceNote;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the private `voice-note-audio` media collection through a
 * temporary signed URL (FR-083, D1, H1) — never a public disk path.
 */
final class VoiceNoteMediaController
{
    use StreamsModelMedia;

    public function play(EmployeeVoiceNote $voiceNote, Media $media): StreamedResponse
    {
        $this->authorizeMedia($voiceNote, $media, ['voice-note-audio'], 'play');

        return $this->stream($media, 'inline');
    }
}
