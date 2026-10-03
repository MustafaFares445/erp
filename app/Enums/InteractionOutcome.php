<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum InteractionOutcome: string implements HasLabel
{
    use HasTranslatedLabel;

    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
    case FollowUp = 'follow_up';
    case NoAnswer = 'no_answer';
}
