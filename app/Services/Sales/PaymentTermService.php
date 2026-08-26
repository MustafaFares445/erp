<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\PaymentTerm;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the single-default invariant and the reference guard on payment
 * terms (FR-009, FR-012).
 *
 * The `is_default` clear-then-set runs inside one transaction rather than a
 * partial unique index, which MySQL cannot express (data-model.md §2).
 *
 * {@see self::delete()}'s reference guard currently checks only what exists at
 * this point in the build: nothing yet, since neither `Quotation` nor
 * `Invoice` exists (both arrive later in this feature, tasks T047 and T097
 * respectively). Each of those tasks extends this method to also refuse
 * deletion of a term any of its own documents reference — the guard is
 * additive as each referencing model lands, never removed.
 */
final readonly class PaymentTermService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PaymentTerm
    {
        return DB::transaction(function () use ($attributes): PaymentTerm {
            if ($attributes['is_default'] ?? false) {
                $this->clearExistingDefault();
            }

            return PaymentTerm::query()->create($attributes);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PaymentTerm $term, array $attributes): PaymentTerm
    {
        return DB::transaction(function () use ($term, $attributes): PaymentTerm {
            if (($attributes['is_default'] ?? false) && ! $term->is_default) {
                $this->clearExistingDefault();
            }

            $term->update($attributes);

            return $term->refresh();
        });
    }

    public function delete(PaymentTerm $term): void
    {
        // FR-012. Extended by T047 (Quotation) and T097 (Invoice) as each
        // referencing relation becomes available — see class docblock.
        $term->delete();
    }

    private function clearExistingDefault(): void
    {
        PaymentTerm::query()->where('is_default', true)->update(['is_default' => false]);
    }
}
