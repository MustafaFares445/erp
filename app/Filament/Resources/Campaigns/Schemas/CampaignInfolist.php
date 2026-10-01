<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Models\Campaign;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Campaign'))
                ->columns(3)
                ->schema([
                    TextEntry::make('campaign_number')->label(__('Campaign number')),
                    TextEntry::make('name'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('channel')->badge(),
                    TextEntry::make('contentTemplate.key')->label(__('Content template'))->placeholder(__('—')),
                    TextEntry::make('creator.name')->label(__('Created by'))->placeholder(__('—')),
                    TextEntry::make('scheduled_at')->dateTime()->placeholder(__('Not scheduled')),
                    TextEntry::make('started_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('completed_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('recipients_count')->label(__('Recipients'))->state(
                        static fn (Campaign $record): int => $record->recipients()->count(),
                    ),
                    TextEntry::make('leads_count')->label(__('Attributed leads'))->state(
                        static fn (Campaign $record): int => $record->leads()->count(),
                    ),
                    TextEntry::make('opportunities_count')->label(__('Attributed opportunities'))->state(
                        static fn (Campaign $record): int => $record->opportunities()->count(),
                    ),
                ]),
        ]);
    }
}
