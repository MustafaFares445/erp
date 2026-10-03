<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountingPermission;
use App\Enums\DashboardRole;
use App\Enums\PeriodCloseCheck;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\PeriodCloseChecklistService;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The oldest open period's close checklist at a glance (WP-2.5, GAP-MW-18),
 * read from whatever the checklist last measured — never a fresh (and, for
 * the stock check, side-effecting) run triggered merely by viewing the
 * dashboard.
 */
final class PeriodCloseReadiness extends TableWidget
{
    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isAdmin() && ! $user->hasAnyRole(DashboardRole::fixedRoleNames())) {
            return true;
        }

        return $user->can(AccountingPermission::FiscalPeriodView->value);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        $period = self::openPeriod();

        return $this->dashboardTable($table)
            ->heading(__('dashboards.accounting.tables.close_readiness'))
            ->description($period instanceof FiscalPeriod
                ? __('dashboards.accounting.tables.close_readiness_period', ['period' => $period->name])
                : __('admin.accounting.close_readiness.no_open_period'))
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => self::paginateRows(
                self::rows($period),
                $page,
                $recordsPerPage,
            ))
            ->columns([
                TextColumn::make('label')
                    ->label(__('dashboards.accounting.columns.check'))
                    ->description(fn (array $record): ?string => $record['mandatory'] ? null : self::optionalLabel()),
                TextColumn::make('status')
                    ->label(__('dashboards.accounting.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('dashboards.accounting.close_status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        'passed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('measured_at')
                    ->label(__('dashboards.accounting.columns.measured'))
                    ->since()
                    ->placeholder('—'),
            ]);
    }

    private static function optionalLabel(): string
    {
        return __('dashboards.accounting.columns.optional');
    }

    private static function openPeriod(): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('is_closed', false)
            ->orderBy('starts_at')
            ->first();
    }

    /** @return array<string, array{label: string, mandatory: bool, status: string, measured_at: ?CarbonImmutable}> */
    private static function rows(?FiscalPeriod $period): array
    {
        if (! $period instanceof FiscalPeriod) {
            return [];
        }

        $rows = [];

        foreach (app(PeriodCloseChecklistService::class)->statusRows($period) as $row) {
            /** @var PeriodCloseCheck $check */
            $check = $row['check'];

            $rows[$check->value] = [
                'label' => $check->label(),
                'mandatory' => $row['mandatory'],
                'status' => match ($row['passed']) {
                    true => 'passed',
                    false => 'failed',
                    null => 'not_measured',
                },
                'measured_at' => $row['measured_at'],
            ];
        }

        return $rows;
    }
}
