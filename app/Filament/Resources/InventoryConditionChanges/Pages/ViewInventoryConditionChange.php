<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges\Pages;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Models\InventoryConditionChange;
use App\Models\User;
use App\Services\Inventory\InventoryConditionChangeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewInventoryConditionChange extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = InventoryConditionChangeResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('post')
                ->label('Post disposition')
                ->color('success')
                ->visible(fn (InventoryConditionChange $record): bool => $record->isDraft()
                    && (auth()->user()?->can('post', $record) ?? false))
                ->authorize(fn (InventoryConditionChange $record): bool => auth()->user()?->can('post', $record) ?? false)
                ->requiresConfirmation()
                ->modalDescription('Posting moves stock between inventory conditions. The movement is immutable and corrections require a new document.')
                ->action(fn (InventoryConditionChange $record) => $this->runConditionChangeAction(
                    fn (InventoryConditionChangeService $service, User $actor): InventoryConditionChange => $service->post($record, $actor),
                    'Quarantine disposition posted.',
                )),
            Action::make('cancel')
                ->color('danger')
                ->visible(fn (InventoryConditionChange $record): bool => $record->isDraft()
                    && (auth()->user()?->can('cancel', $record) ?? false))
                ->authorize(fn (InventoryConditionChange $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                ->schema([
                    Textarea::make('reason')->required()->maxLength(2_000),
                ])
                ->action(function (InventoryConditionChange $record, array $data): void {
                    $reason = $data['reason'] ?? null;

                    if (! is_string($reason)) {
                        throw new LogicException('A cancellation reason is required.');
                    }

                    $this->runConditionChangeAction(
                        fn (InventoryConditionChangeService $service, User $actor): InventoryConditionChange => $service->cancel($record, $actor, $reason),
                        'Quarantine disposition cancelled.',
                    );
                }),
        ];
    }

    private function runConditionChangeAction(callable $operation, string $successMessage): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory condition-change actor is required.');
        }

        $this->runInventoryOperation(
            fn (): mixed => $operation(app(InventoryConditionChangeService::class), $actor),
            $successMessage,
        );
    }
}
