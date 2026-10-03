<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Actions;

use App\Data\Sales\OrderFulfillmentLineProgress;
use App\Enums\InvoiceStatus;
use App\Enums\OperationStage;
use App\Enums\OrderStatus;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\OrderFulfillmentQuantityService;
use App\Services\Sales\OrderWorkflowService;
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
use WeakMap;

final class OrderActions
{
    use InteractsWithSalesServices;

    public static function confirm(): Action
    {
        return Action::make('confirm_order')
            ->label(__('Confirm order'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('Freeze the customer-facing commercial quantity, UOM, price and tax evidence. No stock will be reserved or moved.'))
            ->visible(fn (Order $record): bool => self::salesActor()?->can('confirm', $record) ?? false)
            ->action(function (Order $record): void {
                self::withActor(fn (User $actor) => app(SalesOrderService::class)->confirm($actor, $record));
                Notification::make()->success()->title(__('Customer order confirmed.'))->send();
            });
    }

    public static function release(): Action
    {
        return Action::make('release_order')
            ->label(__('Release to Logistics'))
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('Hand the confirmed demand to Logistics. Release does not create a delivery, reserve stock, or change on-hand quantity.'))
            ->visible(fn (Order $record): bool => self::salesActor()?->can('release', $record) ?? false)
            ->action(function (Order $record): void {
                self::withActor(fn (User $actor) => app(SalesOrderService::class)->release($actor, $record));
                Notification::make()->success()->title(__('Customer order released to Logistics.'))->send();
            });
    }

    /**
     * The single emphasized "what happens next" row action for the Orders work
     * queue. Confirm and release stay in-table operations ({@see confirm()},
     * {@see release()}); every later step belongs to another department's
     * resource, so this action links there with an explicit label and is only
     * visible when the user may open the target. Terminal and waiting orders
     * get no action (View only).
     */
    public static function nextStep(): Action
    {
        return Action::make('next_step')
            ->label(fn (Order $record): string => self::nextStepTarget($record) instanceof OrderNextStep ? self::nextStepTarget($record)->label : '')
            ->icon(fn (Order $record): Heroicon => self::nextStepTarget($record) instanceof OrderNextStep ? self::nextStepTarget($record)->icon : Heroicon::OutlinedArrowRight)
            ->button()
            ->color('primary')
            ->visible(fn (Order $record): bool => self::nextStepTarget($record) !== null)
            ->url(fn (Order $record): ?string => self::nextStepTarget($record)?->url);
    }

    public static function nextStepTarget(Order $order): ?OrderNextStep
    {
        /** @var WeakMap<Order, OrderNextStep|false>|null $cache */
        static $cache = null;
        $cache ??= new WeakMap;

        $cached = $cache[$order] ?? null;

        if ($cached instanceof OrderNextStep) {
            return $cached;
        }

        if ($cached === false) {
            return null;
        }

        $target = self::resolveNextStepTarget($order);
        $cache[$order] = $target ?? false;

        return $target;
    }

