<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone', 'is_24x7', 'is_default', 'is_active'])]
final class SlaCalendar extends Model
{
    use TracksBlameable;

    #[\Override]
    protected static function booted(): void
    {
        // Exactly one default calendar: promoting this one demotes the rest.
        self::saved(static function (self $calendar): void {
            if ($calendar->is_default) {
                self::query()->whereKeyNot($calendar->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'is_24x7' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<SlaCalendarPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(SlaCalendarPeriod::class)->orderBy('weekday')->orderBy('starts_at');
    }

    /** @return HasMany<SlaCalendarException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(SlaCalendarException::class)->orderBy('date');
    }
}
