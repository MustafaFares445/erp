<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EquipmentLoanStatus;
use App\Enums\SupportPermission;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\EquipmentLoan;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Operational queue (not a headline KPI): issued loaners past their expected return date. */
final class SupportOverdueLoaners extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return (bool) config('support.loaner_equipment_enabled', false)
            && (auth()->user()?->can(SupportPermission::LoanView->value) ?? false);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.support.tables.overdue_loaners'))
            ->query(fn (): Builder => EquipmentLoan::query()
                ->where('status', EquipmentLoanStatus::Issued->value)
                ->where('expected_return_at', '<', now())
                ->with(['customer', 'loanerUnit', 'originalUnit']))
            ->defaultSort('expected_return_at')
            ->recordUrl(static fn (EquipmentLoan $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record->maintenance_record_id]))
            ->columns([
                TextColumn::make('loanerUnit.serial_number')->label(__('dashboards.support.columns.loaner'))->weight('medium'),
                TextColumn::make('customer.company_name')->label(__('dashboards.support.columns.customer')),
                TextColumn::make('originalUnit.serial_number')->label(__('dashboards.support.columns.serial')),
                TextColumn::make('expected_return_at')->label(__('dashboards.support.columns.due'))->dateTime()->color('danger'),
            ]);
    }
}
