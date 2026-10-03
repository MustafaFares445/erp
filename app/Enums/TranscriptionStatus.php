<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\VoiceNoteTranscription;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle status of a {@see VoiceNoteTranscription}
 * (contracts/plan-lifecycle.md). `Succeeded` is terminal; `Failed` may be
 * retried.
 */
enum TranscriptionStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Pending = 'Pending';
    case Succeeded = 'Succeeded';
    case Failed = 'Failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Succeeded, self::Failed],
            self::Failed => [self::Pending],
            self::Succeeded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
