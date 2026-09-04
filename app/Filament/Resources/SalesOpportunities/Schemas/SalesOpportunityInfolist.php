<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Schemas;

use App\Models\SalesOpportunity;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SalesOpportunityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pipeline')->columns(3)->schema([
                TextEntry::make('title')->placeholder('—'), TextEntry::make('stage')->badge(), TextEntry::make('status')->label('AI review')->badge(),
                TextEntry::make('owner.name')->label('Owner')->placeholder('—'), TextEntry::make('customer.company_name')->label('Customer')->placeholder('—'), TextEntry::make('lead.lead_number')->label('Lead')->placeholder('—'),
                TextEntry::make('estimated_value_minor')->label('Estimated value (minor)')->numeric()->placeholder('—'), TextEntry::make('currency'), TextEntry::make('probability_percent')->suffix('%')->placeholder('—'),
                TextEntry::make('expected_close_date')->date()->placeholder('—'), TextEntry::make('closed_at')->dateTime()->placeholder('—'), TextEntry::make('close_reason')->badge()->placeholder('—'),
                TextEntry::make('close_note')->placeholder('—')->columnSpanFull(), TextEntry::make('summary')->columnSpanFull(),
            ]),
            Section::make('Origin')->columns(2)->schema([
                TextEntry::make('origin')->badge(), TextEntry::make('historical_party_gap')->label('Commercial party evidence')->state(static fn (SalesOpportunity $record): string => $record->isHistoricalWithoutCommercialParty() ? 'Historical row: no customer/lead was inferable' : 'Linked')->badge(),
                TextEntry::make('origin_evidence')->label('AI origin evidence')->state(static function (SalesOpportunity $record): string {
                    $liveTranscript = $record->transcription?->transcript; if (is_string($liveTranscript) && mb_trim($liveTranscript) !== '') { return $liveTranscript; }
                    if (is_string($record->origin_summary) && mb_trim($record->origin_summary) !== '') { return $record->origin_summary; }
                    return $record->isAiOriginated() ? 'Origin evidence unavailable.' : 'Human-created opportunity.';
                })->columnSpanFull(),
            ]),
            Section::make('AI review decision')->columns(3)->schema([
                TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('Not reviewed'), TextEntry::make('reviewed_at')->dateTime()->placeholder('—'), TextEntry::make('review_notes')->label('Notes')->placeholder('—'),
            ]),
        ]);
    }
}
