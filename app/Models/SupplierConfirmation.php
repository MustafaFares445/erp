<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierConfirmationStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Purchasing\SupplierConfirmationService;
use Database\Factories\SupplierConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One recorded exchange with a supplier about a document (data-model.md §4).
 *
 * The `confirmable` morph is restricted to {@see PurchaseOrder} and
 * {@see Order} by {@see SupplierConfirmationService} (V-09, FR-028). Two
 * tables would have duplicated the lifecycle, the policy, and the UI for one
 * differing column; a pair of nullable foreign keys would have permitted the
 * both-set and neither-set states a morph cannot express (R-007).
 *
 * `confirmation_status`, `confirmed_by`, and `confirmed_at` are not fillable
 * (data-model.md §10), and there is no soft delete: once answered, the record
 * is evidence. A supplier who changes their mind produces a new row.
 *
 * @property int $id
 * @property string $confirmable_type
 * @property int $confirmable_id
 * @property int $supplier_id
 * @property SupplierConfirmationStatus $confirmation_status
 * @property Carbon|null $promised_at
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property string|null $notes
 * @property Supplier $supplier
 */
#[Fillable([
    'confirmable_type',
    'confirmable_id',
    'supplier_id',
    'promised_at',
    'notes',
])]
final class SupplierConfirmation extends Model
{
    /** @use HasFactory<SupplierConfirmationFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'confirmation_status' => 'pending',
    ];

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'confirmation_status' => SupplierConfirmationStatus::class,
            'promised_at' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function confirmable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isAnswered(): bool
    {
        return $this->confirmation_status->isAnswered();
    }
}
