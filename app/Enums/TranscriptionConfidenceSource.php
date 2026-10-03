<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\VoiceNoteTranscription;
use Filament\Support\Contracts\HasLabel;

/**
 * Provenance of a {@see VoiceNoteTranscription}'s `confidence`
 * value (D6). Whisper does not return a calibrated confidence score, so this
 * travels with the value rather than letting the UI assume it is always
 * provider-reported. `confidence` is non-null exactly when this is
 * `ProviderReported` or `DerivedFromLogProb`, and null when `Unavailable`.
 */
enum TranscriptionConfidenceSource: string implements HasLabel
{
    use HasTranslatedLabel;

    case ProviderReported = 'ProviderReported';
    case DerivedFromLogProb = 'DerivedFromLogProb';
    case Unavailable = 'Unavailable';
}
