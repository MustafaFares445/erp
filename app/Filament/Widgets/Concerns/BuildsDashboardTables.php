<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The shared look of every dashboard table: five rows per page, a compact
 * page-size switch, and a translated empty state.
 */
trait BuildsDashboardTables
{
    protected function dashboardTable(Table $table): Table
    {
        return $table
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading(__('dashboards.empty'))
            ->emptyStateIcon(Heroicon::OutlinedInbox);
    }

    /**
     * Paginates an already-computed, keyed list of rows for a custom-data
     * table (see Filament's `Table::records()`).
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int|string, array<string, mixed>>
     */
    protected static function paginateRows(array $rows, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $recordsPerPage, $recordsPerPage, preserve_keys: true),
            total: count($rows),
            perPage: $recordsPerPage,
            currentPage: $page,
        );
    }
}
