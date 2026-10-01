<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Schemas;

use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\SalesOpportunity;
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
                        TextEntry::make('employee.user.name')->label(__('Employee')),
                        TextEntry::make('customer.company_name')->label(__('Customer'))->placeholder(__('Not linked')),
                        TextEntry::make('planTask.title')->label(__('Plan task'))->placeholder(__('Not linked')),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('duration')
                            ->label(__('Duration'))
                            ->state(static fn (CustomerVisit $record): ?string => $record->durationMinutes() !== null
                                ? $record->durationMinutes().' min'
                                : null)
                            ->placeholder(__('Not verifiable')),
                        TextEntry::make('checked_in_at')->dateTime(),
                        TextEntry::make('checked_out_at')->dateTime()->placeholder(__('Not checked out')),
                        TextEntry::make('outcome')->placeholder(__('Not recorded'))->columnSpanFull(),
                    ]),
                Section::make(__('Review'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('review_note')->label(__('Review note'))->placeholder(__('No review note yet'))->columnSpanFull(),
                        TextEntry::make('reviewer.name')->label(__('Reviewed by'))->placeholder(__('—')),
                        TextEntry::make('reviewed_at')->dateTime()->placeholder(__('—')),
                    ]),
                Section::make(__('GPS trail'))
                    ->schema([
                        View::make('filament.visits.gps-trail-map')
                            ->viewData(static function (CustomerVisit $record): array {
                                $customer = $record->customer;

                                return [
                                    'points' => $record->gpsLogs
                                        ->map(static fn (VisitGpsLog $log): array => [
                                            'latitude' => (float) $log->latitude,
                                            'longitude' => (float) $log->longitude,
                                            'recordedAt' => $log->recorded_at->toIso8601String(),
                                        ])
                                        ->all(),
                                    'customerLocation' => $customer !== null && $customer->latitude !== null && $customer->longitude !== null
                                        ? [
                                            'latitude' => (float) $customer->latitude,
                                            'longitude' => (float) $customer->longitude,
                                            'label' => $customer->company_name,
                                        ]
                                        : null,
                                ];
                            }),
                    ]),
                Section::make(__('Voice notes'))
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
                Section::make(__('Sales Opportunity'))
                    ->schema([
                        RepeatableEntry::make('salesOpportunities')
                            ->hiddenLabel()
                            ->state(static fn (CustomerVisit $record): array => $record->salesOpportunities()
                                ->map(static fn (SalesOpportunity $opportunity): array => [
                                    'summary' => $opportunity->summary,
                                    'status' => $opportunity->status->value,
                                    'keyword' => $opportunity->keywordRule?->keyword,
                                ])
                                ->all())
                            ->schema([
                                TextEntry::make('summary')->label(__('Summary'))->columnSpanFull(),
                                TextEntry::make('keyword')->label(__('Matched keyword'))->placeholder(__('—')),
                                TextEntry::make('status')->label(__('Status'))->badge(),
                            ])
                            ->columns(2)
                            ->placeholder(__('No sales opportunity detected for this visit')),
                    ]),
                Section::make(__('Attachments'))
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
                                TextEntry::make('file_name')->label(__('File')),
                                TextEntry::make('preview_url')
                                    ->label(__('Preview'))
                                    ->formatStateUsing(static fn (): string => 'Preview')
                                    ->url(static fn (string $state): string => $state)
                                    ->openUrlInNewTab(),
                                TextEntry::make('download_url')
                                    ->label(__('Download'))
                                    ->formatStateUsing(static fn (): string => 'Download')
                                    ->url(static fn (string $state): string => $state)
                                    ->openUrlInNewTab(),
                            ])
                            ->columns(3)
                            ->placeholder(__('No attachments for this visit')),
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
