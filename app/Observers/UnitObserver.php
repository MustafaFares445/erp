<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Prevents global UOM metadata from reinterpreting an already-posted variant conversion.
 */
final class UnitObserver
{
    public function saving(Unit $unit): void
    {
        if ($unit->getAttribute('precision') === null) {
            $unit->precision = $unit->allows_decimal ? 3 : 0;
        }

        if ($unit->getAttribute('family') === null) {
            $unit->family = 'unspecified';
        }

        if ($unit->precision < 0 || $unit->precision > 6 || (! $unit->allows_decimal && $unit->precision !== 0)) {
            throw ValidationException::withMessages([
                'precision' => 'A unit precision must be between zero and six, and whole units must use zero.',
            ]);
        }

        if (mb_trim($unit->family) === '') {
            throw ValidationException::withMessages([
                'family' => 'A unit family is required.',
            ]);
        }
    }

    public function updating(Unit $unit): void
    {
        if (! $unit->isDirty(['code', 'family', 'precision', 'allows_decimal'])) {
            return;
        }

        $hasStockHistory = $unit->variantUnits()
            ->whereHas('productVariant', function (Builder $variants): void {
                $variants
                    ->whereHas('movements')
                    ->orWhereHas('stocks', fn (Builder $stocks): Builder => $stocks->where('on_hand_quantity', '>', 0));
            })
            ->exists();

        if (! $hasStockHistory) {
            return;
        }

        throw ValidationException::withMessages([
            'unit' => 'Unit conversion metadata cannot change while a linked variant has stock history.',
        ]);
    }
}
