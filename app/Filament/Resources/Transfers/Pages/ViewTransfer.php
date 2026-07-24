<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\Transfers\TransferResource;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Inventory\StockTransferService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Hosts the **Confirm** action — the sole stock-mutating control in the
 * whole resource (FR-008). It is a thin adapter: `runInventoryOperation()`
 * (FI-0's {@see InteractsWithInventoryServices} concern) calls
 * {@see StockTransferService::confirm()} and turns the outcome into a
 * notification; this page computes nothing itself.
 */
final class ViewTransfer extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = TransferResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (StockTransfer $record): bool => $record->isDraft()),
            Action::make('dispatch')
                ->label(__('admin.inventory.transfer.dispatch'))
                ->color('success')
                ->authorize(fn (StockTransfer $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                ->visible(fn (StockTransfer $record): bool => $record->isDraft()
                    && (auth()->user()?->can('confirm', $record) ?? false))
                ->requiresConfirmation()
                ->action(function (StockTransfer $record): void {
                    $actor = auth()->user();

                    // @codeCoverageIgnoreStart
                    // Unreachable in practice: the ->authorize() gate above already
                    // requires auth()->user()?->can('confirm', ...) to be true before
                    // Filament invokes this closure, so $actor is never null here. The
                    // guard exists only to satisfy static analysis (auth()->user() is
                    // typed nullable) without widening the service's User parameter.
                    if (! $actor instanceof User) {
                        return;
                    }

                    // @codeCoverageIgnoreEnd

                    $this->runInventoryOperation(
                        fn () => app(StockTransferService::class)->dispatch($record, $actor),
                        'admin.inventory.transfer.notifications.dispatched',
                    );
                }),
            Action::make('receive')
                ->label(__('admin.inventory.transfer.receive'))
                ->color('success')
                ->authorize(fn (StockTransfer $record): bool => auth()->user()?->can('receive', $record) ?? false)
                ->visible(fn (StockTransfer $record): bool => $record->isDispatched()
                    && (auth()->user()?->can('receive', $record) ?? false))
                ->requiresConfirmation()
                ->action(function (StockTransfer $record): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    $this->runInventoryOperation(
                        fn () => app(StockTransferService::class)->receive($record, $actor),
                        'admin.inventory.transfer.notifications.received',
                    );
                }),
        ];
    }
}
