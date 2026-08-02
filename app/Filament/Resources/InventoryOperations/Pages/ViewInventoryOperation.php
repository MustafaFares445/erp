<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Models\User;
use App\Services\Inventory\InventoryOperationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use LogicException;

final class ViewInventoryOperation extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = InventoryOperationResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (InventoryOperation $record): bool => $record->isDraft()),
            $this->transitionAction('markReady', 'ready', 'admin.inventory.operation.notifications.ready'),
            $this->transitionAction('dispatch', 'dispatch', 'admin.inventory.operation.notifications.dispatched'),
            $this->transitionAction('complete', 'complete', 'admin.inventory.operation.notifications.completed'),
            $this->transitionAction('cancel', 'cancel', 'admin.inventory.operation.notifications.canceled'),
        ];
    }

    private function transitionAction(string $ability, string $method, string $notification): Action
    {
        return Action::make($ability)
            ->label(Str::headline($ability))
            ->visible(fn (InventoryOperation $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->authorize(fn (InventoryOperation $record): bool => auth()->user()?->can($ability, $record) ?? false)
            ->action(function (InventoryOperation $record) use ($method, $notification): void {
                $actor = auth()->user();

                // @codeCoverageIgnoreStart
                // Unreachable in practice: this action's `authorize()` closure already requires
                // an authenticated user able to perform the ability, so the guard exists only to
                // narrow `auth()->user()`'s nullable, generic Authenticatable type for static
                // analysis.
                if (! $actor instanceof User) {
                    throw new LogicException('An authenticated inventory operation actor is required.');
                }

                // @codeCoverageIgnoreEnd

                $service = app(InventoryOperationService::class);

                $this->runInventoryOperation(
                    fn (): InventoryOperation => match ($method) {
                        'ready' => $service->markReady($record),
                        'dispatch' => $service->dispatch($record, $actor),
                        'complete' => $service->complete($record, $actor),
                        'cancel' => $service->cancel($record, $actor, 'Canceled from the inventory operation screen.'),
                        default => throw new LogicException(sprintf('Unknown inventory operation transition [%s].', $method)),
                    },
                    $notification,
                );
            });
    }
}
