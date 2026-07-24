<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The single writer of `audit_logs` (plan §2.4: exactly one audit trail; no
 * parallel Filament trail). Introduced by FI-3 as the first sensitive
 * action needs it; later sensitive actions (transfers, reservation
 * release) reuse this unchanged.
 *
 * Performs **no** transaction of its own — callers write inside their own
 * `DB::transaction()` so a rollback discards the audit entry along with the
 * action it records (research R10).
 *
 * @see /specs/003-stock-adjustments/contracts/audit-log.md
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        Model $entity,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null,
        string $sourceChannel = 'dashboard',
    ): AuditLog {
        $actor ??= auth()->user();

        return AuditLog::query()->forceCreate([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'source_channel' => $sourceChannel,
            'ip_address' => request()->ip(),
        ]);
    }
}
