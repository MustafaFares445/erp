<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Shared fit policy for list tables (Docs/architecture/DASHBOARD_DESIGN_SYSTEM.md, "List table fit").
 *
 * A list table is `table-auto` at the full width of its card, so the browser keeps every column at its natural
 * width while that fits and only then shrinks the columns that may wrap. The `ierp-table-fit` theme rules decide
 * which ones may: header labels, cell descriptions and `wrap()` text columns break at word boundaries with a
 * readable minimum width, while identifiers, money, quantities, dates and status badges stay on one line and are
 * never truncated. Whatever still does not fit scrolls sideways under the sticky row-actions cell; below the
 * `sm` breakpoint each row is stacked into a labelled card instead.
 */
final class TableFit
{
    public const string CSS_CLASS = 'ierp-table-fit';

    /**
     * Inline row buttons show their label from this breakpoint and collapse to icon + tooltip below it, so the
     * sticky actions cell does not cover most of a narrower desktop or tablet table.
     */
    public const string ROW_BUTTON_LABELS_FROM = 'xl';

    public static function apply(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => self::CSS_CLASS], merge: true)
            ->stackedOnMobile();
    }

    /**
     * The table only turns plain record actions into links when it registers them, after this runs, so whether an
     * action collapses is decided when it renders.
     *
     * @param  array<Action|ActionGroup>  $actions
     * @return array<Action|ActionGroup>
     */
    public static function rowActions(array $actions): array
    {
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                continue;
            }

            $action->labeledFrom(static fn (Action $action): ?string => self::collapsesToIcon($action) ? self::ROW_BUTTON_LABELS_FROM : null);

            if (! $action->hasTooltip()) {
                $action->tooltip(static fn (Action $action): string|Htmlable|null => self::collapsesToIcon($action) ? $action->getLabel() : null);
            }
        }

        return $actions;
    }

    /**
     * Only buttons collapse: links are already compact, and an icon-less button would collapse to an empty one.
     */
    private static function collapsesToIcon(Action $action): bool
    {
        return $action->isButton() && filled($action->getIcon());
    }
}
