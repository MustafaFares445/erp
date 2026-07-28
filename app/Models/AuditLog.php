<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\AuditLogger;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable trace of a sensitive action (ERD §6). Written **only** by
 * {@see AuditLogger} — there is no Filament create/
 * edit/delete surface and no mass-assignment path, so no `Fillable`
 * attribute is declared. Rows are permanent: no soft delete.
 */
final class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
