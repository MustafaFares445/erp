<?php

declare(strict_types=1);

namespace App\Enums;

enum InteractionOutcome: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
    case FollowUp = 'follow_up';
    case NoAnswer = 'no_answer';
}
