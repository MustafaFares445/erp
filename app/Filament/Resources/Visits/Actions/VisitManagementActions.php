<?php

declare(strict_types=1);

namespace App\Filament\Resources\Visits\Actions;

use App\Models\CustomerVisit;
use App\Services\Employees\VisitReviewService;
use App\Services\Employees\VisitSchedulingService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

final class VisitManagementActions
{
    public static function reschedule(): Action
    {
        return Action::make('reschedule')
            ->label(__('Reschedule'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->authorize('schedule')
            ->visible(static fn (CustomerVisit $record): bool => ! $record->isTerminal())
            ->fillForm(static fn (CustomerVisit $record): array => [
                'scheduled_start_at' => $record->scheduled_start_at,
                'scheduled_end_at' => $record->scheduled_end_at,
                'override_conflict' => false,
                'override_reason' => null,
            ])
            ->schema([
                DateTimePicker::make('scheduled_start_at')->label(__('Scheduled start'))->required()->seconds(false),
                DateTimePicker::make('scheduled_end_at')->label(__('Scheduled end'))->required()->seconds(false),
                Toggle::make('override_conflict')
                    ->label(__('Override scheduling conflict'))
                    ->helperText(__('Use only when the overlap is intentional and approved.'))
                    ->live(),
                Textarea::make('override_reason')
                    ->label(__('Conflict override reason'))
                    ->helperText(__('Required when an overlap must be intentionally accepted.'))
                    ->required(static fn (Get $get): bool => (bool) $get('override_conflict'))
                    ->visible(static fn (Get $get): bool => (bool) $get('override_conflict'))
                    ->rows(3),
            ])
            ->action(static function (CustomerVisit $record, array $data): void {
                try {
                    app(VisitSchedulingService::class)->reschedule(
                        $record,
                        Carbon::parse(self::string($data, 'scheduled_start_at')),
                        Carbon::parse(self::string($data, 'scheduled_end_at')),
                        (bool) ($data['override_conflict'] ?? false),
                        is_string($data['override_reason'] ?? null) ? $data['override_reason'] : null,
                    );

                    Notification::make()->success()->title(__('Visit rescheduled'))->send();
                } catch (DomainException $domainException) {
                    Notification::make()
                        ->danger()
                        ->title(__('Unable to reschedule the visit'))
                        ->body($domainException->getMessage())
                        ->send();
                }
            });
    }

    public static function review(): Action
    {
        return Action::make('review')
            ->label(__('Add / update review note'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->authorize('review')
            ->fillForm(static fn (CustomerVisit $record): array => ['review_note' => $record->review_note])
            ->schema([
                Textarea::make('review_note')
                    ->label(__('Review note'))
                    ->required()
                    ->rows(4),
            ])
            ->action(static function (CustomerVisit $record, array $data): void {
                $note = $data['review_note'] ?? null;
                app(VisitReviewService::class)->updateReviewNote($record, is_string($note) ? $note : '');
            });
    }

    /** @param array<array-key, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new DomainException("Expected {$key}.");
        }

        return $value;
    }
}
