<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Schemas;

use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\VisitGpsLog;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class VisitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('employee.user.name')->label('Employee'),
                        TextEntry::make('customer.company_name')->label('Customer')->placeholder('Not linked'),
                        TextEntry::make('planTask.title')->label('Plan task')->placeholder('Not linked'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('duration')
                            ->label('Duration')
                            ->state(static fn (CustomerVisit $record): ?string => $record->durationMinutes() !== null
                                ? $record->durationMinutes().' min'
                                : null)
                            ->placeholder('Not verifiable'),
                        TextEntry::make('checked_in_at')->dateTime(),
                        TextEntry::make('checked_out_at')->dateTime()->placeholder('Not checked out'),
                        TextEntry::make('outcome')->placeholder('Not recorded')->columnSpanFull(),
                    ]),
                Section::make('Review')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('review_note')->label('Review note')->placeholder('No review note yet')->columnSpanFull(),
                        TextEntry::make('reviewer.name')->label('Reviewed by')->placeholder('—'),
                        TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
                    ]),
                Section::make('GPS trail')
                    ->schema([
                        View::make('filament.visits.gps-trail-map')
                            ->viewData(static fn (CustomerVisit $record): array => [
                                'points' => $record->gpsLogs
                                    ->map(static fn (VisitGpsLog $log): array => [
                                        'latitude' => (float) $log->latitude,
                                        'longitude' => (float) $log->longitude,
                                        'recordedAt' => $log->recorded_at->toIso8601String(),
                                    ])
                                    ->all(),
                                'customerLocation' => $record->customer?->latitude !== null && $record->customer?->longitude !== null
                                    ? [
                                        'latitude' => (float) $record->customer->latitude,
                                        'longitude' => (float) $record->customer->longitude,
                                        'label' => $record->customer->company_name,
                                    ]
                                    : null,
                            ]),
                    ]),
                Section::make('Voice notes')
                    ->schema([
                        View::make('filament.visits.voice-notes')
                            ->viewData(static fn (CustomerVisit $record): array => [
                                'notes' => $record->voiceNotes
                                    ->map(static fn (EmployeeVoiceNote $note): array => [
                                        'language' => $note->language,
                                        'duration_seconds' => $note->duration_seconds,
                                        'play_url' => self::voiceNotePlayUrl($note),
                                    ])
                                    ->all(),
                            ]),
                    ]),
                Section::make('Attachments')
                    ->schema([
                        RepeatableEntry::make('attachments')
                            ->hiddenLabel()
                            ->state(static fn (CustomerVisit $record): array => $record->getMedia('visit-attachments')
                                ->map(static fn (Media $media): array => [
                                    'file_name' => $media->file_name,
                                    'preview_url' => route('admin.visits.media.preview', ['visit' => $record, 'media' => $media]),
                                    'download_url' => route('admin.visits.media.download', ['visit' => $record, 'media' => $media]),
                                ])
                                ->all())
                            ->schema([
                                TextEntry::make('file_name')->label('File'),
                                TextEntry::make('preview_url')
                                    ->label('Preview')
                                    ->formatStateUsing(static fn (): string => 'Preview')
                                    ->url(static fn (string $state): string => $state)
                                    ->openUrlInNewTab(),
                                TextEntry::make('download_url')
                                    ->label('Download')
                                    ->formatStateUsing(static fn (): string => 'Download')
                                    ->url(static fn (string $state): string => $state)
                                    ->openUrlInNewTab(),
                            ])
                            ->columns(3)
                            ->placeholder('No attachments for this visit'),
                    ]),
            ]);
    }

    private static function voiceNotePlayUrl(EmployeeVoiceNote $note): ?string
    {
        $media = $note->getFirstMedia('voice-note-audio');

        if (! $media instanceof Media) {
            return null;
        }

        return URL::temporarySignedRoute(
            'admin.voice-notes.media.play',
            now()->addMinutes(15),
            ['voiceNote' => $note->getKey(), 'media' => $media->getKey()],
        );
    }
}
