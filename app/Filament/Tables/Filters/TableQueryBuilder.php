<?php

declare(strict_types=1);

namespace App\Filament\Tables\Filters;

use Filament\QueryBuilder\Constraints\Constraint;
use Filament\Tables\Filters\QueryBuilder;

/**
 * The rule-based "Add rule" filter shown at the top of every list table's
 * filter slide-over. Tables list their constraints explicitly; simple
 * domain toggles (overdue, accounting issues, trashed) stay as their own
 * filters below it.
 *
 * Constraint options are a UI affordance, not an authorization boundary:
 * the table query must already be scoped to what the user may see.
 */
final class TableQueryBuilder
{
    /** @param  list<Constraint>  $constraints */
    public static function make(array $constraints): QueryBuilder
    {
        return QueryBuilder::make()
            ->label(__('Rules'))
            ->constraints($constraints)
            ->constraintPickerColumns(1)
            ->maxNestingDepth(2)
            ->maxRules(20)
            ->columnSpanFull();
    }
}
