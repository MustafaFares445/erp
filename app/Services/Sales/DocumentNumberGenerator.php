<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Purchasing\PurchaseOrderNumberGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Assigns the human-readable document number for every sales document
 * (research.md R-005).
 *
 * The same mechanism `operation_number`, `order_number`, `purchase_order_number`,
 * and `entry_number` already use: read the current maximum under a row lock
 * inside the creating transaction, then increment. One parameterised
 * implementation rather than four near-identical classes, because this feature
 * has four documents needing the identical scheme in one place — the
 * duplication a fifth copy would add is exactly what
 * {@see PurchaseOrderNumberGenerator}'s docblock
 * warns against.
 *
 * Callers pass an already-scoped query (`Quotation::withTrashed()`, and so on)
 * rather than a bare model class, so this service stays generic over any model
 * and Larastan never has to reason about whether an arbitrary `class-string`
 * uses `SoftDeletes`. `withTrashed()` on the caller's side is load-bearing, not
 * defensive: quotations, invoices, payments, and credit notes are all
 * deletable while a draft, so a number must never be reissued after its draft
 * is deleted.
 *
 * A UUID would be race-free without the lock and is rejected for the reason
 * every prior generator in this codebase rejected it: a document number has to
 * be readable aloud to a customer.
 *
 * @see /specs/019-sales-lifecycle-payments-credits/research.md R-005
 */
final readonly class DocumentNumberGenerator
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  Scoped to the target model, including trashed rows
     */
    public function next(Builder $query, string $column, string $prefix, int $padding = 6): string
    {
        $maxSequence = 0;

        foreach ($query->whereNotNull($column)->lockForUpdate()->pluck($column) as $number) {
            if (! is_string($number) || ! str_starts_with($number, $prefix)) {
                continue;
            }

            $suffix = mb_substr($number, mb_strlen($prefix));

            if ($suffix === '' || ! ctype_digit($suffix)) {
                continue;
            }

            $maxSequence = max($maxSequence, (int) $suffix);
        }

        $sequence = $maxSequence + 1;

        return $prefix.mb_str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);
    }
}
