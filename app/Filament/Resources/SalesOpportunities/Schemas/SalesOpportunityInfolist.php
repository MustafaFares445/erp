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
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('summary')->columnSpanFull(),
                    TextEntry::make('keywordRule.keyword')->label('Matched keyword')->placeholder('—'),
                    TextEntry::make('status')->badge(),
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

                            return 'Origin unknown (recorded before evidence retention).';
                        })
                        ->helperText(static function (SalesOpportunity $record): ?string {
                            if ($record->transcription !== null || ! is_string($record->origin_summary) || mb_trim($record->origin_summary) === '') {
                                return null;
                            }

                            return 'Source transcript is no longer retained; this is the preserved origin snapshot.';
                        })
                        ->columnSpanFull(),
                ]),
            Section::make('Decision')
                ->columns(3)
                ->schema([
                    TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('Not yet reviewed'),
                    TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('review_notes')->label('Notes')->placeholder('—'),
                ]),
        ]);
    }
}
