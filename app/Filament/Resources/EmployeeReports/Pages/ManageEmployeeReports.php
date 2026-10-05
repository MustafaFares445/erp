<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeReports\Pages;

use App\Enums\EmployeeReportType;
use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\EmployeeReports\Tables\EmployeeReportsTable;
use App\Models\User;
use App\Services\Employees\EmployeeReportExportService;
use App\Services\Employees\EmployeeReportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

final class ManageEmployeeReports extends ManageRecords
{
    protected static string $resource = EmployeeReportResource::class;

    #[Url]
    public ?string $report = null;

    #[\Override]
    public function getSubheading(): string
    {
        return __('reporting.reports.employees.'.$this->reportType()->value);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return EmployeeReportsTable::configure($table, $this->reportType());
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        $tabs = [];

        foreach ($this->categoryMap() as $category => $metadata) {
            if ($this->reportsForCategory($category) === []) {
                continue;
            }

            $tabs[$category] = Tab::make(__($metadata['label']));
        }

        return $tabs;
    }

    #[\Override]
    public function getDefaultActiveTab(): string
    {
        return $this->categoryForReport($this->reportType());
    }

    /** @return Builder<covariant \Illuminate\Database\Eloquent\Model> */
    #[\Override]
    protected function getTableQuery(): Builder
    {
        return app(EmployeeReportService::class)->query($this->reportType(), $this->reportFilters());
    }

    public function updatedReport(): void
    {
        $requested = is_string($this->report) ? EmployeeReportType::tryFrom($this->report) : null;

        if (! $requested instanceof EmployeeReportType || ! in_array($requested, $this->availableReports(), true)) {
            $this->report = $this->availableReports()[0]->value ?? null;
            $requested = $this->report !== null ? EmployeeReportType::tryFrom($this->report) : null;
        }

        if ($requested instanceof EmployeeReportType) {
            $this->activeTab = $this->categoryForReport($requested);
        }

        $this->tableFilters = null;
        $this->resetTable();
    }

    #[\Override]
    public function updatedActiveTab(): void
    {
        $category = is_string($this->activeTab) ? $this->activeTab : null;
        $reports = $category !== null ? $this->reportsForCategory($category) : [];

        if ($reports !== [] && ! in_array($this->reportType(), $reports, true)) {
            $this->report = $reports[0]->value;
        }

        $this->tableFilters = null;
        $this->resetTable();
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        $reportActions = array_map(
            function (EmployeeReportType $type): Action {
                return Action::make('select_report_'.$type->value)
                    ->label($type->label())
                    ->icon($this->reportType() === $type ? 'heroicon-m-check' : null)
                    ->action(function () use ($type): void {
                        $this->selectReport($type);
                    });
            },
            $this->reportsForCategory(
                is_string($this->activeTab)
                    ? $this->activeTab
                    : $this->categoryForReport($this->reportType()),
            ),
        );

        return [
            ActionGroup::make($reportActions)
                ->label(__('Report').': '.$this->reportType()->label())
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray'),
            Action::make('export_current_report')
                ->label(__('reporting.actions.export_current'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canViewCurrentReport())
                ->authorize(fn (): bool => $this->canViewCurrentReport())
                ->action(function (): void {
                    $actor = auth()->user();

                    abort_unless($actor instanceof User, 403);

                    app(EmployeeReportExportService::class)->request(
                        $this->reportType(),
                        $this->reportFilters(),
                        $actor,
                    );

                    Notification::make()
                        ->success()
                        ->title(__('Report export queued'))
                        ->body(__('The export uses the report and filters currently shown on this page.'))
                        ->send();
                }),
        ];
    }

    /** @return list<EmployeeReportType> */
    private function availableReports(): array
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(EmployeeReportService::class)->availableReports($actor)
            : [];
    }

    private function reportType(): EmployeeReportType
    {
        $available = $this->availableReports();
        $requested = is_string($this->report) ? EmployeeReportType::tryFrom($this->report) : null;

        if ($requested instanceof EmployeeReportType && in_array($requested, $available, true)) {
            return $requested;
        }

        return $available[0] ?? EmployeeReportType::PlanCompletion;
    }

    /**
     * @return array<string,array{label:string,reports:list<EmployeeReportType>}>
     */
    private function categoryMap(): array
    {
        return [
            'tasks_visits' => [
                'label' => 'reporting.categories.tasks_visits',
                'reports' => [
                    EmployeeReportType::PlanCompletion,
                    EmployeeReportType::OverdueTasks,
                    EmployeeReportType::UnexecutedVisits,
                ],
            ],
            'performance' => [
                'label' => 'reporting.categories.performance',
                'reports' => [
                    EmployeeReportType::PerformanceByEmployee,
                    EmployeeReportType::PerformanceByMonth,
                ],
            ],
            'salary' => [
                'label' => 'reporting.categories.salary',
                'reports' => [
                    EmployeeReportType::SalaryByEmployee,
                    EmployeeReportType::SalaryByMonth,
                ],
            ],
        ];
    }

    /** @return list<EmployeeReportType> */
    private function reportsForCategory(string $category): array
    {
        $configured = $this->categoryMap()[$category]['reports'] ?? [];
        $available = $this->availableReports();

        return array_values(array_filter(
            $configured,
            static fn (EmployeeReportType $type): bool => in_array($type, $available, true),
        ));
    }

    private function categoryForReport(EmployeeReportType $type): string
    {
        return array_find_key(
            $this->categoryMap(),
            static fn (array $metadata): bool => in_array($type, $metadata['reports'], true),
        ) ?? 'tasks_visits';
    }

    private function selectReport(EmployeeReportType $type): void
    {
        abort_unless(in_array($type, $this->availableReports(), true), 403);

        $this->report = $type->value;
        $this->activeTab = $this->categoryForReport($type);
        $this->tableFilters = null;
        $this->resetTable();
    }

    private function canViewCurrentReport(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && app(EmployeeReportService::class)->canView($actor, $this->reportType());
    }

    /** @return array<string, mixed> */
    private function reportFilters(): array
    {
        $filters = [];

        foreach ($this->tableFilters ?? [] as $name => $state) {
            if (! is_array($state)) {
                continue;
            }

            if (array_key_exists('value', $state)) {
                $filters[$name] = $state['value'];

                continue;
            }

            foreach ($state as $key => $value) {
                if (is_string($key)) {
                    $filters[$key] = $value;
                }
            }
        }

        return $filters;
    }
}
