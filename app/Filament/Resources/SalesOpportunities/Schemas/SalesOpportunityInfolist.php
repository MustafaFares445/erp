<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesOpportunities\Schemas;

use App\Enums\SalesOpportunityStatus;
use App\Models\SalesOpportunity;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SalesOpportunityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('AI opportunity review'))
                ->description(__('AI output is reviewable evidence only. A manager must accept or reject the suggestion before downstream sales actions are authoritative.'))
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label(__('Review status'))
                        ->badge()
                        ->formatStateUsing(static fn (SalesOpportunityStatus $state): string => $state->label()),
                    TextEntry::make('sourceVisit.reference')->label(__('Source visit'))->placeholder(__('—')),
                    TextEntry::make('sourceVisit.employee.user.name')->label(__('Employee'))->placeholder(__('—')),
                    TextEntry::make('sourceVisit.customer.company_name')->label(__('Customer'))->placeholder(__('—')),
                    TextEntry::make('detectedProduct.name')->label(__('Detected product'))->placeholder(__('—')),
                    TextEntry::make('detectedProductVariant.sku')->label(__('Detected variant'))->placeholder(__('—')),
                    TextEntry::make('source_voice_note_id')->label(__('Voice note'))->formatStateUsing(static fn (?int $state): string => $state !== null ? '#'.$state : __('—')),
                    TextEntry::make('keywordRule.keyword')->label(__('Matched keyword'))->placeholder(__('—')),
                    TextEntry::make('created_at')->label(__('Detected at'))->dateTime(),
                    TextEntry::make('transcript_excerpt')->label(__('Transcript excerpt'))->placeholder(__('No excerpt retained'))->columnSpanFull(),
                    TextEntry::make('summary')->label(__('AI summary'))->columnSpanFull(),
                ]),
            Section::make(__('Review decision'))
                ->columns(3)
                ->schema([
                    TextEntry::make('reviewer.name')->label(__('Reviewed by'))->placeholder(__('Not reviewed')),
                    TextEntry::make('reviewed_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('rejection_reason')->label(__('Rejection reason'))->placeholder(__('—')),
                    TextEntry::make('review_notes')->label(__('Review notes'))->placeholder(__('—'))->columnSpanFull(),
                ]),
            Section::make(__('Sales pipeline'))
                ->columns(3)
                ->schema([
                    TextEntry::make('title')->placeholder(__('—')),
                    TextEntry::make('stage')->badge(),
                    TextEntry::make('owner.name')->label(__('Owner'))->placeholder(__('—')),
                    TextEntry::make('customer.company_name')->label(__('Customer'))->placeholder(__('—')),
                    TextEntry::make('lead.lead_number')->label(__('Lead'))->placeholder(__('—')),
                    TextEntry::make('estimated_value_minor')->label(__('Estimated value (minor)'))->numeric()->placeholder(__('—')),
                    TextEntry::make('currency'),
                    TextEntry::make('probability_percent')->suffix('%')->placeholder(__('—')),
                    TextEntry::make('expected_close_date')->date()->placeholder(__('—')),
                    TextEntry::make('closed_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('close_reason')->badge()->placeholder(__('—')),
                    TextEntry::make('close_note')->placeholder(__('—'))->columnSpanFull(),
                ]),
            Section::make(__('Origin evidence'))
                ->columns(2)
                ->schema([
                    TextEntry::make('origin')->badge(),
                    TextEntry::make('historical_party_gap')
                        ->label(__('Commercial party evidence'))
                        ->state(static fn (SalesOpportunity $record): string => $record->isHistoricalWithoutCommercialParty()
                            ? __('Historical row: no customer/lead was inferable')
                            : __('Linked'))
                        ->badge(),
                    TextEntry::make('origin_evidence')
                        ->label(__('Preserved origin evidence'))
                        ->state(static function (SalesOpportunity $record): string {
                            $liveTranscript = $record->transcription?->transcript;
                            if (is_string($liveTranscript) && mb_trim($liveTranscript) !== '') {
                                return $liveTranscript;
                            }
                            if (is_string($record->origin_summary) && mb_trim($record->origin_summary) !== '') {
                                return $record->origin_summary."\n\n".__('Source transcript is no longer retained; this is the preserved origin snapshot.');
                            }

                            return $record->isAiOriginated() ? __('Origin evidence unavailable.') : __('Human-created opportunity.');
                        })
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
