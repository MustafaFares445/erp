<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Sales\OrderCompletionService;
use App\Services\Sales\SalesOrderService;
use App\Services\Shipments\ShipmentArrivalConfirmationService;

/**
 * Sales orders and their physical fulfilment: direct orders, release, planning, reservation,
 * dispatch, arrival confirmation and automatic closure.
 *
 * Orders O01-O03, O05, O08, O11 come from the quotation scenes; everything else is entered
 * directly here. Every delivery is satisfiable from opening stock alone.
 */
final class DemoSalesFulfilmentScenes
{
    /**
     * Directly entered orders. `confirm`/`release` are scene moments (release null = left
     * confirmed, confirm null = left as a draft).
     *
     * @var array<string, array{customer: string, term: string, lines: array<string, int>, create: string, confirm: ?string, release: ?string, note: string, cancel?: string}>
     */
    public const array DirectOrders = [
        'O09' => ['customer' => 'C10', 'term' => 'Net 30', 'create' => '2026-09-09 09:30', 'confirm' => '2026-09-09 10:00', 'release' => '2026-09-09 10:30',
            'lines' => ['P001-1L' => 3, 'P002-1L' => 8], 'note' => 'Monthly resin top-up.'],
        'O12' => ['customer' => 'C07', 'term' => 'Net 30', 'create' => '2026-09-10 11:00', 'confirm' => '2026-09-10 11:30', 'release' => '2026-09-10 12:00',
            'lines' => ['P016-BASIC' => 10, 'P018-UPPER' => 20, 'P012-21MM' => 3], 'note' => 'Lab consumables, standing order.'],
        'O07' => ['customer' => 'C04', 'term' => 'Net 30', 'create' => '2026-09-11 14:00', 'confirm' => '2026-09-11 14:30', 'release' => '2026-09-11 15:00',
            'lines' => ['P017-LONG' => 5, 'P017-STD' => 10, 'P016-PREMIUM' => 8, 'P020-BASIC' => 2], 'note' => 'Implant prosthetics workshop restock.'],
        'O13' => ['customer' => 'C14', 'term' => 'Net 7', 'create' => '2026-09-14 09:00', 'confirm' => '2026-09-14 09:30', 'release' => '2026-09-14 10:00',
            'lines' => ['P009-STD' => 5, 'P006-LIGHT' => 10, 'P004-40X10' => 1], 'note' => 'Urgent clinic order.'],
        'O16' => ['customer' => 'C11', 'term' => 'Net 45', 'create' => '2026-09-15 10:00', 'confirm' => '2026-09-15 10:30', 'release' => '2026-09-15 11:00',
            'lines' => ['P015-20X30' => 4, 'P014-10G' => 2], 'note' => 'Cold-chain biomaterials.'],
        'O04' => ['customer' => 'C12', 'term' => 'Net 15', 'create' => '2026-09-17 10:00', 'confirm' => '2026-09-17 10:30', 'release' => '2026-09-17 11:00',
            'lines' => ['P004-40X12' => 6, 'P004-40X10' => 3], 'note' => 'Surgical implant fixtures for the October list.'],
        'O10' => ['customer' => 'C15', 'term' => 'Net 30', 'create' => '2026-09-22 09:00', 'confirm' => '2026-09-22 09:30', 'release' => '2026-09-22 10:00',
            'lines' => ['P012-25MM' => 8, 'P013-500ML' => 30, 'P007-L' => 9], 'note' => 'Endodontic supplies.'],
        'O15' => ['customer' => 'C08', 'term' => 'Net 15', 'create' => '2026-09-22 13:00', 'confirm' => '2026-09-22 13:30', 'release' => '2026-09-22 14:00',
            'lines' => ['P010-A2' => 5, 'P013-1L' => 10], 'note' => 'Small order, customer has a deposit on account.'],
        'O06' => ['customer' => 'C06', 'term' => 'Net 7', 'create' => '2026-09-24 09:00', 'confirm' => '2026-09-24 09:30', 'release' => '2026-09-24 10:00',
            'lines' => ['P008-135X280' => 30, 'P008-90X230' => 40, 'P013-1L' => 20], 'note' => 'Sterilization supplies for the diagnostics unit.'],
        'O22' => ['customer' => 'C06', 'term' => 'Net 7', 'create' => '2026-09-25 11:00', 'confirm' => '2026-09-25 11:30', 'release' => null,
            'lines' => ['P002-5L' => 1], 'note' => 'Placed by mistake, customer postponed the project.', 'cancel' => '2026-09-28 10:00'],
        'O14' => ['customer' => 'C08', 'term' => 'Net 15', 'create' => '2026-09-29 10:00', 'confirm' => '2026-09-29 10:15', 'release' => '2026-09-29 10:30',
            'lines' => ['P018-LOWER' => 110], 'note' => 'Bulk impression tray order, beyond current stock.'],
        'O17' => ['customer' => 'C10', 'term' => 'Net 30', 'create' => '2026-09-28 10:00', 'confirm' => '2026-09-28 10:30', 'release' => '2026-09-28 11:00',
            'lines' => ['P007-M' => 30, 'P008-90X230' => 20], 'note' => 'Gloves and pouches.'],
        'O18' => ['customer' => 'C13', 'term' => 'Net 30', 'create' => '2026-09-29 14:00', 'confirm' => '2026-09-29 14:30', 'release' => '2026-09-29 15:00',
            'lines' => ['P001-500ML' => 4, 'P012-21MM' => 3, 'P005-45MM' => 10, 'P010-A3' => 3], 'note' => 'Prepaid by card, goods reserved for pickup.'],
        'O19' => ['customer' => 'C14', 'term' => 'Net 7', 'create' => '2026-09-29 15:00', 'confirm' => '2026-09-29 15:30', 'release' => '2026-09-29 16:00',
            'lines' => ['P010-A3' => 2, 'P013-500ML' => 10], 'note' => 'Shade A3 composite is nearly out of stock.'],
        'O21A' => ['customer' => 'C11', 'term' => 'Net 45', 'create' => '2026-09-30 09:30', 'confirm' => '2026-09-30 10:00', 'release' => null,
            'lines' => ['P014-05G' => 3, 'P015-20X30' => 1], 'note' => 'Confirmed, waiting for sales manager release.'],
        'O21B' => ['customer' => 'C05', 'term' => 'Net 15', 'create' => '2026-10-01 09:30', 'confirm' => '2026-10-01 10:00', 'release' => null,
            'lines' => ['P015-15X20' => 3], 'note' => 'Confirmed, waiting for sales manager release.'],
        'O23' => ['customer' => 'C15', 'term' => 'Net 30', 'create' => '2026-10-01 08:30', 'confirm' => '2026-10-01 08:45', 'release' => '2026-10-01 09:00',
            'lines' => ['P005-35MM' => 10, 'P013-500ML' => 5], 'note' => 'Second order of the month.'],
        'O20' => ['customer' => 'C03', 'term' => 'Net 45', 'create' => '2026-10-01 15:00', 'confirm' => null, 'release' => null,
            'lines' => ['P001-1L' => 5], 'note' => 'Draft prepared for the next ordering round.'],
    ];

