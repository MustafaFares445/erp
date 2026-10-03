<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum PurchaseRfqStatus: string implements HasLabel
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Sent = 'sent';
    case AwaitingResponses = 'awaiting_responses';
    case PartiallyResponded = 'partially_responded';
    case Evaluating = 'evaluating';
    case Awarded = 'awarded';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled, self::Expired], true);
    }

    public function acceptsResponses(): bool
    {
        return in_array($this, [self::Sent, self::AwaitingResponses, self::PartiallyResponded, self::Evaluating], true);
    }

    public function isAwardable(): bool
    {
        return in_array($this, [self::PartiallyResponded, self::Evaluating], true);
    }

    public function canCancel(): bool
    {
        return ! $this->isTerminal() && $this !== self::Awarded;
    }

    public function canClose(): bool
    {
        return $this === self::Awarded;
    }
}
