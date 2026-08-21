<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Activitylog\Models\Activity;

/**
 * Backed by `spatie/laravel-activitylog` (see ADR 0005). Written **only**
 * through the `activity()` helper — there is no Filament create/edit/delete
 * surface and no mass-assignment path from user input.
 */
final class AuditLog extends Activity
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * @return Attribute<string|null, never>
     */
    protected function sourceChannel(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $value = $this->getProperty('source_channel');

                return is_string($value) ? $value : null;
            },
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function ipAddress(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $value = $this->getProperty('ip_address');

                return is_string($value) ? $value : null;
            },
        );
    }
}