    /**
     * Delivery flows. `spec` null = the whole order. `arrive`/`dispatch`/`prepare` null = stop
     * at the previous stage. `cancel` cancels the delivery after preparing it. `by` is the
     * arrival confirmation source (admin user or system).
     *
     * @var list<array{order: string, key: string, spec?: array<string, int>, plan: string, prepare?: string, dispatch?: string, arrive?: string, by?: string, cancel?: string, overbook?: bool}>
     */
    public const array Flows = [
        ['order' => 'O01', 'key' => 'O01', 'plan' => '2026-09-09 10:00', 'prepare' => '2026-09-09 14:00', 'dispatch' => '2026-09-10 09:30', 'arrive' => '2026-09-10 17:00'],
        ['order' => 'O09', 'key' => 'O09', 'plan' => '2026-09-10 10:00', 'prepare' => '2026-09-10 15:00', 'dispatch' => '2026-09-11 10:00', 'arrive' => '2026-09-11 17:00'],
        ['order' => 'O03', 'key' => 'O03', 'plan' => '2026-09-11 09:00', 'prepare' => '2026-09-11 11:00', 'dispatch' => '2026-09-11 15:00', 'arrive' => '2026-09-14 10:00'],
        ['order' => 'O12', 'key' => 'O12', 'plan' => '2026-09-11 10:00', 'prepare' => '2026-09-11 15:00', 'dispatch' => '2026-09-14 10:00', 'arrive' => '2026-09-14 18:00'],
        ['order' => 'O02', 'key' => 'O02', 'plan' => '2026-09-14 09:00', 'prepare' => '2026-09-14 10:00', 'dispatch' => '2026-09-14 15:00', 'arrive' => '2026-09-15 11:00', 'by' => 'system'],
        ['order' => 'O13', 'key' => 'O13', 'plan' => '2026-09-15 09:30', 'prepare' => '2026-09-15 14:00', 'dispatch' => '2026-09-16 10:00', 'arrive' => '2026-09-16 16:00'],
        ['order' => 'O07', 'key' => 'O07', 'plan' => '2026-09-15 10:00', 'prepare' => '2026-09-15 14:00', 'dispatch' => '2026-09-16 09:30', 'arrive' => '2026-09-17 12:00'],
        ['order' => 'O16', 'key' => 'O16', 'plan' => '2026-09-16 10:00', 'prepare' => '2026-09-16 14:00', 'dispatch' => '2026-09-17 10:00', 'arrive' => '2026-09-18 11:00'],
        ['order' => 'O05', 'key' => 'O05', 'plan' => '2026-09-18 10:00', 'prepare' => '2026-09-18 15:00', 'dispatch' => '2026-09-21 09:30', 'arrive' => '2026-09-22 10:00'],
        ['order' => 'O04', 'key' => 'O04', 'plan' => '2026-09-21 10:00', 'prepare' => '2026-09-21 15:00', 'dispatch' => '2026-09-22 09:00', 'arrive' => '2026-09-23 10:00'],
        ['order' => 'O08', 'key' => 'O08A', 'spec' => ['P005-45MM' => 12, 'P018-LOWER' => 20, 'P007-S' => 20],
            'plan' => '2026-09-21 11:00', 'prepare' => '2026-09-21 14:00', 'dispatch' => '2026-09-22 10:00', 'arrive' => '2026-09-23 12:00'],
        ['order' => 'O11', 'key' => 'O11', 'plan' => '2026-09-23 10:00', 'prepare' => '2026-09-23 14:00', 'dispatch' => '2026-09-24 09:00', 'arrive' => '2026-09-24 16:00'],
        ['order' => 'O15', 'key' => 'O15', 'plan' => '2026-09-23 11:00', 'prepare' => '2026-09-23 16:00', 'dispatch' => '2026-09-24 11:00', 'arrive' => '2026-09-24 15:00'],
        ['order' => 'O10', 'key' => 'O10', 'plan' => '2026-09-23 15:00', 'prepare' => '2026-09-24 09:00', 'dispatch' => '2026-09-24 14:00', 'arrive' => '2026-09-25 10:00'],
        ['order' => 'O06', 'key' => 'O06', 'plan' => '2026-09-25 09:00', 'prepare' => '2026-09-25 11:00', 'dispatch' => '2026-09-25 15:00', 'arrive' => '2026-09-28 10:00'],
        ['order' => 'O17', 'key' => 'O17', 'plan' => '2026-09-29 10:00', 'prepare' => '2026-09-29 11:00', 'dispatch' => '2026-09-30 10:00', 'arrive' => '2026-10-01 11:00'],
        ['order' => 'O08', 'key' => 'O08B', 'spec' => ['P005-45MM' => 4],
            'plan' => '2026-09-30 10:00', 'prepare' => '2026-09-30 14:00', 'dispatch' => '2026-10-01 09:00'],
        ['order' => 'O18', 'key' => 'O18', 'plan' => '2026-09-30 09:30', 'prepare' => '2026-09-30 11:00', 'overbook' => true],
        ['order' => 'O19', 'key' => 'O19', 'plan' => '2026-09-30 09:00', 'prepare' => '2026-09-30 11:30'],
        ['order' => 'O23', 'key' => 'O23A', 'plan' => '2026-10-01 10:00', 'prepare' => '2026-10-01 11:00', 'cancel' => '2026-10-01 15:00'],
        ['order' => 'O23', 'key' => 'O23B', 'plan' => '2026-10-02 10:00'],
    ];

