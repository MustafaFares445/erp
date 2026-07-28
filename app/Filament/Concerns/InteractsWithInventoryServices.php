<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Forces every inventory-affecting Filament action to be a thin adapter
 * over a domain service (constitution Principle III; plan.md §2.1).
 *
 * {@see self::runInventoryOperation()} invokes the given operation and
 * translates its outcome into a Filament notification. It performs no
 * database writes and computes no stock itself — the operation it wraps is
 * expected to own its own transaction, so a thrown exception leaves no
 * partial state.
 *
 * @see /specs/001-inventory-dashboard-foundation/contracts/action-adapter.md
 */
trait InteractsWithInventoryServices
{
    protected function runInventoryOperation(callable $operation, string $successMessageKey): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->notifyInventoryOperationFailure(
                implode(' ', $exception->validator->errors()->all()),
            );

            return;
        } catch (DomainException $exception) {
            $this->notifyInventoryOperationFailure($exception->getMessage());

            return;
        }

        Notification::make()
            ->success()
            ->title(__($successMessageKey))
            ->send();
    }

    private function notifyInventoryOperationFailure(string $message): void
    {
        Notification::make()
            ->danger()
            ->title(__('admin.inventory.notifications.error'))
            ->body($message)
            ->send();
    }
}
