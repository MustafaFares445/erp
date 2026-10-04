<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\MaintenanceStatus;
use App\Enums\SupportPermission;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\MaintenanceRecord;
use App\Services\Support\MaintenanceNextActionResolver;
use App\Services\Support\WarrantyClaimService;
use App\Support\MoneyFormatter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Maintenance jobs waiting on the next step — diagnosis, coverage, approval,
 * repair or QA — latest activity first. A current-state work queue, so it
 * ignores the date range.
 */
final class SupportMaintenanceNeedsAttention extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::MaintenanceRequestView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.support.tables.maintenance'))
            ->query(fn (): Builder => self::attentionQuery()
                ->with('customer:id,company_name')
                ->withSum('coverageLines as coverage_total_amount_minor', 'amount_minor')
                ->withSum('coverageLines as coverage_covered_amount_minor', 'covered_amount_minor')
                ->withSum('coverageLines as coverage_customer_amount_minor', 'customer_amount_minor'))
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(static fn (MaintenanceRecord $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('id')
                    ->label(__('dashboards.support.columns.job'))
                    ->formatStateUsing(static fn (int $state): string => '#'.$state)
                    ->description(static fn (MaintenanceRecord $record): ?string => $record->customer?->company_name)
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label(__('dashboards.support.columns.stage'))
                    ->badge()
                    ->formatStateUsing(static fn (MaintenanceStatus $state): string => $state->label())
                    ->color(static fn (MaintenanceStatus $state): string => $state->color()),
                TextColumn::make('customer_amount')
                    ->label(__('dashboards.support.columns.customer_pays'))
                    ->state(static fn (MaintenanceRecord $record): string => MoneyFormatter::format(
                        app(WarrantyClaimService::class)->coverageSummary($record)['customer_amount_minor'],
                    )),
                TextColumn::make('next_action')
                    ->label(__('dashboards.support.columns.next_action'))
                    ->state(static fn (MaintenanceRecord $record): string => app(MaintenanceNextActionResolver::class)->resolve($record))
                    ->color('primary')
                    ->wrap(),
            ]);
    }

    /** @return Builder<MaintenanceRecord> */
    private static function attentionQuery(): Builder
    {
        return MaintenanceRecord::query()
            ->whereIn('status', [
                MaintenanceStatus::Open->value,
                MaintenanceStatus::Diagnosing->value,
                MaintenanceStatus::AwaitingApproval->value,
                MaintenanceStatus::ReadyForRepair->value,
                MaintenanceStatus::QualityAssurance->value,
            ]);
    }
}
