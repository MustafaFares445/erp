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
                TextEntry::make('status')->badge(),
                TextEntry::make('review_status')->label('AI review')->badge(),
                TextEntry::make('owner.name')->label('Owner')->placeholder('—'),
                TextEntry::make('customerProfile.company_name')->label('Customer')->placeholder('—'),
                TextEntry::make('lead.lead_number')->label('Lead')->placeholder('—'),
                TextEntry::make('estimated_value')->money()->placeholder('—'),
                TextEntry::make('expected_close_date')->date()->placeholder('—'),
                TextEntry::make('closed_at')->dateTime()->placeholder('—'),
                TextEntry::make('close_reason')->placeholder('—')->columnSpanFull(),
                TextEntry::make('summary')->columnSpanFull(),
            ]),
            Section::make('Origin')->columns(2)->schema([
                TextEntry::make('keywordRule.keyword')->label('Matched keyword')->placeholder('—'),
                TextEntry::make('origin')->state(static fn (SalesOpportunity $record): string => $record->isAiOriginated() ? 'AI-originated' : 'Manual')->badge(),
                TextEntry::make('origin_evidence')
                    ->label('AI origin evidence')
                    ->state(static function (SalesOpportunity $record): string {
                        $liveTranscript = $record->transcription?->transcript;
                        if (is_string($liveTranscript) && mb_trim($liveTranscript) !== '') {
                            return $liveTranscript;
                        }
                        if (is_string($record->origin_summary) && mb_trim($record->origin_summary) !== '') {
                            return $record->origin_summary;
                        }

                        return $record->isAiOriginated() ? 'Origin evidence unavailable.' : 'Manual opportunity.';
                    })
                    ->columnSpanFull(),
            ]),
            Section::make('Review decision')->columns(3)->schema([
                TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('Not reviewed'),
                TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                TextEntry::make('review_notes')->label('Notes')->placeholder('—'),
            ]),
        ]);
    }
}
