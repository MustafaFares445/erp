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
            Section::make(__('Pipeline'))->columns(3)->schema([
                TextEntry::make('title')->placeholder(__('—')), TextEntry::make('stage')->badge(), TextEntry::make('status')->label(__('AI review'))->badge(),
                TextEntry::make('owner.name')->label(__('Owner'))->placeholder(__('—')), TextEntry::make('customer.company_name')->label(__('Customer'))->placeholder(__('—')), TextEntry::make('lead.lead_number')->label(__('Lead'))->placeholder(__('—')),
                TextEntry::make('estimated_value_minor')->label(__('Estimated value (minor)'))->numeric()->placeholder(__('—')), TextEntry::make('currency'), TextEntry::make('probability_percent')->suffix('%')->placeholder(__('—')),
                TextEntry::make('expected_close_date')->date()->placeholder(__('—')), TextEntry::make('closed_at')->dateTime()->placeholder(__('—')), TextEntry::make('close_reason')->badge()->placeholder(__('—')),
                TextEntry::make('close_note')->placeholder(__('—'))->columnSpanFull(), TextEntry::make('summary')->columnSpanFull(),
            ]),
            Section::make(__('Origin'))->columns(2)->schema([
                TextEntry::make('origin')->badge(), TextEntry::make('historical_party_gap')->label(__('Commercial party evidence'))->state(static fn (SalesOpportunity $record): string => $record->isHistoricalWithoutCommercialParty() ? 'Historical row: no customer/lead was inferable' : 'Linked')->badge(),
                TextEntry::make('origin_evidence')->label(__('AI origin evidence'))->state(static function (SalesOpportunity $record): string {
                    $liveTranscript = $record->transcription?->transcript;
                    if (is_string($liveTranscript) && mb_trim($liveTranscript) !== '') {
                        return $liveTranscript;
                    }
                    if (is_string($record->origin_summary) && mb_trim($record->origin_summary) !== '') {
                        // WP-1.10: the transcription row itself may have been
                        // purged by retention; the snapshot taken at creation
                        // is what survives, and the UI says so rather than
                        // silently presenting it as a live transcript.
                        return $record->origin_summary."\n\nSource transcript is no longer retained; this is the preserved origin snapshot.";
                    }

                    return $record->isAiOriginated() ? 'Origin evidence unavailable.' : 'Human-created opportunity.';
                })->columnSpanFull(),
            ]),
            Section::make(__('AI review decision'))->columns(3)->schema([
                TextEntry::make('reviewer.name')->label(__('Reviewed by'))->placeholder(__('Not reviewed')), TextEntry::make('reviewed_at')->dateTime()->placeholder(__('—')), TextEntry::make('review_notes')->label(__('Notes'))->placeholder(__('—')),
            ]),
        ]);
    }
}
