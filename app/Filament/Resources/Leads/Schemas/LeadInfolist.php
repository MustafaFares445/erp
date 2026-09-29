<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Schemas;

use App\Models\Lead;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead')
                ->columns(3)
                ->schema([
                    TextEntry::make('lead_number')->label('Lead number'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('source')->badge(),
                    TextEntry::make('display_name')
                        ->label('Contact')
                        ->state(static fn (Lead $record): string => $record->displayName()),
                    TextEntry::make('company_name')->placeholder('—'),
                    TextEntry::make('job_title')->placeholder('—'),
                    TextEntry::make('email')->placeholder('—'),
                    TextEntry::make('phone')->placeholder('—'),
                    TextEntry::make('preferred_language')->label('Language'),
                    TextEntry::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                    TextEntry::make('last_interaction_at')->dateTime()->placeholder('Never'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
            Section::make('Outcome')
                ->columns(2)
                ->schema([
                    TextEntry::make('convertedCustomer.company_name')->label('Converted customer')->placeholder('—'),
                    TextEntry::make('converted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('disqualified_reason')->badge()->placeholder('—'),
                    TextEntry::make('disqualified_note')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
