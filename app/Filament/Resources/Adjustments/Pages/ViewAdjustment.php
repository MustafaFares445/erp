<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Pages;

use App\Filament\Concerns\InteractsWithInventoryServices;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Models\InventoryAdjustment;
use App\Models\User;
use App\Services\Inventory\InventoryAdjustmentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Hosts the **Confirm** action — the sole stock-mutating control in the
 * whole resource (FR-009). It is a thin adapter: `runInventoryOperation()`
 * (FI-0's {@see InteractsWithInventoryServices} concern) calls
 * {@see InventoryAdjustmentService::confirm()} and turns the outcome into a
 * notification; this page computes nothing itself.
 */
final class ViewAdjustment extends ViewRecord
{
    use InteractsWithInventoryServices;

    protected static string $resource = AdjustmentResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (InventoryAdjustment $record): bool => $record->isDraft()),
            Action::make('confirm')
                ->label(__('admin.inventory.adjustment.confirm'))
                ->color('success')
                ->authorize(fn (InventoryAdjustment $record): bool => auth()->user()?->can('confirm', $record) ?? false)
                ->visible(fn (InventoryAdjustment $record): bool => $record->isDraft()
                    && (auth()->user()?->can('confirm', $record) ?? false))
                ->requiresConfirmation()
                ->action(function (InventoryAdjustment $record): void {
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
                        fn () => app(InventoryAdjustmentService::class)->confirm($record, $actor),
                        'admin.inventory.adjustment.notifications.confirmed',
                    );
                }),
        ];
    }
}