    /** @var array<string, string> order => automatic closure moment */
    public const array Closures = [
        'O09' => '2026-09-28 10:00',
        'O02' => '2026-09-30 10:00',
        'O07' => '2026-10-02 10:00',
        'O16' => '2026-10-02 12:00',
    ];

    private int $tracking = 0;

    /** @var array<string, array<string, int>> */
    private array $specs = [];

    public function __construct(
        private readonly DemoSalesKit $kit,
        private readonly DemoSalesTimeline $timeline,
    ) {}

    public function register(): void
    {
        foreach (self::DirectOrders as $code => $order) {
            $this->registerDirectOrder($code, $order);
        }

        foreach (self::Flows as $flow) {
            $this->registerFlow($flow);
        }

        foreach (self::Closures as $code => $when) {
            $this->timeline->add($when, "order {$code} closed automatically", function () use ($code): void {
                $this->kit->context->as('sales_manager');
                $this->kit->orders[$code] = app(OrderCompletionService::class)->closeAutomatically($this->kit->orders[$code]->refresh());
            });
        }
    }

    /** @param array{customer: string, term: string, lines: array<string, int>, create: string, confirm: ?string, release: ?string, note: string, cancel?: string} $order */
    private function registerDirectOrder(string $code, array $order): void
    {
        $service = fn (): SalesOrderService => app(SalesOrderService::class);

        $this->timeline->add($order['create'], "order {$code} drafted", function () use ($code, $order, $service): void {
            $this->kit->orders[$code] = $service()->createDraft(
                $this->kit->context->as('sales_manager'),
                [
                    'customer_id' => $this->kit->customer($order['customer'])->getKey(),
                    'payment_term_id' => $this->kit->term($order['term'])->getKey(),
                    'notes' => "[DEMO-SALES] {$code} - {$order['note']}",
                ],
                $this->kit->lines($this->orderSpec($code)),
            );
        });

        if ($order['confirm'] !== null) {
            $this->timeline->add($order['confirm'], "order {$code} confirmed", fn (): Order => $this->kit->orders[$code] = $service()->confirm(
                $this->kit->context->as('sales_manager'),
                $this->kit->orders[$code]->refresh(),
            ));
        }

        if ($order['release'] !== null) {
            $this->timeline->add($order['release'], "order {$code} released", fn (): Order => $this->kit->orders[$code] = $service()->release(
                $this->kit->context->as('sales_manager'),
                $this->kit->orders[$code]->refresh(),
            ));
        }

        if (isset($order['cancel'])) {
            $this->timeline->add($order['cancel'], "order {$code} cancelled", fn (): Order => $this->kit->orders[$code] = $service()->cancel(
                $this->kit->context->as('sales_manager'),
                $this->kit->orders[$code]->refresh(),
                'Customer postponed the project before delivery.',
            ));
        }
    }

