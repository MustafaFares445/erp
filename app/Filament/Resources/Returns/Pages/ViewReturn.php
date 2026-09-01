<?php

declare(strict_types=1);

namespace App\Filament\Resources\Returns\Pages;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\Returns\ReturnResource;
use App\Models\InventoryReturn;
use App\Models\User;
use App\Services\Inventory\InventoryReturnService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewReturn extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = ReturnResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('markReady')
                ->label(__('admin.inventory.return.actions.mark_ready'))
                ->color('warning')
                ->visible(fn (InventoryReturn $record): bool => $record->isDraft()
                    && (auth()->user()?->can('markReady', $record) ?? false))
                ->authorize(fn (InventoryReturn $record): bool => auth()->user()?->can('markReady', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (InventoryReturn $record) => $this->runReturnAction(
                    $record,
                    fn (InventoryReturnService $service, User $actor): InventoryReturn => $service->markReady($record, $actor),
                    'admin.inventory.return.notifications.ready',
                )),
            Action::make('post')
                ->label(__('admin.inventory.return.actions.post'))
                ->color('success')
                ->visible(fn (InventoryReturn $record): bool => $record->isReady()
                    && (auth()->user()?->can('post', $record) ?? false))
                ->authorize(fn (InventoryReturn $record): bool => auth()->user()?->can('post', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (InventoryReturn $record) => $this->runReturnAction(
                    $record,
                    fn (InventoryReturnService $service, User $actor): InventoryReturn => $service->post($record, $actor),
                    'admin.inventory.return.notifications.posted',
                )),
            Action::make('cancel')
                ->label(__('admin.inventory.return.actions.cancel'))
                ->color('danger')
                ->visible(fn (InventoryReturn $record): bool => ! $record->isTerminal()
                    && (auth()->user()?->can('cancel', $record) ?? false))
                ->authorize(fn (InventoryReturn $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                ->schema([
                    Textarea::make('reason')
                        ->label(__('admin.inventory.return.reason'))
                        ->maxLength(2_000),
                ])
                ->requiresConfirmation()
                ->action(function (InventoryReturn $record, array $data): void {
                    $reason = is_string($data['reason'] ?? null) ? mb_trim($data['reason']) : null;

                    $this->runReturnAction(
                        $record,
                        fn (InventoryReturnService $service, User $actor): InventoryReturn => $service->cancel(
                            $record,
                            $actor,
                            $reason === '' ? null : $reason,
                        ),
                        'admin.inventory.return.notifications.cancelled',
                    );
                }),
        ];
    }

    private function runReturnAction(
        InventoryReturn $record,
        callable $operation,
        string $notification,
    ): void {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory return actor is required.');
        }

        $this->runInventoryOperation(
            function () use ($record, $operation, $actor): void {
                $updated = $operation(app(InventoryReturnService::class), $actor);

                if ($updated instanceof InventoryReturn) {
                    $record->refresh();
                }
            },
            $notification,
        );
    }
}
