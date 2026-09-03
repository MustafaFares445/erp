<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReservations\Actions;

use App\Enums\InventoryPermission;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Inventory\InventoryReservationService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final class InventoryReservationActions
{
    public static function release(): Action
    {
        return Action::make('release')
            ->label(__('admin.inventory.reservation.actions.release'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (InventoryReservation $record): bool => $record->isActive()
                && (auth()->user()?->can('release', $record) ?? false))
            ->authorize(fn (InventoryReservation $record): bool => auth()->user()?->can('release', $record) ?? false)
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.inventory.reservation.release_reason'))
                    ->required()
                    ->minLength(10)
                    ->maxLength(255),
            ])
            ->action(function (InventoryReservation $record, array $data): void {
                $actor = self::actor();
                $reason = self::reason($data);

                try {
                    app(InventoryReservationService::class)->release($record, $actor, $reason);
                } catch (DomainException $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('admin.inventory.notifications.error'))
                        ->body($exception->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('admin.inventory.reservation.notifications.released'))
                    ->send();
            });
    }

    public static function releaseSelected(): BulkAction
    {
        return BulkAction::make('release_selected')
            ->label(__('admin.inventory.reservation.actions.release_selected'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->can(InventoryPermission::ReservationRelease->value) ?? false)
            ->authorizeIndividualRecords('release')
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.inventory.reservation.release_reason'))
                    ->required()
                    ->minLength(10)
                    ->maxLength(255),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $actor = self::actor();
                $reason = self::reason($data);
                $released = 0;
                $skipped = 0;
                $failed = 0;

                foreach ($records->sortBy('id') as $record) {
                    if (! $record instanceof InventoryReservation) {
                        $skipped++;

                        continue;
                    }

                    if (! $record->isActive() || ! $actor->can('release', $record)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        app(InventoryReservationService::class)->release($record, $actor, $reason);
                        $released++;
                    } catch (DomainException) {
                        $failed++;
                    }
                }

                $notification = Notification::make()
                    ->title(__('admin.inventory.reservation.notifications.bulk_released', [
                        'released' => $released,
                        'skipped' => $skipped,
                        'failed' => $failed,
                    ]));

                if ($failed > 0) {
                    $notification->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory reservation actor is required.');
        }

        return $actor;
    }

    /** @param array<string, mixed> $data */
    private static function reason(array $data): string
    {
        $reason = $data['reason'] ?? null;

        if (! is_string($reason)) {
            throw new LogicException('A reservation release reason is required.');
        }

        return $reason;
    }
}
