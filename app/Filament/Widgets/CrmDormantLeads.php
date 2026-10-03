<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CrmPermission;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\Lead;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Open leads with no interaction in the last 14 days, longest-silent first.
 * A current-state work queue, so it ignores the date range but respects the
 * lead source filter.
 */
final class CrmDormantLeads extends TableWidget
{
    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(CrmPermission::LeadView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.crm.tables.dormant_leads'))
            ->description(__('dashboards.crm.tables.dormant_leads_description'))
            ->query(fn (): Builder => Lead::query()
                ->dormant()
                ->with('assignee:id,name')
                ->when($this->dashboardStringFilter('leadSource'), static fn (Builder $query, string $source): Builder => $query->where('source', $source))
                ->orderByRaw('last_interaction_at IS NOT NULL')
                ->orderBy('last_interaction_at'))
            ->recordUrl(fn (Lead $record): string => LeadResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('lead_number')
                    ->label(__('dashboards.crm.columns.lead'))
                    ->formatStateUsing(fn (Lead $record): string => $record->displayName())
                    ->description(fn (Lead $record): string => $record->lead_number)
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label(__('dashboards.crm.columns.status'))
                    ->badge(),
                TextColumn::make('assignee.name')
                    ->label(__('dashboards.crm.columns.owner'))
                    ->placeholder('—'),
                TextColumn::make('last_interaction_at')
                    ->label(__('dashboards.crm.columns.last_interaction'))
                    ->since()
                    ->placeholder(__('dashboards.crm.columns.never')),
            ]);
    }
}
