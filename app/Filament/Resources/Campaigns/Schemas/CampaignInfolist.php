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
            Section::make('Campaign')
                ->columns(3)
                ->schema([
                    TextEntry::make('campaign_number')->label('Campaign number'),
                    TextEntry::make('name'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('channel')->badge(),
                    TextEntry::make('contentTemplate.key')->label('Content template')->placeholder('—'),
                    TextEntry::make('creator.name')->label('Created by')->placeholder('—'),
                    TextEntry::make('scheduled_at')->dateTime()->placeholder('Not scheduled'),
                    TextEntry::make('started_at')->dateTime()->placeholder('—'),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('recipients_count')->label('Recipients')->state(
                        static fn (Campaign $record): int => $record->recipients()->count(),
                    ),
                    TextEntry::make('leads_count')->label('Attributed leads')->state(
                        static fn (Campaign $record): int => $record->leads()->count(),
                    ),
                    TextEntry::make('opportunities_count')->label('Attributed opportunities')->state(
                        static fn (Campaign $record): int => $record->opportunities()->count(),
                    ),
                ]),
        ]);
    }
}
