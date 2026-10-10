<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\OperationStage;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StockCondition;
use App\Jobs\GenerateInvoiceDocument;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Logistics\OutboundDispatchService;
use App\Services\Logistics\OutboundFulfillmentService;
use App\Services\Payments\PaymentService;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesOrderService;
use App\Services\Shipments\ShipmentArrivalConfirmationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Posted sales-to-stock-to-AR journeys on the ONE developer database.
 *
 * B2B shipments of synthetic batch-tracked dental supplies; every order goes
 * through the real order, logistics, shipment-arrival and invoice services.
 * Payment is service-posted, not forced into a final state.
 *
 * Local dry-run can be restricted with DEMO_FULFILLMENT_LIMIT=1 while debugging;
 * the normal 230-sale run requires no additional environments.
 */
final class UnifiedSalesFulfillmentSeeder extends DemoSeeder
{
    private const int TargetCount = 230;

    protected function seed(DemoContext $context): void
    {
        $anchor = Carbon::parse(config()->string('demo.unified.anchor', now()->toDateString()))->startOfDay();
        $limit = (int) (getenv('DEMO_FULFILLMENT_LIMIT') ?: self::TargetCount);
        $limit = max(1, min($limit, self::TargetCount));

        $warehouse = Warehouse::query()->where('code', 'DEMO-WH-004')->firstOrFail();
        $customers = CustomerProfile::query()
            ->where('customer_code', 'like', 'DEMO-CUST-%')
            ->where('is_active', true)
            ->orderBy('customer_code')->get();
        if ($customers->count() < 100) {
            throw new LogicException('Fulfilment requires the unified master customer list.');
        }

        $sale = app(SalesOrderService::class);
        $logistics = app(OutboundFulfillmentService::class);
        $dispatch = app(OutboundDispatchService::class);
        $arrival = app(ShipmentArrivalConfirmationService::class);
        $invoices = app(InvoiceService::class);
        $payments = app(PaymentService::class);
        $cash = PaymentMethod::query()->where('name', 'Cash Desk')->firstOrFail();

        foreach (range(1, $limit) as $n) {
            $marker = sprintf('[DEMO-FULFILL] Local clinical supply order %04d', $n);
            $issuedAt = $anchor->copy()->subDays(29 - (int) floor(($n - 1) / 8))
                ->setTime(9 + ($n % 7), 10);
            $order = Order::query()->where('notes', $marker)->first();

            // Eligible batches have certified saleable stock and arrived before
            // this customer's order. Never fabricate an inventory balance.
            $lot = null;
            if (! $order instanceof Order) {
                $stock = DB::table('inventory_lot_balances as b')
                    ->join('inventory_lots as l', 'l.id', '=', 'b.inventory_lot_id')
                    ->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')
                    ->join('inventory_stocks as s', function (JoinClause $join): void {
                        $join->on('s.product_variant_id', '=', 'l.product_variant_id')
                            ->on('s.warehouse_id', '=', 'b.warehouse_id');
                    })
                    ->where('b.warehouse_id', $warehouse->getKey())
                    ->where('b.stock_condition', StockCondition::Saleable->value)
                    ->where('v.sku', 'like', 'DEMO-REAL-%')
                    ->where('v.track_serials', false)
                    ->where('l.created_at', '<', $issuedAt->toDateTimeString())
                    ->where(function (Builder $q) use ($issuedAt): void {
                        $q->whereNull('l.expires_at')->orWhereDate('l.expires_at', '>', $issuedAt->toDateString());
                    })
                    ->whereRaw('(b.on_hand_base_quantity - b.reserved_base_quantity) >= 1')
                    ->where('s.available_quantity', '>=', 1)
                    ->orderBy('l.id')
                    ->offset(($n * 17) % 75)->first();

                if (! $stock) {
                    // Offset may be beyond the eligible list late in the run.
                    $stock = DB::table('inventory_lot_balances as b')
                        ->join('inventory_lots as l', 'l.id', '=', 'b.inventory_lot_id')
                        ->join('product_variants as v', 'v.id', '=', 'l.product_variant_id')
                        ->join('inventory_stocks as s', function (JoinClause $join): void {
                            $join->on('s.product_variant_id', '=', 'l.product_variant_id')
                                ->on('s.warehouse_id', '=', 'b.warehouse_id');
                        })
                        ->where('b.warehouse_id', $warehouse->getKey())
                        ->where('b.stock_condition', StockCondition::Saleable->value)
                        ->where('v.sku', 'like', 'DEMO-REAL-%')
                        ->where('v.track_serials', false)
                        ->where('l.created_at', '<', $issuedAt->toDateTimeString())
                        ->whereRaw('(b.on_hand_base_quantity - b.reserved_base_quantity) >= 1')
                        ->where('s.available_quantity', '>=', 1)
                        ->orderByDesc('b.on_hand_base_quantity')->first();
                }
                if (! $stock) {
                    throw new LogicException("Insufficient real lot stock for sale {$n}; never fabricate it.");
                }

                $lot = DemoContext::intOf($stock->inventory_lot_id);
                $variant = ProductVariant::query()->whereKey($stock->product_variant_id)->firstOrFail();
                $context->at($issuedAt->toDateTimeString());
                $actor = $context->as('sales_manager');
                $customer = $customers[(($n * 11) + 7) % $customers->count()]
                    ?? throw new LogicException('Missing customer for sales fulfillment.');
                $customer = $customers[(($n * 11) + 7) % $customers->count()]
                    ?? throw new LogicException('Missing customer for sales fulfillment.');
                $customer = $customers[(($n * 11) + 7) % $customers->count()]
                    ?? throw new LogicException('Missing customer for sales fulfillment.');
                $order = $sale->createDraft($actor, [
                    'customer_id' => $customer->getKey(),
                    'scheduled_at' => $issuedAt->copy()->addHours(3)->toDateTimeString(),
                    'notes' => $marker,
                ], [[
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => 1,
                    'unit_id' => $variant->unit_id,
                ]]);
            }

            // Resume in-service if a previous run stopped after creating the PO.
            $context->at($issuedAt->toDateTimeString());
            $seller = $context->as('sales_manager');
            if ($order->status === OrderStatus::Draft) {
                $order = $sale->confirm($seller, $order);
            }
            if ($order->status === OrderStatus::Confirmed) {
                $order = $sale->release($seller, $order);
            }

            $delivery = $order->deliveries()->orderBy('id')->first();
            if (! $delivery instanceof InventoryOperation) {
                $line = $order->lines()->firstOrFail();
                if ($lot === null) {
                    $lot = DB::table('inventory_lot_balances as b')
                        ->join('inventory_lots as l', 'l.id', '=', 'b.inventory_lot_id')
                        ->where('b.warehouse_id', $warehouse->getKey())
                        ->where('l.product_variant_id', $line->product_variant_id)
                        ->where('b.stock_condition', StockCondition::Saleable->value)
                        ->whereRaw('(b.on_hand_base_quantity - b.reserved_base_quantity) >= 1')
                        ->orderBy('l.expires_at')
                        ->value('b.inventory_lot_id');
                    if (! is_numeric($lot)) {
                        throw new LogicException("A resumed released order has no available lot: {$marker}");
                    }
                    $lot = (int) $lot;
                }
                $context->at($issuedAt->copy()->addHours(1)->toDateTimeString());
                $operator = $context->as('operations');
                $logistics->plan($operator, $order, [[
                    'warehouse_id' => $warehouse->getKey(),
                    'tracking_number' => sprintf('DEMO-HSHIP-%04d', $n),
                    'attachments' => [],
                    'assignments' => [[
                        'product_variant_id' => $line->product_variant_id,
                        'quantity' => 1,
                        'inventory_lot_id' => $lot,
                        'serialized_inventory_unit_ids' => [],
                    ]],
                ]]);
                $delivery = $order->deliveries()->orderBy('id')->firstOrFail();
            }

            if ($delivery->stage === OperationStage::Draft) {
                $context->at($issuedAt->copy()->addHours(2)->toDateTimeString());
                $operator = $context->as('operations');
                $logistics->prepare($operator, $delivery);
            }

            $shipment = $delivery->shipment()->firstOrFail();
            if ($shipment->status === ShipmentStatus::Planned) {
                $context->at($issuedAt->copy()->addHours(3)->toDateTimeString());
                $operator = $context->as('operations');
                $dispatch->dispatch($operator, $delivery->refresh(), [
                    'tracking_number' => sprintf('DEMO-HSHIP-%04d', $n),
                ]);
            }

            if ($delivery->refresh()->stage !== OperationStage::Done) {
                $context->at($issuedAt->copy()->addHours(5)->toDateTimeString());
                $arrival->confirmByAdmin($delivery->shipment()->firstOrFail(), $context->as('operations'), [],
                    'Delivered to the receiving clinic; synthetic digital acceptance.');
            }

            $invoice = Invoice::query()->where('order_id', $order->getKey())->orderBy('id')->first();
            if (! $invoice instanceof Invoice) {
                $context->at($issuedAt->copy()->addHours(6)->toDateTimeString());
                $invoice = $invoices->createFromDelivery($context->as('billing'), $delivery->refresh());
            }
            if (! $invoice->isIssued()) {
                $context->at($issuedAt->copy()->addHours(7)->toDateTimeString());
                $invoice = $invoices->issue($context->as('billing'), $invoice);
            }
            if ($invoice->sent_at === null) {
                $context->at($issuedAt->copy()->addHours(7)->addMinutes(10)->toDateTimeString());
                $billing = $context->as('billing');
                if (! $invoice->getFirstMedia('invoice-pdf')) {
                    GenerateInvoiceDocument::dispatchSync(DemoContext::keyOf($invoice), DemoContext::keyOf($billing));
                }

                // Honour real communication suppressions. An invoice can be
                // legally issued, posted and paid without emailing a customer
                // who explicitly opted out of mail. Avoid an unsuccessful
                // send job re-queuing on every idempotent seeder rerun.
                $recipientEmail = mb_strtolower(mb_trim((string) $invoice->customer?->email));
                $mailSuppressed = DB::table('communication_suppressions')
                    ->where('channel', 'mail')
                    ->where('address', $recipientEmail)
                    ->exists();
                if (! $mailSuppressed) {
                    $invoice = $invoices->send($billing, $invoice->refresh());
                }
            }

            // Cash desk reflects a real customer payment service. Unpaid and
            // partially paid invoices are preserved for AR-aging training.
            if ($n % 4 !== 0 && ! Payment::query()
                ->where('external_reference', sprintf('DEMO-HSALE-PAY-%04d', $n))->exists()) {
                $context->at($issuedAt->copy()->addDays(2)->setTime(11, 0)->toDateTimeString());
                $billing = $context->as('billing');
                $amount = (float) $invoice->outstandingAmount();
                if ($n % 4 === 1) {
                    $amount = round($amount / 2, 2);
                }

                // Existing customer deposits may have been automatically applied
                // when the invoice was issued. Never create a zero-value payment.
                if ($amount <= 0.0) {
                    continue;
                }

                $draft = $payments->createDraft($billing, [
                    'customer_id' => $order->customer_id,
                    'payment_method_id' => $cash->getKey(),
                    'amount' => number_format($amount, 2, '.', ''),
                    'currency' => 'AED',
                    'payment_date' => now()->toDateString(),
                    'external_reference' => sprintf('DEMO-HSALE-PAY-%04d', $n),
                    'notes' => sprintf('[DEMO-HISTORY] Customer remittance for %s', $order->order_number),
                ]);

                $payments->post($billing, $draft, [[
                    'invoice_id' => DemoContext::keyOf($invoice), 'amount' => $amount,
                ]]);
            }
        }

        $this->note("Posted {$limit} synthetic order-to-delivery-to-invoice journeys with cash/AR settlement splits.");
    }
}
