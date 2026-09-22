<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Observers\BusinessConstraintObserver;
use App\Services\Settings\BusinessConstraints;
use App\Services\Settings\BusinessConstraintService;
use Database\Factories\BusinessConstraintFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An owner's override of one entry in the {@see BusinessConstraintKey}
 * catalogue.
 *
 * A key with no row here is not unset — it is at its declared default. That is
 * why nothing seeds this table and why deleting a row is a legitimate "reset
 * to default" rather than data loss.
 *
 * Read through {@see BusinessConstraints} and write
 * through {@see BusinessConstraintService}. Reading the
 * model directly bypasses the memoisation; writing it directly bypasses the
 * audit trail.
 *
 * @property BusinessConstraintKey $key
 * @property string|null $value
 * @property list<int>|null $value_json
 * @property BusinessConstraintEnforcement|null $enforcement
 */
#[ObservedBy(BusinessConstraintObserver::class)]
#[Fillable(['key', 'value', 'value_json', 'enforcement', 'updated_by'])]
final class BusinessConstraint extends Model
{
    /** @use HasFactory<BusinessConstraintFactory> */
    use HasFactory;

    /**
     * The stored row must match the shape its key declares.
     *
     * Enforced here rather than only in the settings form because the form is
     * one writer among several — a seeder, a test factory, or a future API all
     * reach the same table, and a scalar limit that quietly arrived as a list
     * would surface much later as a type error deep inside a pricing service.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $record): void {
            $record->assertValueMatchesKey();
            $record->assertEnforcementMatchesKind();
        });
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'key' => BusinessConstraintKey::class,
            'value' => 'decimal:4',
            'value_json' => 'array',
            'enforcement' => BusinessConstraintEnforcement::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    private function assertValueMatchesKey(): void
    {
        $key = $this->key;

        if ($key->isList()) {
            if ($this->value !== null) {
                throw new DomainException("The {$key->value} constraint holds a list, not a single value.");
            }

            $this->assertAscendingPositiveList($key);

            return;
        }

        if ($this->value_json !== null) {
            throw new DomainException("The {$key->value} constraint holds a single value, not a list.");
        }

        if ($this->value === null && ! $key->isNullable()) {
            throw new DomainException("The {$key->value} constraint requires a value.");
        }
    }

    /**
     * A ladder has to climb.
     *
     * Ageing buckets and reminder schedules are both read as ordered
     * boundaries, so an unsorted or duplicated list would silently produce an
     * empty bucket or a reminder that can never fire.
     */
    private function assertAscendingPositiveList(BusinessConstraintKey $key): void
    {
        $list = $this->value_json;

        if ($list === null || $list === []) {
            throw new DomainException("The {$key->value} constraint requires at least one boundary.");
        }

        $previous = 0;

        foreach ($list as $boundary) {
            if ($boundary <= $previous) {
                throw new DomainException("The {$key->value} boundaries must ascend and be greater than zero.");
            }

            $previous = $boundary;
        }
    }

    private function assertEnforcementMatchesKind(): void
    {
        $key = $this->key;
        $enforcement = $this->enforcement;

        if (! $key->kind()->requiresEnforcement()) {
            if ($enforcement !== null) {
                throw new DomainException("The {$key->value} constraint has nothing to enforce.");
            }

            return;
        }

        if ($enforcement === null) {
            throw new DomainException("The {$key->value} constraint requires an enforcement mode.");
        }

        if (! in_array($enforcement, $key->allowedEnforcements(), true)) {
            throw new DomainException("{$enforcement->value} is not an allowed enforcement mode for {$key->value}.");
        }
    }
}
