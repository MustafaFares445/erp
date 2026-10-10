<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\SlaPolicy;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ticket priority (FR-050), driving the {@see SlaPolicy} lookup
 * at SLA clock-start (contracts/ticket-lifecycle.md §6).
 */
enum TicketPriority: string implements HasColor, HasLabel
{
    use HasTranslatedLabel;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function getColor(): string
    {
        return match ($this) {
            self::Urgent => 'danger',
            self::High => 'warning',
            self::Normal => 'info',
            self::Low => 'gray',
        };
    }
}
