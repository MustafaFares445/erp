<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sla_calendar_id', 'weekday', 'starts_at', 'ends_at'])]
final class SlaCalendarPeriod extends Model
{
    /** @return BelongsTo<SlaCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(SlaCalendar::class, 'sla_calendar_id');
    }
}