    /** @param array{order: string, key: string, spec?: array<string, int>, plan: string, prepare?: string, dispatch?: string, arrive?: string, by?: string, cancel?: string, overbook?: bool} $flow */
    private function registerFlow(array $flow): void
    {
        $key = $flow['key'];

        $this->timeline->add($flow['plan'], "delivery {$key} planned", function () use ($flow, $key): void {
            /** @var Order $order */
            $order = $this->kit->orders[$flow['order']]->refresh();
            $spec = $flow['spec'] ?? $this->orderSpec($flow['order']);
            $this->kit->deliveries[$key] = array_values($this->kit->plan('operations', $order, $spec, null, $flow['overbook'] ?? false)->all());
        });

        if (isset($flow['prepare'])) {
            $this->timeline->add($flow['prepare'], "delivery {$key} prepared", function () use ($key): void {
                foreach ($this->kit->deliveries[$key] as $index => $delivery) {
                    $this->kit->deliveries[$key][$index] = $this->kit->prepare('operations', $delivery);
                }
            });
        }

        if (isset($flow['dispatch'])) {
            $this->timeline->add($flow['dispatch'], "delivery {$key} dispatched", function () use ($key): void {
                foreach ($this->kit->deliveries[$key] as $index => $delivery) {
                    $this->kit->dispatch('operations', $delivery, sprintf('TRK-DEMO-%04d', ++$this->tracking));
                    $this->kit->deliveries[$key][$index] = $delivery->refresh();
                }
            });
        }

        if (isset($flow['arrive'])) {
            $this->timeline->add($flow['arrive'], "delivery {$key} arrival confirmed", function () use ($flow, $key): void {
                foreach ($this->kit->deliveries[$key] as $delivery) {
                    if (($flow['by'] ?? 'admin') === 'system') {
                        app(ShipmentArrivalConfirmationService::class)
                            ->confirmBySystem($delivery->refresh()->shipment()->firstOrFail());

                        continue;
                    }

                    $this->kit->arrive('admin', $delivery, 'Received and checked by the customer on site.');
                }
            });
        }

        if (isset($flow['cancel'])) {
            $this->timeline->add($flow['cancel'], "delivery {$key} cancelled", function () use ($key): void {
                foreach ($this->kit->deliveries[$key] as $index => $delivery) {
                    $this->kit->deliveries[$key][$index] = app(InventoryOperationService::class)->cancel(
                        $delivery->refresh(),
                        $this->kit->context->as('operations'),
                        'Customer asked to move the delivery slot.',
                    );
                }
            });
        }
    }

