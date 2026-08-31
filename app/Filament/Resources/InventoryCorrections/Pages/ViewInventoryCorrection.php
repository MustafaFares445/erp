<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCorrections\Pages;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\InventoryCorrections\InventoryCorrectionResource;
use App\Models\InventoryCorrection;
use App\Models\User;
use App\Services\Inventory\InventoryCorrectionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewInventoryCorrection extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = InventoryCorrectionResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            Action::make('post')
                ->label(__('admin.inventory.correction.actions.post'))
                ->color('success')
                ->visible(fn (InventoryCorrection $record): bool => $record->isDraft()
                    && (auth()->user()?->can('post', $record) ?? false))
                ->authorize(fn (InventoryCorrection $record): bool => auth()->user()?->can('post', $record) ?? false)
                ->requiresConfirmation()
                ->action(fn (InventoryCorrection $record) => $this->runCorrectionAction(
                    fn (InventoryCorrectionService $service, User $actor): InventoryCorrection => $service->post(
                        $record,
                        $actor,
                    ),
                    'admin.inventory.correction.notifications.posted',
                )),
            Action::make('cancel')
                ->label(__('admin.inventory.correction.actions.cancel'))
                ->color('danger')
                ->visible(fn (InventoryCorrection $record): bool => $record->isDraft()
                    && (auth()->user()?->can('cancel', $record) ?? false))
                ->authorize(fn (InventoryCorrection $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                ->schema([
                    Textarea::make('reason')
                        ->label(__('admin.inventory.correction.cancellation_reason'))
                        ->required()
                        ->maxLength(2_000),
                ])
                ->requiresConfirmation()
                ->action(function (InventoryCorrection $record, array $data): void {
                    $reason = $data['reason'] ?? null;

                    if (! is_string($reason)) {
                        throw new LogicException('A correction cancellation reason is required.');
                    }

                    $this->runCorrectionAction(
                        fn (InventoryCorrectionService $service, User $actor): InventoryCorrection => $service->cancel(
                            $record,
                            $actor,
                            $reason,
                        ),
                        'admin.inventory.correction.notifications.cancelled',
                    );
                }),
        ];
    }

    private function runCorrectionAction(callable $operation, string $notification): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory correction actor is required.');
        }

        $this->runInventoryOperation(
            fn (): mixed => $operation(app(InventoryCorrectionService::class), $actor),
            $notification,
        );
    }
}
