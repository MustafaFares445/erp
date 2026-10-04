<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportAutomationEvent;
use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'event_key', 'precedence', 'is_active', 'stop_processing', 'conditions', 'actions',
])]
final class SupportAutomationRule extends Model
{
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'event_key' => SupportAutomationEvent::class,
            'precedence' => 'integer',
            'is_active' => 'boolean',
            'stop_processing' => 'boolean',
            'conditions' => 'array',
            'actions' => 'array',
        ];
    }

    /** @return HasMany<SupportAutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(SupportAutomationRun::class);
    }
}
