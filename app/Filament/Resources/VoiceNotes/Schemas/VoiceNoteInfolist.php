<?php

declare(strict_types=1);

namespace App\Filament\Resources\VoiceNotes\Schemas;

use App\Enums\TranscriptionConfidenceSource;
use App\Models\EmployeeVoiceNote;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class VoiceNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('employee.user.name')->label('Employee'),
                    TextEntry::make('customerVisit.customer.company_name')->label('Visit customer')->placeholder('—'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('language')->label('Language hint')->placeholder('Auto-detect'),
                    TextEntry::make('duration_seconds')->label('Duration (s)')->placeholder('—'),
                    TextEntry::make('audio')
                        ->label('Audio')
                        ->state(static fn (EmployeeVoiceNote $record): ?string => self::playUrl($record))
                        ->formatStateUsing(static fn (?string $state): string => $state !== null ? 'Play' : 'No audio attached')
                        ->url(static fn (?string $state): ?string => $state)
                        ->openUrlInNewTab(),
                ]),
            Section::make('Transcription')
                ->columns(2)
                ->schema([
                    TextEntry::make('transcription.status')->label('Status')->badge(),
                    TextEntry::make('transcription.detected_language')->label('Detected language')->placeholder('—'),
                    TextEntry::make('transcription.transcript')->columnSpanFull()->placeholder('No transcript yet'),
                    TextEntry::make('transcription.error_message')
                        ->label('Failure reason')
                        ->color('danger')
                        ->visible(static fn (EmployeeVoiceNote $record): bool => $record->transcription?->error_message !== null)
                        ->columnSpanFull(),
                    TextEntry::make('confidence')
                        ->label('Confidence')
                        ->state(static fn (EmployeeVoiceNote $record): string => $record->transcription?->confidenceLabel()
                            ?? __('admin.employees.confidence.unavailable'))
                        ->tooltip(static fn (EmployeeVoiceNote $record): ?string => $record->transcription?->confidence_source === TranscriptionConfidenceSource::DerivedFromLogProb
                            ? "Derived from the model's log-probabilities, not a provider-reported confidence."
                            : null),
                ]),
        ]);
    }

    private static function playUrl(EmployeeVoiceNote $record): ?string
    {
        $media = $record->getFirstMedia('voice-note-audio');

        if (! $media instanceof Media) {
            return null;
        }

        return URL::temporarySignedRoute(
            'admin.voice-notes.media.play',
            now()->addMinutes(15),
            ['voiceNote' => $record->getKey(), 'media' => $media->getKey()],
        );
    }
}
