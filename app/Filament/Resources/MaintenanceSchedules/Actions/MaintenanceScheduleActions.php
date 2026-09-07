<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Actions;

use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\User;
use App\Services\Support\MaintenanceScheduleGenerator;
use App\Services\Support\MaintenanceScheduleService;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Table/relation-manager actions for preventive maintenance schedules and
 * their occurrences (WP-3.6, GAP-MW-08).
 */
final class MaintenanceScheduleActions
{
    public static function raiseNow(): Action
    {
        return Action::make('raise_now')
            ->label('Raise now')
            ->requiresConfirmation()
            ->authorize('update')
            ->visible(static fn (MaintenanceSchedule $record): bool => $record->is_active)
            ->action(function (): void {
                try {
                    app(MaintenanceScheduleGenerator::class)->raiseDue();
                    Notification::make()->success()->title('Due occurrences raised')->send();
                } catch (DomainException $domainException) {
                    Notification::make()->danger()->title('Unable to raise due occurrences')->body($domainException->getMessage())->send();
                }
            });
    }

    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->color('danger')
            ->requiresConfirmation()
            ->authorize('update')
            ->visible(static fn (MaintenanceSchedule $record): bool => $record->is_active)
            ->action(function (MaintenanceSchedule $record): void {
                app(MaintenanceScheduleService::class)->deactivate($record, self::currentActor());
                Notification::make()->success()->title('Schedule deactivated')->send();
            });
    }

    public static function skipOccurrence(): Action
    {
        return Action::make('skip_occurrence')
            ->label('Skip')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')->required()->label('Reason'),
            ])
            ->authorize(fn (MaintenanceScheduleOccurrence $record): bool => self::currentActor()->can('update', $record->schedule))
            ->action(self::handleSkip(...));
    }

    /**
     * @param  array{reason: string}  $data
     */
    private static function handleSkip(MaintenanceScheduleOccurrence $record, array $data): void
    {
        try {
            app(MaintenanceScheduleGenerator::class)->skip($record, self::currentActor(), $data['reason']);
            Notification::make()->success()->title('Occurrence skipped')->send();
        } catch (DomainException|ValidationException $exception) {
            Notification::make()->danger()->title('Unable to skip this occurrence')->body($exception->getMessage())->send();
        }
    }

    private static function currentActor(): User
    {
        $actor = auth()->user();

        // @codeCoverageIgnoreStart
        if (! $actor instanceof User) {
            throw new LogicException('An authenticated User is required.');
        }

        // @codeCoverageIgnoreEnd

        return $actor;
    }
}
