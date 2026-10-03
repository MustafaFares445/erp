<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Models\Ticket;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows Support-dashboard ticket queries to the assignee and priority
 * picked in the filter bar.
 *
 * @phpstan-require-extends Widget
 */
trait ScopesSupportTickets
{
    use InteractsWithDashboardFilters;

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    protected function scopeTickets(Builder $query): Builder
    {
        return $query
            ->when($this->dashboardFilter('assigneeId'), static fn (Builder $query, int $employeeId): Builder => $query->where('assigned_employee_id', $employeeId))
            ->when($this->dashboardStringFilter('priority'), static fn (Builder $query, string $priority): Builder => $query->where('priority', $priority));
    }
}
