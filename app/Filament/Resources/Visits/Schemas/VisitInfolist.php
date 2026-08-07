<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Schemas;

use App\Enums\VisitRecordChannel;
use App\Models\CustomerVisit;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
                        TextEntry::make('recorded_channel')
                            ->label('Recorded via')
                            ->badge()
                            ->color(static fn (VisitRecordChannel $state): string => $state === VisitRecordChannel::Field ? 'warning' : 'gray')
                            ->formatStateUsing(static fn (VisitRecordChannel $state): string => $state === VisitRecordChannel::Field
                                ? 'Field (locked except to admin)'
                                : $state->value),
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
                        RepeatableEntry::make('gpsLogs')
                            ->label('')
                            ->schema([
                                TextEntry::make('recorded_at')->dateTime(),
                                TextEntry::make('latitude'),
                                TextEntry::make('longitude'),
                            ])
                            ->columns(3)
                            ->placeholder('No GPS records for this visit'),
                    ]),
                Section::make('Attachments')
                    ->schema([
                        RepeatableEntry::make('attachments')
                            ->label('')
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
}
