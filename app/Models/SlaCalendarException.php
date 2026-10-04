<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sla_calendar_id', 'date', 'name', 'is_working_day', 'starts_at', 'ends_at'])]
final class SlaCalendarException extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['date' => 'date', 'is_working_day' => 'boolean'];
    }

    /** @return BelongsTo<SlaCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(SlaCalendar::class, 'sla_calendar_id');
    }
}
