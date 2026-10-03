<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CampaignSendStatus;
use App\Enums\CrmPermission;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\Campaign;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delivery and lead yield per campaign, newest first. Campaign results
 * accumulate after launch, so the list is not cut by the date range.
 */
final class CrmCampaignPerformance extends TableWidget
{
    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(CrmPermission::CampaignView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.crm.tables.campaigns'))
            ->query(fn (): Builder => Campaign::query()
                ->withCount([
                    'recipients as sent_count' => static fn (Builder $query): Builder => $query->where('send_status', CampaignSendStatus::Sent->value),
                    'recipients as failed_count' => static fn (Builder $query): Builder => $query->whereIn('send_status', [
                        CampaignSendStatus::Failed->value,
                        CampaignSendStatus::Suppressed->value,
                    ]),
                    'leads',
                ])
                ->latest())
            ->recordUrl(fn (Campaign $record): string => CampaignResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('dashboards.crm.columns.campaign'))
                    ->description(fn (Campaign $record): string => $record->channel->label())
                    ->weight('medium'),
                TextColumn::make('sent_count')
                    ->label(__('dashboards.crm.columns.sent'))
                    ->numeric(),
                TextColumn::make('failed_count')
                    ->label(__('dashboards.crm.columns.failed'))
                    ->numeric()
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('leads_count')
                    ->label(__('dashboards.crm.columns.leads'))
                    ->numeric(),
            ]);
    }
}
