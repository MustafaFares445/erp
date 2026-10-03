<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\EmployeeVoiceNote;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle status of an {@see EmployeeVoiceNote}
 * (contracts/plan-lifecycle.md). `Transcribed` is terminal; `Failed` may be
 * retried manually, bounded by the retry policy in contracts/voice-note-ai.md.
 */
enum VoiceNoteStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Pending = 'Pending';
    case Processing = 'Processing';
    case Transcribed = 'Transcribed';
    case Failed = 'Failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing],
            self::Processing => [self::Transcribed, self::Failed],
            self::Transcribed => [],
            self::Failed => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
