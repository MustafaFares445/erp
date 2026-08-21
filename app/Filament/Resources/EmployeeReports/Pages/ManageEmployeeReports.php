<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeReports\Pages;

use App\Enums\EmployeeReportType;
use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\EmployeeReports\Schemas\EmployeeReportExportRequestSchema;
use App\Filament\Resources\EmployeeReports\Tables\EmployeeReportsTable;
use App\Models\User;
use App\Services\Employees\EmployeeReportExportService;
use App\Services\Employees\EmployeeReportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ManageEmployeeReports extends ManageRecords
{
    protected static string $resource = EmployeeReportResource::class;

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

        foreach ($this->availableReports() as $type) {
            $tabs[$type->value] = Tab::make($type->label());
        }

        return $tabs;
    }

    /** @return Builder<covariant \Illuminate\Database\Eloquent\Model> */
    #[\Override]
    protected function getTableQuery(): Builder
    {
        return app(EmployeeReportService::class)->query($this->reportType(), $this->reportFilters());
    }

    #[\Override]
    public function updatedActiveTab(): void
    {
        $this->tableFilters = null;
        $this->resetTable();
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export')
                ->form(EmployeeReportExportRequestSchema::make())
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if ($actor instanceof User) {
                        app(EmployeeReportExportService::class)->request($this->reportType(), $this->exportFormData($data), $actor);
                    }
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
        $requested = is_string($this->activeTab) ? EmployeeReportType::tryFrom($this->activeTab) : null;

        if ($requested instanceof EmployeeReportType && in_array($requested, $available, true)) {
            return $requested;
        }

        return $available[0] ?? EmployeeReportType::PlanCompletion;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function exportFormData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
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