    private static function resolveNextStepTarget(Order $order): ?OrderNextStep
    {
        $user = self::salesActor();

        if (! $user instanceof User) {
            return null;
        }

        $label = app(OrderWorkflowService::class)->project($order)->nextActionLabel;
        $canOpenOutbound = OutboundFulfillmentResource::canView($order);

        return match ($label) {
            'Resolve supply requirement' => match (true) {
                PurchaseNeeds::canAccess() => new OrderNextStep(__('Resolve supply requirement'), Heroicon::OutlinedShoppingCart, PurchaseNeeds::getUrl()),
                $canOpenOutbound => new OrderNextStep(__('Review supply requirement'), Heroicon::OutlinedShoppingCart, OutboundFulfillmentResource::getUrl('view', ['record' => $order])),
                default => null,
            },
            'Allocate remaining demand' => $canOpenOutbound && $user->can('planFulfillment', $order)
                ? new OrderNextStep(__('Allocate stock'), Heroicon::OutlinedMap, OutboundFulfillmentResource::getUrl('view', ['record' => $order]))
                : null,
            'Dispatch goods' => $canOpenOutbound
                ? new OrderNextStep(__('Dispatch goods'), Heroicon::OutlinedTruck, OutboundFulfillmentResource::getUrl('view', ['record' => $order]))
                : null,
            'Confirm shipment arrival' => $canOpenOutbound
                ? new OrderNextStep(__('Confirm arrival'), Heroicon::OutlinedCheckBadge, OutboundFulfillmentResource::getUrl('view', ['record' => $order]))
                : null,
            'Create invoice' => self::createInvoiceTarget($order, $user),
            'Issue invoice' => self::issueInvoiceTarget($order, $user),
            'Complete payment' => $user->can('create', Payment::class)
                ? new OrderNextStep(__('Record payment'), Heroicon::OutlinedBanknotes, PaymentResource::getUrl('create'))
                : null,
            default => null,
        };
    }

    private static function createInvoiceTarget(Order $order, User $user): ?OrderNextStep
    {
        if (! $user->can('create', Invoice::class)) {
            return null;
        }

        $delivery = $order->deliveries()
            ->where('stage', OperationStage::Done->value)
            ->whereDoesntHave('invoiceDeliveryLink')
            ->orderBy('id')
            ->first();

        if (! $delivery instanceof InventoryOperation || ! DeliveryNoteResource::canView($delivery)) {
            return null;
        }

        return new OrderNextStep(__('Create invoice'), Heroicon::OutlinedDocumentPlus, DeliveryNoteResource::getUrl('view', ['record' => $delivery]));
    }

    private static function issueInvoiceTarget(Order $order, User $user): ?OrderNextStep
    {
        $invoice = $order->invoices()->where('status', InvoiceStatus::Draft->value)->orderByDesc('id')->first();

        if (! $invoice instanceof Invoice || ! $user->can('issue', $invoice) || ! InvoiceResource::canView($invoice)) {
            return null;
        }

        return new OrderNextStep(__('Issue invoice'), Heroicon::OutlinedDocumentCheck, InvoiceResource::getUrl('view', ['record' => $invoice]));
    }

    public static function cancel(): Action
    {
        return Action::make('cancel_order')
            ->label(__('Cancel order'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('reason')->label(__('Cancellation reason'))->required()->maxLength(2000),
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
                Notification::make()->success()->title(__('Customer order cancelled.'))->send();
            });
    }

    public static function shortClose(): Action
    {
        return Action::make('short_close_order')
            ->label(__('Short close remaining'))
            ->icon(Heroicon::OutlinedArchiveBoxXMark)
            ->color('warning')
            ->schema([
                Repeater::make('lines')
                    ->label(__('Quantities to abandon'))
                    ->default(fn (Order $record): array => self::shortCloseLines($record))
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->schema([
                        Hidden::make('order_line_id'),
                        TextInput::make('product')->disabled()->dehydrated(false),
                        TextInput::make('remaining')->label(__('Remaining to plan'))->disabled()->dehydrated(false),
                        TextInput::make('quantity')
                            ->label(__('Short-close quantity'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.000001)
                            ->required(),
                    ])
                    ->columns(3),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(2000),
            ])
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::Released
                && app(OrderFulfillmentQuantityService::class)->totals($record)['remaining'] > 0.000001
                && (self::salesActor()?->can('shortClose', $record) ?? false))
            ->authorize(fn (Order $record): bool => self::salesActor()?->can('shortClose', $record) ?? false)
            ->action(function (Order $record, array $data): void {
                $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
                $lineQuantities = [];

                foreach ($lines as $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    if (! is_numeric($line['order_line_id'] ?? null)) {
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

                Notification::make()->success()->title(__('Remaining demand short-closed.'))->send();
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