    /** @return array<string, int> variant key => quantity of the whole order */
    private function orderSpec(string $code): array
    {
        if (isset(self::DirectOrders[$code])) {
            return $this->specs[$code] ??= $this->resolveSpec($code);
        }

        foreach (DemoSalesQuotationScenes::Quotes as $quote) {
            if (($quote['order'] ?? null) === $code) {
                return $quote['lines'];
            }
        }

        throw new \LogicException("No line specification for order [{$code}].");
    }

    /** Variants tried (cheapest first) for the Waiting-for-stock story. */
    private const array ScarceCandidates = ['P010-A3', 'P012-25MM', 'P003-250ML', 'P002-5L', 'P004-40X10'];

    private ?string $scarce = null;

    /**
     * O18 reserves every unit but one of a scarce variant, so the competing O19 delivery
     * (planned first, prepared last) lands in Waiting whatever purchasing left in stock. The
     * variant is the first candidate holding 3-14 available units at the main store; if none
     * qualifies the story degrades to a normal Ready delivery rather than failing.
     *
     * @return array<string, int>
     */
    private function resolveSpec(string $code): array
    {
        $lines = self::DirectOrders[$code]['lines'];

        if (! in_array($code, ['O18', 'O19'], true)) {
            return $lines;
        }

        $this->scarce ??= $this->pickScarceVariant();
        unset($lines['P010-A3']);

        if ($code === 'O19') {
            return [$this->scarce => 2, ...$lines];
        }

        return [...$lines, $this->scarce => max(3, $this->mainAvailable($this->scarce) - 1)];
    }

    private function pickScarceVariant(): string
    {
        foreach (self::ScarceCandidates as $key) {
            $available = $this->mainAvailable($key);

            if ($available >= 3 && $available <= 14) {
                return $key;
            }
        }

        return 'P010-A3';
    }

    private function mainAvailable(string $key): int
    {
        return (int) floor((float) InventoryStock::query()
            ->where('product_variant_id', $this->kit->variant($key)->getKey())
            ->where('warehouse_id', $this->kit->inventory->warehouse('WH-MAIN')->getKey())
            ->sum('available_quantity'));
    }
}
