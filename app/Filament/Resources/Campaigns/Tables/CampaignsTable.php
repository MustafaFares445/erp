<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Tables;

use App\Enums\CampaignStatus;
use App\Filament\Resources\Campaigns\Actions\CampaignActions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('campaign_number')->searchable()->sortable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('channel')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('recipients_count')->counts('recipients')->label(__('Recipients')),
            TextColumn::make('scheduled_at')->dateTime()->placeholder(__('Not scheduled'))->sortable(),
            TextColumn::make('completed_at')->dateTime()->placeholder(__('—'))->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(collect(CampaignStatus::cases())->mapWithKeys(fn (CampaignStatus $status): array => [$status->value => $status->label()])->all()),
        ])->recordActions([
            ViewAction::make(),
            CampaignActions::buildRecipients(),
            CampaignActions::schedule(),
            CampaignActions::send(),
            CampaignActions::cancel(),
            CampaignActions::downloadSendLog(),
        ]);
    }
}
