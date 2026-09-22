<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessConstraintKey;
use Database\Factories\ConstraintOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A named, reasoned approval to cross one constraint once.
 *
 * Immutable for the same reason {@see PriceFloorOverride} is: the row exists
 * to answer "who allowed this, and why" about a decision that has already been
 * acted on. A row that could be edited afterwards answers nothing.
 *
 * `limit_value` is snapshotted rather than re-read, so raising the ceiling next
 * quarter does not retroactively make this approval look unnecessary.
 *
 * @property BusinessConstraintKey $constraint_key
 * @property string $attempted_value
 * @property string $limit_value
 * @property Carbon $approved_at
 */
#[Fillable([
    'constraint_key',
    'subject_type',
    'subject_id',
    'attempted_value',
    'limit_value',
    'reason',
    'approved_by',
    'approved_at',
])]
final class ConstraintOverride extends Model
{
    /** @use HasFactory<ConstraintOverrideFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        $rejectMutation = static function (): never {
            throw new LogicException('Constraint overrides are immutable.');
        };

        self::updating($rejectMutation);
        self::deleting($rejectMutation);
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'constraint_key' => BusinessConstraintKey::class,
            'attempted_value' => 'decimal:4',
            'limit_value' => 'decimal:4',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this approval covers the value now being attempted.
     *
     * An approval is for an amount, so a float that has been through a
     * conversion still has to match the amount it was granted for. Uses the
     * catalogue's own tolerance, the same one the guard compares limits with.
     */
    public function covers(BusinessConstraintKey $key, float $attempted): bool
    {
        return $this->constraint_key === $key
            && abs((float) $this->attempted_value - $attempted) <= BusinessConstraintKey::ValueTolerance;
    }
}
