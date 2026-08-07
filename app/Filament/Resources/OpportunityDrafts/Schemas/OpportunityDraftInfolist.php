<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityDrafts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class OpportunityDraftInfolist
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
                    TextEntry::make('transcription.transcript')->label('Source transcript')->columnSpanFull()->placeholder('—'),
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
