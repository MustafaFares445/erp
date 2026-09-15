<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Data\Sales\OrderFulfillmentLineProgress;
use App\Enums\OrderStatus;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\OrderFulfillmentQuantityService;
use App\Services\Sales\SalesOrderService;
use App\Support\QuantityFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    public static function shortClose(): Action
    {
        return Action::make('short_close_order')
            ->label('Short close remaining')
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('warning')
            ->schema([
                Repeater::make('lines')
                    ->label('Quantities to abandon')
                    ->default(fn (Order $record): array => self::shortCloseLines($record))
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->schema([
                        Hidden::make('order_line_id'),
                        TextInput::make('product')->disabled()->dehydrated(false),
                        TextInput::make('remaining')->label('Remaining to plan')->disabled()->dehydrated(false),
                        TextInput::make('quantity')
                            ->label('Short-close quantity')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.000001)
                            ->required(),
                    ])
                    ->columns(3),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::Released
                && app(OrderFulfillmentQuantityService::class)->totals($record)['remaining'] > 0.000001
                && (self::salesActor()?->can('close', $record) ?? false))
            ->authorize(fn (Order $record): bool => self::salesActor()?->can('close', $record) ?? false)
            ->action(function (Order $record, array $data): void {
                $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
                $lineQuantities = [];

                foreach ($lines as $line) {
                    if (! is_array($line) || ! is_numeric($line['order_line_id'] ?? null)) {
                        continue;
                    }

                    $quantity = $line['quantity'] ?? null;
                    if (! is_numeric($quantity)) {
                        continue;
                    }

                    $lineQuantities[(int) $line['order_line_id']] = (float) $quantity;
                }

                $reason = $data['reason'] ?? null;
                if (! is_string($reason)) {
                    throw new LogicException('A short-close reason is required.');
                }

                self::withActor(fn (User $actor) => app(SalesOrderService::class)->shortClose(
                    $actor,
                    $record,
                    $lineQuantities,
                    $reason,
                ));

                Notification::make()->success()->title('Remaining demand short-closed.')->send();
            });
    }

    /** @return list<array{order_line_id:int,product:string,remaining:string,quantity:float}> */
    private static function shortCloseLines(Order $order): array
    {
        $progressByLine = app(OrderFulfillmentQuantityService::class)
            ->forOrder($order)
            ->keyBy(static fn (OrderFulfillmentLineProgress $progress): int => $progress->orderLineId);

        $lines = $order->lines
            ->map(function (OrderLine $line) use ($progressByLine): ?array {
                $progress = $progressByLine->get($line->id);
                if (! $progress instanceof OrderFulfillmentLineProgress) {
                    return null;
                }

                $remaining = $progress->remainingToPlanBase;
                if ($remaining <= 0.000001) {
                    return null;
                }

                $product = $line->productVariant;

                return [
                    'order_line_id' => $line->id,
                    'product' => $product instanceof ProductVariant
                        ? $product->sku
                        : (string) $line->product_variant_id,
                    'remaining' => QuantityFormatter::display($remaining),
                    'quantity' => $remaining,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return array_values($lines);
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
