<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SupportPermission;
use App\Enums\TicketPriority;
use App\Filament\Widgets\SupportMaintenanceNeedsAttention;
use App\Filament\Widgets\SupportNeedsAttention;
use App\Filament\Widgets\SupportStatistics;
use App\Filament\Widgets\SupportTicketTrend;
use App\Filament\Widgets\SupportUpcomingMaintenance;
use App\Filament\Widgets\SupportWarrantyStatistics;
use App\Models\EmployeeProfile;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

/**
 * Support's module landing page: ticket KPIs, the ticket trend beside the
 * service economics, then the ticket and maintenance work queues side by
 * side, with upcoming preventive maintenance across the full width.
 */
final class SupportDashboard extends ModuleDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.support_dashboard');
    }

    /** @return array<Select> */
    #[\Override]
    protected function moduleFilters(): array
    {
        return [
            Select::make('assigneeId')
                ->label(__('dashboards.support.filters.assignee'))
                ->searchable()
                ->native(false)
                ->options(fn (): array => EmployeeProfile::query()
                    ->where('is_active', true)
                    ->with('user:id,name')
                    ->get()
                    ->mapWithKeys(static fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                    ->all()),
            Select::make('priority')
                ->label(__('dashboards.support.filters.priority'))
                ->native(false)
                ->options(collect(TicketPriority::cases())->mapWithKeys(static fn (TicketPriority $priority): array => [$priority->value => $priority->label()])->all()),
        ];
    }

    #[\Override]
    protected function getDashboardWidgets(): array
    {
        return [
            SupportStatistics::class,
            [SupportTicketTrend::class, SupportWarrantyStatistics::class],
            [SupportNeedsAttention::class, SupportMaintenanceNeedsAttention::class],
            SupportUpcomingMaintenance::class,
        ];
    }
}
