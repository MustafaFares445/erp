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
            Section::make(__('Lead'))
                ->columns(3)
                ->schema([
                    TextEntry::make('lead_number')->label(__('Lead number')),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('source')->badge(),
                    TextEntry::make('display_name')
                        ->label(__('Contact'))
                        ->state(static fn (Lead $record): string => $record->displayName()),
                    TextEntry::make('company_name')->placeholder(__('—')),
                    TextEntry::make('job_title')->placeholder(__('—')),
                    TextEntry::make('email')->placeholder(__('—')),
                    TextEntry::make('phone')->placeholder(__('—')),
                    TextEntry::make('preferred_language')->label(__('Language')),
                    TextEntry::make('assignee.name')->label(__('Assigned to'))->placeholder(__('Unassigned')),
                    TextEntry::make('last_interaction_at')->dateTime()->placeholder(__('Never')),
                    TextEntry::make('created_at')->dateTime(),
                ]),
            Section::make(__('Outcome'))
                ->columns(2)
                ->schema([
                    TextEntry::make('convertedCustomer.company_name')->label(__('Converted customer'))->placeholder(__('—')),
                    TextEntry::make('converted_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('disqualified_reason')->badge()->placeholder(__('—')),
                    TextEntry::make('disqualified_note')->placeholder(__('—'))->columnSpanFull(),
                ]),
        ]);
    }
}
