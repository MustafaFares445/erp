<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Filament\Concerns\InteractsWithSalesServices;
use App\Models\Order;
use App\Models\User;
use App\Services\Sales\SalesOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use LogicException;

final class OrderActions
{
    use InteractsWithSalesServices;

    public static function confirm(): Action
    {
        return Action::make('confirm_order')
            ->label('Confirm order')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription('Freeze the customer-facing commercial quantity, UOM, price and tax evidence. No stock will be reserved or moved.')
            ->visible(fn (Order $record): bool => self::salesActor()?->can('confirm', $record) ?? false)
            ->action(function (Order $record): void {
                self::withActor(fn (User $actor) => app(SalesOrderService::class)->confirm($actor, $record));
                Notification::make()->success()->title('Customer order confirmed.')->send();
            });
    }

    public static function release(): Action
    {
        return Action::make('release_order')
            ->label('Release to Logistics')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription('Hand the confirmed demand to Logistics. Release does not create a delivery, reserve stock, or change on-hand quantity.')
            ->visible(fn (Order $record): bool => self::salesActor()?->can('release', $record) ?? false)
            ->action(function (Order $record): void {
                self::withActor(fn (User $actor) => app(SalesOrderService::class)->release($actor, $record));
                Notification::make()->success()->title('Customer order released to Logistics.')->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel_order')
            ->label('Cancel order')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('reason')->label('Cancellation reason')->required()->maxLength(2000),
            ])
            ->visible(fn (Order $record): bool => self::salesActor()?->can('cancel', $record) ?? false)
            ->action(function (Order $record, array $data): void {
                $reason = $data['reason'] ?? null;

                if (! is_string($reason)) {
                    throw new LogicException('A cancellation reason is required.');
                }

                self::withActor(fn (User $actor) => app(SalesOrderService::class)->cancel(
                    $actor,
                    $record,
                    $reason,
                ));
                Notification::make()->success()->title('Customer order cancelled.')->send();
            });
    }

    public static function close(): Action
    {
        return Action::make('close_order')
            ->label('Close order')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Order $record): bool => self::salesActor()?->can('close', $record) ?? false)
            ->action(function (Order $record): void {
                self::withActor(fn (User $actor) => app(SalesOrderService::class)->close($actor, $record));
                Notification::make()->success()->title('Customer order closed.')->send();
            });
    }

    /** @param callable(User):mixed $callback */
    private static function withActor(callable $callback): mixed
    {
        $actor = self::salesActor();
        if (! $actor instanceof User) {
            return null;
        }

        return self::runSalesOperation(fn () => $callback($actor));
    }
}
