<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Services\Sales\PaymentTermService;
use Carbon\CarbonInterface;
use Database\Factories\PaymentTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A due-date rule an invoice or quotation may reference (data-model.md §2).
 *
 * At most one row holds `is_default = true` at a time — enforced by
 * {@see PaymentTermService}, which clears the incumbent
 * inside the same transaction rather than a partial unique index, which
 * MySQL cannot express (FR-009).
 *
 * @property int $due_days
 * @property int $grace_days
 * @property bool $is_default
 */
#[Fillable(['name', 'due_days', 'grace_days', 'discount_percent', 'is_default'])]
final class PaymentTerm extends Model
{
    /** @use HasFactory<PaymentTermFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    /**
     * FR-010: an invoice's due date defaults to its invoice date plus this
     * term's due days.
     */
    public function dueDateFrom(CarbonInterface $invoiceDate): CarbonInterface
    {
        return $invoiceDate->copy()->addDays($this->due_days);
    }

    /**
     * FR-011: an issued, unpaid invoice presents as overdue once the current
     * date passes its due date plus this term's grace days.
     */
    public function isOverdueAt(CarbonInterface $dueDate, CarbonInterface $asOf): bool
    {
        return $asOf->greaterThan($dueDate->copy()->addDays($this->grace_days));
    }
}
