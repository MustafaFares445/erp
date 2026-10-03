<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Filament\Pages\ModuleDashboard;
use App\Support\Dashboard\DashboardPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Attributes\Locked;

/**
 * Reads a {@see ModuleDashboard}'s filter bar. Dashboard widgets refresh when
 * the filters change, so they never poll on a timer.
 */
trait InteractsWithDashboardFilters
{
    use InteractsWithPageFilters;

    /** Set by the dashboard when this widget's side-by-side partner is hidden. */
    #[Locked]
    public bool $spansFullWidth = false;

    /** @return int|string|array<string, int|null> */
    #[\Override]
    public function getColumnSpan(): int|string|array
    {
        return $this->spansFullWidth ? 'full' : $this->columnSpan;
    }

    /** Dashboard widgets refresh when the filters change, never on a timer. */
    protected function getPollingInterval(): ?string
    {
        return null;
    }

    protected function dashboardPeriod(): DashboardPeriod
    {
        return DashboardPeriod::fromPageFilters($this->pageFilters ?? []);
    }

    protected function dashboardFilter(string $key): ?int
    {
        $value = ($this->pageFilters ?? [])[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    protected function dashboardStringFilter(string $key): ?string
    {
        $value = ($this->pageFilters ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
