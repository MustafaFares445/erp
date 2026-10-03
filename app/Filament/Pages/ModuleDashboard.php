<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Support\Dashboard\DashboardPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * The shared layout for every module dashboard: a filter card (date range
 * plus the module's own filters) above a two-column widget grid. Widgets
 * read the filters through {@see InteractsWithDashboardFilters}.
 *
 * {@see self::getDashboardWidgets()} lists full-width widgets as class
 * strings and side-by-side pairs as two-element lists. When one half of a
 * pair is hidden by its `canView()`, the other half spans the full row, so
 * the grid never shows a half-empty row.
 */
abstract class ModuleDashboard extends Page
{
    use HasFiltersForm;

    /**
     * @return list<class-string<Widget>|list<class-string<Widget>>>
     */
    abstract protected function getDashboardWidgets(): array;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard');
    }

    /**
     * The module-specific filter fields shown after the date range.
     *
     * @return array<Component|Select>
     */
    protected function moduleFilters(): array
    {
        return [];
    }

    /** @return array<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetFilters')
                ->label(__('dashboards.reset_filters'))
                ->color('gray')
                ->action(function (): void {
                    $this->filters = null;
                    session()->forget($this->getFiltersSessionKey());
                    $this->getFiltersForm()->fill();
                }),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->compact()
                    ->columnSpanFull()
                    ->columns(['md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('period')
                            ->label(__('dashboards.date_range'))
                            ->options(DashboardPeriod::presetOptions())
                            ->default(DashboardPeriod::DEFAULT_PERIOD)
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live(),
                        DatePicker::make('customFrom')
                            ->label(__('dashboards.from'))
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('period') === DashboardPeriod::PERIOD_CUSTOM),
                        DatePicker::make('customUntil')
                            ->label(__('dashboards.until'))
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('period') === DashboardPeriod::PERIOD_CUSTOM),
                        ...$this->moduleFilters(),
                    ]),
            ]);
    }

    #[\Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(['default' => 1, 'lg' => 2])
                ->extraAttributes(['class' => 'ierp-dashboard-grid'])
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->resolveDashboardWidgets())),
        ]);
    }

    /**
     * Flattens the declared rows, promoting the surviving half of a pair to
     * full width when its partner cannot be viewed.
     *
     * @return list<class-string<Widget>|WidgetConfiguration>
     */
    protected function resolveDashboardWidgets(): array
    {
        $widgets = [];

        foreach ($this->getDashboardWidgets() as $row) {
            if (is_string($row)) {
                $widgets[] = $row;

                continue;
            }

            $visible = array_values(array_filter($row, static fn (string $widget): bool => $widget::canView()));

            if (count($visible) === 1) {
                $widgets[] = $visible[0]::make(['spansFullWidth' => true]);

                continue;
            }

            array_push($widgets, ...$visible);
        }

        return $widgets;
    }
}
