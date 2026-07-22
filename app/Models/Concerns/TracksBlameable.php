<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

/**
 * Populates `created_by` / `updated_by` from the authenticated user.
 *
 * Applied to master-data models (e.g. {@see Warehouse}) so
 * authorship is recorded without a Filament-specific hook, reusable by any
 * access channel that creates/updates these records.
 */
trait TracksBlameable
{
    protected static function bootTracksBlameable(): void
    {
        static::creating(function (Model $model): void {
            $model->setAttribute('created_by', $model->getAttribute('created_by') ?? auth()->id());
            $model->setAttribute('updated_by', $model->getAttribute('updated_by') ?? auth()->id());
        });

        static::updating(function (Model $model): void {
            $model->setAttribute('updated_by', auth()->id());
        });
    }
}
