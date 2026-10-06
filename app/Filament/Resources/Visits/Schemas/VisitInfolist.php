<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Schemas;

use App\Enums\VisitOutcome;
use App\Enums\VisitStatus;
use App\Models\CustomerVisit;
use App\Models\EmployeeVoiceNote;
use App\Models\SalesOpportunity;
use App\Models\VisitGpsLog;
use App\Services\Employees\VisitTimelineService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class VisitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Basic information'))
                ->columns(3)
                ->schema([
                    TextEntry::make('reference')->label(__('Visit reference'))->placeholder(__('Legacy visit')),
                    TextEntry::make('customer.company_name')->label(__('Customer')),
                    TextEntry::make('employee.user.name')->label(__('Employee')),
                    TextEntry::make('visit_type')->label(__('Visit type'))->placeholder(__('General')),
                    TextEntry::make('planTask.title')->label(__('Plan task')),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(static fn (VisitStatus $state): string => $state->label())
                        ->color(static fn (VisitStatus $state): string => $state->color()),
                    TextEntry::make('scheduled_start_at')->label(__('Scheduled start'))->dateTime()->placeholder(__('—')),
                    TextEntry::make('scheduled_end_at')->label(__('Scheduled end'))->dateTime()->placeholder(__('—')),
                    TextEntry::make('duration')
                        ->label(__('Actual duration'))
                        ->state(static fn (CustomerVisit $record): ?string => $record->durationMinutes() !== null
                            ? $record->durationMinutes().' min'
                            : null)
                        ->placeholder(__('Not verifiable')),
                    TextEntry::make('en_route_at')->label(__('En route at'))->dateTime()->placeholder(__('—')),
                    TextEntry::make('checked_in_at')->label(__('Checked in at'))->dateTime()->placeholder(__('—')),
                    TextEntry::make('checked_out_at')->label(__('Checked out at'))->dateTime()->placeholder(__('—')),
                ]),

            Grid::make(['default' => 1, 'lg' => 2])
                ->columnSpanFull()
                ->schema([
                    Section::make(__('Location evidence'))
                        ->schema([
                            TextEntry::make('customer.address')->label(__('Customer address'))->placeholder(__('Not provided')),
                            TextEntry::make('customer_coordinates')
                                ->label(__('Customer coordinates'))
                                ->state(static function (CustomerVisit $record): string {
                                    $customer = $record->customer;

                                    return $customer !== null && $customer->latitude !== null && $customer->longitude !== null
                                        ? $customer->latitude.', '.$customer->longitude
                                        : self::translation('Not provided');
                                }),
                            TextEntry::make('check_in_coordinates')
                                ->label(__('Check-in coordinates'))
                                ->state(static fn (CustomerVisit $record): string => $record->check_in_latitude !== null && $record->check_in_longitude !== null
                                    ? $record->check_in_latitude.', '.$record->check_in_longitude
                                    : __('Not recorded')),
                            TextEntry::make('check_in_accuracy_meters')->label(__('GPS accuracy'))->suffix(' m')->placeholder(__('—')),
                            TextEntry::make('distance_from_customer_meters')->label(__('Distance from customer'))->suffix(' m')->placeholder(__('—')),
                            IconEntry::make('location_warning')->label(__('Location warning'))->boolean(),
                            TextEntry::make('location_override_reason')
                                ->label(__('Location override reason'))
                                ->placeholder(__('No override recorded'))
                                ->columnSpanFull(),
                            TextEntry::make('locationOverriddenBy.name')->label(__('Override by'))->placeholder(__('—')),
                            TextEntry::make('location_overridden_at')->label(__('Override at'))->dateTime()->placeholder(__('—')),
                        ])
                        ->columns(2),
                    Section::make(__('Visit result'))
                        ->schema([
                            TextEntry::make('outcome_code')
                                ->label(__('Outcome'))
                                ->formatStateUsing(static fn (?VisitOutcome $state): string => $state?->label() ?? __('Not recorded'))
                                ->badge(),
                            IconEntry::make('follow_up_required')->label(__('Follow-up required'))->boolean(),
                            TextEntry::make('follow_up_date')->label(__('Follow-up date'))->date()->placeholder(__('—')),
                            TextEntry::make('outcome_notes')->label(__('Outcome notes'))->placeholder(__('No outcome notes'))->columnSpanFull(),
                            TextEntry::make('employee_notes')->label(__('Employee notes'))->placeholder(__('No employee notes'))->columnSpanFull(),
                            TextEntry::make('follow_up_note')->label(__('Follow-up note'))->placeholder(__('No follow-up note'))->columnSpanFull(),
                            TextEntry::make('schedule_override_reason')
                                ->label(__('Schedule conflict override'))
                                ->placeholder(__('No conflict override'))
                                ->columnSpanFull(),
                            TextEntry::make('scheduleOverriddenBy.name')->label(__('Schedule override by'))->placeholder(__('—')),
                            TextEntry::make('schedule_overridden_at')->label(__('Schedule override at'))->dateTime()->placeholder(__('—')),
                        ])
                        ->columns(2),
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

            Section::make(__('Sales activity'))
                ->description(__('AI detections remain reviewable evidence until a manager accepts or rejects them.'))
                ->schema([
                    RepeatableEntry::make('sales_activity')
                        ->hiddenLabel()
                        ->state(static fn (CustomerVisit $record): array => $record->salesOpportunities()
                            ->map(static fn (SalesOpportunity $opportunity): array => [
                                'summary' => $opportunity->summary,
                                'status' => $opportunity->status->label(),
                                'product' => self::detectedProductLabel($opportunity),
                                'transcript_excerpt' => $opportunity->transcript_excerpt ?? $opportunity->origin_summary,
                                'quotation' => $opportunity->quotation?->quotation_number,
                                'deliveries' => $opportunity->quotation?->convertedOrder?->deliveries()->count() ?? 0,
                                'rejection_reason' => $opportunity->rejection_reason,
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('summary')->label(__('Summary'))->columnSpanFull(),
                            TextEntry::make('status')->label(__('Review status'))->badge(),
                            TextEntry::make('product')->label(__('Detected product'))->placeholder(__('—')),
                            TextEntry::make('quotation')->label(__('Quotation'))->placeholder(__('Not created')),
                            TextEntry::make('deliveries')->label(__('Delivery records'))->numeric(),
                            TextEntry::make('transcript_excerpt')->label(__('Transcript evidence'))->placeholder(__('—'))->columnSpanFull(),
                            TextEntry::make('rejection_reason')->label(__('Rejection reason'))->placeholder(__('—'))->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->placeholder(__('No sales opportunity evidence for this visit')),
                ]),

            Section::make(__('Voice notes and transcription'))
                ->schema([
                    RepeatableEntry::make('voice_note_details')
                        ->hiddenLabel()
                        ->state(static fn (CustomerVisit $record): array => $record->voiceNotes()
                            ->with('transcription')
                            ->get()
                            ->map(static fn (EmployeeVoiceNote $note): array => [
                                'language' => $note->language,
                                'duration' => $note->duration_seconds !== null ? $note->duration_seconds.' sec' : null,
                                'status' => $note->status->value,
                                'play_url' => self::voiceNotePlayUrl($note),
                                'transcript' => $note->transcription?->transcript,
                                'transcription_status' => $note->transcription?->status->value,
                                'confidence' => $note->transcription?->confidenceLabel(),
                                'error' => $note->transcription?->error_message,
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('language')->label(__('Language'))->placeholder(__('Unknown')),
                            TextEntry::make('duration')->label(__('Duration'))->placeholder(__('—')),
                            TextEntry::make('status')->label(__('Voice status'))->badge(),
                            TextEntry::make('transcription_status')->label(__('Transcription status'))->badge()->placeholder(__('Pending')),
                            TextEntry::make('confidence')->label(__('Confidence'))->placeholder(__('Unavailable')),
                            TextEntry::make('play_url')
                                ->label(__('Audio'))
                                ->formatStateUsing(static fn (?string $state): string => $state !== null ? __('Play audio') : __('Audio unavailable'))
                                ->url(static fn (?string $state): ?string => $state)
                                ->openUrlInNewTab(),
                            TextEntry::make('transcript')->label(__('Transcript'))->placeholder(__('No transcript'))->columnSpanFull(),
                            TextEntry::make('error')->label(__('Transcription error'))->placeholder(__('—'))->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->placeholder(__('No voice notes for this visit')),
                ]),

            Section::make(__('Manager review'))
                ->columns(2)
                ->schema([
                    TextEntry::make('review_note')->label(__('Review note'))->placeholder(__('No review note yet'))->columnSpanFull(),
                    TextEntry::make('reviewer.name')->label(__('Reviewed by'))->placeholder(__('—')),
                    TextEntry::make('reviewed_at')->dateTime()->placeholder(__('—')),
                ]),

            Section::make(__('Visit timeline'))
                ->description(__('Chronological operational and audit events for this visit.'))
                ->schema([
                    RepeatableEntry::make('timeline')
                        ->hiddenLabel()
                        ->state(static fn (CustomerVisit $record): array => app(VisitTimelineService::class)->forVisit($record))
                        ->schema([
                            TextEntry::make('occurred_at')->label(__('Time'))->dateTime(),
                            TextEntry::make('action')->label(__('Action')),
                            TextEntry::make('actor')->label(__('Actor')),
                            TextEntry::make('context')->label(__('Context'))->placeholder(__('—')),
                        ])
                        ->columns(4)
                        ->placeholder(__('No timeline events recorded')),
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
                                ->formatStateUsing(static fn (): string => __('Preview'))
                                ->url(static fn (string $state): string => $state)
                                ->openUrlInNewTab(),
                            TextEntry::make('download_url')
                                ->label(__('Download'))
                                ->formatStateUsing(static fn (): string => __('Download'))
                                ->url(static fn (string $state): string => $state)
                                ->openUrlInNewTab(),
                        ])
                        ->columns(3)
                        ->placeholder(__('No attachments for this visit')),
                ]),
        ]);
    }

    private static function detectedProductLabel(SalesOpportunity $opportunity): ?string
    {
        $variant = $opportunity->detectedProductVariant;

        if ($variant !== null) {
            return $variant->sku;
        }

        $product = $opportunity->detectedProduct;

        if ($product !== null) {
            return $product->name;
        }

        return $opportunity->keywordRule?->keyword;
    }

    private static function translation(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
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
