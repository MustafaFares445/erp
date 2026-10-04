<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Quotation;
use App\Services\Settings\CurrencyCatalogService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Read-only aggregation layer behind the rebuilt Sales Dashboard.
 *
 * The canonical "Sales" figure used everywhere here (KPIs, trend, funnel
 * money, top products/customers/salespeople) is the confirmed order value:
 * `SUM(orders.grand_total)` for orders whose status is `Confirmed`,
 * `Released`, or `Closed` (i.e. commercially confirmed — excludes `Draft`
 * and `Cancelled`), dated by `orders.confirmed_at`. This is deliberately
 * order value, not invoiced value or collected payments — those are
 * Accounting/AR figures and are only surfaced here as the "delivered, not
 * invoiced" attention item, which is a genuine Sales workflow bottleneck.
 *
 * Caveat: `orders.grand_total`/`confirmed_at` were added by
 * `2026_08_23_170000_add_sales_pricing_to_orders_table.php` with no
 * backfill, so orders confirmed before that date may read as 0 here. This
 * is a pre-existing data gap, not something this dashboard corrects.
 *
 * "Salesperson" is `Quotation.employee_id` (the explicit sales-ownership
 * column) — never `Order.responsible_id`, which is a delivery-wizard
 * fulfillment-responsibility column, not a sales-ownership one. An order is
 * attributed to a salesperson only by tracing `Order.quotation_id ->
 * Quotation.employee_id`; direct orders with no quotation have none.
 */
final class SalesDashboardMetricsService
{
    /** @var array<string, array{count: int, value: float}> */
    private array $confirmedAggregateCache = [];

    /** @var array<string, array{numerator: int, denominator: int, percent: float|null}> */
    private array $conversionCache = [];

    /** @var array<string, array{labels: list<string>, values: list<float>, counts: list<int>}> */
    private array $trendSeriesCache = [];

    public function __construct(
        private readonly SalesReportService $salesReports,
        private readonly CurrencyCatalogService $currency,
    ) {}

    /**
     * @return array{
     *     currency: string, value: float, value_previous: float, value_change_percent: float|null,
     *     count: int, count_previous: int, count_change_percent: float|null,
     *     average_order_value: float|null, conversion_percent: float|null,
     *     conversion_numerator: int, conversion_denominator: int,
     * }
     */
    public function kpis(SalesDashboardFilters $filters): array
    {
        $current = $this->confirmedOrderAggregate($filters, $filters->from, $filters->to);
        $previous = $this->confirmedOrderAggregate($filters, $filters->previousFrom, $filters->previousTo);
        $conversion = $this->conversion($filters, $filters->from, $filters->to);

        return [
            'currency' => $this->currency->defaultCode(),
            'value' => $current['value'],
            'value_previous' => $previous['value'],
            'value_change_percent' => self::percentChange($previous['value'], $current['value']),
            'count' => $current['count'],
            'count_previous' => $previous['count'],
            'count_change_percent' => self::percentChange((float) $previous['count'], (float) $current['count']),
            'average_order_value' => $current['count'] > 0 ? $current['value'] / $current['count'] : null,
            'conversion_percent' => $conversion['percent'],
            'conversion_numerator' => $conversion['numerator'],
            'conversion_denominator' => $conversion['denominator'],
        ];
    }

    /** @return array{granularity: string, currency: string, labels: list<string>, current: list<float>, previous: list<float>, current_counts: list<int>} */
    public function salesTrend(SalesDashboardFilters $filters): array
    {
        $current = $this->trendSeries($filters, previous: false);
        $previous = $this->trendSeries($filters, previous: true);

        return [
            'granularity' => $filters->granularity,
            'currency' => $this->currency->defaultCode(),
            'labels' => $current['labels'],
            'current' => $current['values'],
            'previous' => $previous['values'],
            'current_counts' => $current['counts'],
        ];
    }

    /**
     * @return array{
     *     currency: string,
     *     stages: list<array{key: string, label: string, count: int, value: float, conversion_percent: float|null}>,
     * }
     */
    public function salesFunnel(SalesDashboardFilters $filters): array
    {
        /** @var Collection<int, Quotation> $quotations */
        $quotations = $this->applyQuotationFilters(
            Quotation::query()
                ->whereDate('issue_date', '>=', $filters->from->toDateString())
                ->whereDate('issue_date', '<=', $filters->to->toDateString()),
            $filters,
        )->with(['convertedOrder.deliveries', 'convertedOrder.invoices'])->get();

        $acceptedOrBeyond = $quotations->filter(
            fn (Quotation $quotation): bool => in_array($quotation->status, [QuotationStatus::Accepted, QuotationStatus::ConvertedToDelivery], true),
        );
        $ordered = $quotations->filter(fn (Quotation $quotation): bool => $quotation->convertedOrder instanceof Order);
        $delivered = $ordered->filter(function (Quotation $quotation): bool {
            $order = $quotation->convertedOrder;

            return $order instanceof Order && $order->deliveries->contains(
                fn (InventoryOperation $delivery): bool => $delivery->stage === OperationStage::Done,
            );
        });
        $invoiced = $ordered->filter(function (Quotation $quotation): bool {
            $order = $quotation->convertedOrder;

            return $order instanceof Order && $order->invoices->contains(
                fn (Invoice $invoice): bool => in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Sent], true),
            );
        });

        $stages = [
            ['key' => 'quotations', 'label' => __('dashboards.sales.funnel.quotations'), 'count' => $quotations->count(), 'value' => self::sumDecimal($quotations, 'grand_total'), 'conversion_percent' => null],
            ['key' => 'accepted', 'label' => __('dashboards.sales.funnel.accepted'), 'count' => $acceptedOrBeyond->count(), 'value' => self::sumDecimal($acceptedOrBeyond, 'grand_total'), 'conversion_percent' => null],
            ['key' => 'orders', 'label' => __('dashboards.sales.funnel.orders'), 'count' => $ordered->count(), 'value' => self::sumConvertedOrderValue($ordered), 'conversion_percent' => null],
            ['key' => 'delivered', 'label' => __('dashboards.sales.funnel.delivered'), 'count' => $delivered->count(), 'value' => self::sumConvertedOrderValue($delivered), 'conversion_percent' => null],
            ['key' => 'invoiced', 'label' => __('dashboards.sales.funnel.invoiced'), 'count' => $invoiced->count(), 'value' => self::sumConvertedOrderValue($invoiced), 'conversion_percent' => null],
        ];

        $previousCount = null;
        foreach ($stages as &$stage) {
            $stage['conversion_percent'] = $previousCount !== null && $previousCount > 0
                ? round($stage['count'] / $previousCount * 100, 1)
                : null;
            $previousCount = $stage['count'];
        }
        unset($stage);

        return ['currency' => $this->currency->defaultCode(), 'stages' => $stages];
    }

    /**
     * @return array{
     *     currency: string,
     *     open: array{count: int, value: float}, awaiting_decision: array{count: int, value: float},
     *     accepted: array{count: int, value: float}, rejected_or_expired: array{count: int, value: float},
     *     conversion_percent: float|null, median_days_to_decision: float,
     * }
     */
    public function quotationPerformance(SalesDashboardFilters $filters): array
    {
        $baseQuery = fn (): Builder => $this->applyQuotationFilters(
            Quotation::query()
                ->whereDate('issue_date', '>=', $filters->from->toDateString())
                ->whereDate('issue_date', '<=', $filters->to->toDateString()),
            $filters,
        );

        $open = self::countAndValue(fn (): Builder => (clone $baseQuery())->open());
        $awaiting = self::countAndValue(fn (): Builder => (clone $baseQuery())->awaitingDecision());
        $accepted = self::countAndValue(fn (): Builder => (clone $baseQuery())->where('status', QuotationStatus::Accepted->value));
        $rejectedOrExpired = self::countAndValue(
            fn (): Builder => (clone $baseQuery())->whereIn('status', [QuotationStatus::Rejected->value, QuotationStatus::Expired->value]),
        );

        $conversion = $this->conversion($filters, $filters->from, $filters->to);
        $velocity = $this->salesReports->conversionVelocity($filters->from, $filters->to);
        $medianDays = $velocity['median_days_sent_to_decided'] ?? 0.0;

        return [
            'currency' => $this->currency->defaultCode(),
            'open' => $open,
            'awaiting_decision' => $awaiting,
            'accepted' => $accepted,
            'rejected_or_expired' => $rejectedOrExpired,
            'conversion_percent' => $conversion['percent'],
            'median_days_to_decision' => is_numeric($medianDays) ? (float) $medianDays : 0.0,
        ];
    }

    /** @return list<array{priority: int, key: string, count: int, label: string, detail: string|null, color: string, url: string}> */
    public function attentionItems(SalesDashboardFilters $filters): array
    {
        $items = [];

        /** @var Collection<int, Order> $blocked */
        $blocked = $this->applyOrderFilters(Order::query()->blocked(), $filters)->get(['id', 'grand_total']);
        if ($blocked->isNotEmpty()) {
            $items[] = [
                'priority' => 10,
                'key' => 'blocked',
                'count' => $blocked->count(),
                'label' => (string) __('dashboards.sales.attention.blocked'),
                'detail' => null,
                'color' => 'danger',
                'url' => SalesDashboardLinks::orders('requires_attention', $filters->customerId),
            ];
        }

        /** @var Collection<int, Quotation> $acceptedNotConverted */
        $acceptedNotConverted = $this->applyQuotationFilters(Quotation::query()->acceptedNotConverted(), $filters)
            ->get(['id', 'grand_total']);
        if ($acceptedNotConverted->isNotEmpty()) {
            $items[] = [
                'priority' => 20,
                'key' => 'accepted_not_converted',
                'count' => $acceptedNotConverted->count(),
                'label' => (string) __('dashboards.sales.attention.accepted_not_converted'),
                'detail' => (string) __('dashboards.sales.attention.potential_value', ['value' => self::formatMoney(self::sumDecimal($acceptedNotConverted, 'grand_total'), $this->currency->defaultCode())]),
                'color' => 'warning',
                'url' => SalesDashboardLinks::quotations('accepted', $filters->customerId, $filters->employeeId),
            ];
        }

        $deliveredNotInvoicedQuery = InventoryOperation::query()->deliveredNotInvoiced();
        if ($filters->customerId !== null) {
            $deliveredNotInvoicedQuery->where('customer_id', $filters->customerId);
        }
        $deliveredNotInvoiced = $deliveredNotInvoicedQuery->get(['id']);
        if ($deliveredNotInvoiced->isNotEmpty()) {
            $items[] = [
                'priority' => 30,
                'key' => 'delivered_not_invoiced',
                'count' => $deliveredNotInvoiced->count(),
                'label' => (string) __('dashboards.sales.attention.delivered_not_invoiced'),
                'detail' => null,
                'color' => 'warning',
                'url' => SalesDashboardLinks::deliveryNotes('delivered_not_invoiced'),
            ];
        }

        /** @var Collection<int, Order> $awaitingFulfillment */
        $awaitingFulfillment = $this->applyOrderFilters(Order::query()->awaitingFulfillment(), $filters)->get(['id', 'grand_total']);
        if ($awaitingFulfillment->isNotEmpty()) {
            $items[] = [
                'priority' => 40,
                'key' => 'awaiting_fulfillment',
                'count' => $awaitingFulfillment->count(),
                'label' => (string) __('dashboards.sales.attention.awaiting_fulfillment'),
                'detail' => self::formatMoney(self::sumDecimal($awaitingFulfillment, 'grand_total'), $this->currency->defaultCode()),
                'color' => 'warning',
                'url' => SalesDashboardLinks::orders('awaiting_fulfillment', $filters->customerId),
            ];
        }

        /** @var Collection<int, Quotation> $awaitingDecision */
        $awaitingDecision = $this->applyQuotationFilters(Quotation::query()->awaitingDecision(), $filters)->get(['id', 'sent_at']);
        if ($awaitingDecision->isNotEmpty()) {
            $oldestSentAt = $awaitingDecision->min('sent_at');
            $oldestDays = $oldestSentAt instanceof CarbonInterface ? (int) $oldestSentAt->diffInDays(now()) : null;
            $items[] = [
                'priority' => 50,
                'key' => 'awaiting_decision',
                'count' => $awaitingDecision->count(),
                'label' => (string) __('dashboards.sales.attention.awaiting_decision'),
                'detail' => $oldestDays !== null ? (string) __('dashboards.sales.attention.oldest_waiting', ['days' => $oldestDays]) : null,
                'color' => 'info',
                'url' => SalesDashboardLinks::quotations('awaiting_decision', $filters->customerId, $filters->employeeId),
            ];
        }

        /** @var Collection<int, Quotation> $expiringSoon */
        $expiringSoon = $this->applyQuotationFilters(Quotation::query()->expiringSoon(), $filters)->get(['id', 'grand_total']);
        if ($expiringSoon->isNotEmpty()) {
            $items[] = [
                'priority' => 60,
                'key' => 'expiring_soon',
                'count' => $expiringSoon->count(),
                'label' => (string) __('dashboards.sales.attention.expiring_soon'),
                'detail' => self::formatMoney(self::sumDecimal($expiringSoon, 'grand_total'), $this->currency->defaultCode()),
                'color' => 'info',
                'url' => SalesDashboardLinks::quotations('expiring_soon', $filters->customerId, $filters->employeeId),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $items;
    }

    /** @return array<int, array{product_variant_id: int, label: string, value: float, quantity: float}> */
    public function topProducts(SalesDashboardFilters $filters, int $limit = 5): array
    {
        $orderIds = $this->confirmedOrdersQuery($filters, $filters->from, $filters->to)->pluck('id');

        if ($orderIds->isEmpty()) {
            return [];
        }

        /** @var Collection<int, OrderLine> $rows */
        $rows = OrderLine::query()
            ->whereIn('order_id', $orderIds)
            ->selectRaw('product_variant_id, SUM(line_total) as total_value, SUM(quantity) as total_quantity')
            ->groupBy('product_variant_id')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->with('productVariant')
            ->get();

        return $rows->map(function (OrderLine $line): array {
            $totalValue = $line->getAttribute('total_value');
            $totalQuantity = $line->getAttribute('total_quantity');

            return [
                'product_variant_id' => (int) $line->product_variant_id,
                'label' => $line->productVariant->name ?? __('dashboards.fallback.variant', ['id' => $line->product_variant_id]),
                'value' => is_numeric($totalValue) ? (float) $totalValue : 0.0,
                'quantity' => is_numeric($totalQuantity) ? (float) $totalQuantity : 0.0,
            ];
        })->values()->all();
    }

    /** @return array<int, array{customer_id: int, label: string, orders_count: int, value: float, average_value: float, last_order_at: string|null}> */
    public function topCustomers(SalesDashboardFilters $filters, int $limit = 5): array
    {
        /** @var Collection<int, Order> $rows */
        $rows = $this->confirmedOrdersQuery($filters, $filters->from, $filters->to)
            ->selectRaw('customer_id, COUNT(*) as orders_count, SUM(grand_total) as total_value, MAX(confirmed_at) as last_order_at')
            ->groupBy('customer_id')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->with('customer')
            ->get();

        return $rows->map(function (Order $order): array {
            $ordersCountRaw = $order->getAttribute('orders_count');
            $totalValueRaw = $order->getAttribute('total_value');
            $lastOrderAtRaw = $order->getAttribute('last_order_at');
            $ordersCount = is_numeric($ordersCountRaw) ? (int) $ordersCountRaw : 0;
            $totalValue = is_numeric($totalValueRaw) ? (float) $totalValueRaw : 0.0;

            return [
                'customer_id' => (int) $order->customer_id,
                'label' => self::customerLabel($order->customer, (int) $order->customer_id),
                'orders_count' => $ordersCount,
                'value' => $totalValue,
                'average_value' => $ordersCount > 0 ? $totalValue / $ordersCount : 0.0,
                'last_order_at' => is_string($lastOrderAtRaw) ? $lastOrderAtRaw : null,
            ];
        })->values()->all();
    }

    /** @return array<int, array{employee_id: int, label: string, quotations: int, orders: int, conversion_percent: float, value: float}> */
    public function salespersonPerformance(SalesDashboardFilters $filters): array
    {
        /** @var Collection<int, Quotation> $quotations */
        $quotations = $this->applyQuotationFilters(
            Quotation::query()
                ->whereDate('issue_date', '>=', $filters->from->toDateString())
                ->whereDate('issue_date', '<=', $filters->to->toDateString())
                ->whereNotNull('employee_id'),
            $filters,
        )->with(['employee.user', 'convertedOrder'])->get();

        return $quotations->groupBy('employee_id')
            ->map(function (Collection $group, int|string $employeeId): array {
                $quotationCount = $group->count();
                $orders = $group->filter(fn (Quotation $quotation): bool => $quotation->convertedOrder instanceof Order);
                $orderCount = $orders->count();
                $firstQuotation = $group->first();
                $employee = $firstQuotation instanceof Quotation ? $firstQuotation->employee : null;

                return [
                    'employee_id' => (int) $employeeId,
                    'label' => ($employee instanceof EmployeeProfile ? $employee->user?->name : null) ?? __('dashboards.fallback.employee', ['id' => $employeeId]),
                    'quotations' => $quotationCount,
                    'orders' => $orderCount,
                    'conversion_percent' => $quotationCount > 0 ? round($orderCount / $quotationCount * 100, 1) : 0.0,
                    'value' => self::sumConvertedOrderValue($orders),
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    public function hasSalespersonData(): bool
    {
        return Quotation::query()->whereNotNull('employee_id')->exists();
    }

    /** @return array<int, array{timestamp: CarbonInterface, label: string, detail: string|null, url: string}> */
    public function recentActivity(SalesDashboardFilters $filters, int $limit = 10): array
    {
        /** @var Collection<int, array{timestamp: CarbonInterface|null, label: string, detail: string|null, url: string}> $events */
        $events = collect();

        /** @var Collection<int, Quotation> $acceptedQuotations */
        $acceptedQuotations = $this->applyQuotationFilters(
            Quotation::query()->where('status', QuotationStatus::Accepted->value)->whereNotNull('decided_at'),
            $filters,
        )->with('customer')->latest('decided_at')->limit($limit)->get();
        foreach ($acceptedQuotations as $quotation) {
            $events->push([
                'timestamp' => $quotation->decided_at,
                'label' => __('dashboards.sales.activity.quotation_accepted', ['number' => $quotation->quotation_number]),
                'detail' => self::customerLabel($quotation->customer, $quotation->customer_id),
                'url' => SalesDashboardLinks::quotations(null, $filters->customerId, $filters->employeeId),
            ]);
        }

        /** @var Collection<int, Order> $confirmedOrders */
        $confirmedOrders = $this->applyOrderFilters(Order::query()->whereNotNull('confirmed_at'), $filters)
            ->with('customer')->latest('confirmed_at')->limit($limit)->get();
        foreach ($confirmedOrders as $order) {
            $events->push([
                'timestamp' => $order->confirmed_at,
                'label' => __('dashboards.sales.activity.order_confirmed', ['number' => $order->order_number]),
                'detail' => self::customerLabel($order->customer, $order->customer_id),
                'url' => SalesDashboardLinks::orders(null, $filters->customerId),
            ]);
        }

        $deliveriesQuery = InventoryOperation::query()
            ->where('operation_type', OperationType::Delivery->value)
            ->where('stage', OperationStage::Done->value)
            ->whereNotNull('completed_at');
        if ($filters->customerId !== null) {
            $deliveriesQuery->where('customer_id', $filters->customerId);
        }
        /** @var Collection<int, InventoryOperation> $deliveries */
        $deliveries = $deliveriesQuery->with('customer')->latest('completed_at')->limit($limit)->get();
        foreach ($deliveries as $delivery) {
            $events->push([
                'timestamp' => $delivery->completed_at,
                'label' => __('dashboards.sales.activity.delivery_completed', ['number' => $delivery->operation_number]),
                'detail' => self::customerLabel($delivery->customer, $delivery->customer_id),
                'url' => SalesDashboardLinks::deliveryNotes(),
            ]);
        }

        $invoicesQuery = Invoice::query()->whereNotNull('issued_at')->where('status', '!=', InvoiceStatus::Cancelled->value);
        if ($filters->customerId !== null) {
            $invoicesQuery->where('customer_id', $filters->customerId);
        }
        if ($filters->employeeId !== null) {
            $invoicesQuery->whereHas('order.quotation', fn (Builder $quotation): Builder => $quotation->where('employee_id', $filters->employeeId));
        }
        /** @var Collection<int, Invoice> $invoices */
        $invoices = $invoicesQuery->with('customer')->latest('issued_at')->limit($limit)->get();
        foreach ($invoices as $invoice) {
            $events->push([
                'timestamp' => $invoice->issued_at,
                'label' => __('dashboards.sales.activity.invoice_issued', ['number' => $invoice->invoice_number]),
                'detail' => self::formatMoney((float) $invoice->total_amount, $this->currency->defaultCode()),
                'url' => SalesDashboardLinks::invoices(null, $filters->customerId),
            ]);
        }

        return $events
            ->filter(fn (array $event): bool => $event['timestamp'] instanceof CarbonInterface)
            ->sortByDesc(fn (array $event): int => $event['timestamp']->getTimestamp())
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array{count: int, value: float} */
    private function confirmedOrderAggregate(SalesDashboardFilters $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = $this->cacheKey($filters, $from, $to, 'confirmed-aggregate');

        if (isset($this->confirmedAggregateCache[$key])) {
            return $this->confirmedAggregateCache[$key];
        }

        $row = $this->confirmedOrdersQuery($filters, $from, $to)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(grand_total), 0) as aggregate_value')
            ->first();

        $count = $row?->getAttribute('aggregate_count');
        $value = $row?->getAttribute('aggregate_value');

        return $this->confirmedAggregateCache[$key] = [
            'count' => is_numeric($count) ? (int) $count : 0,
            'value' => is_numeric($value) ? (float) $value : 0.0,
        ];
    }

    /** @return Builder<Order> */
    private function confirmedOrdersQuery(SalesDashboardFilters $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->applyOrderFilters(
            Order::query()
                ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Released->value, OrderStatus::Closed->value])
                ->whereBetween('confirmed_at', [$from, $to]),
            $filters,
        );
    }

    /** @return array{numerator: int, denominator: int, percent: float|null} */
    private function conversion(SalesDashboardFilters $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = $this->cacheKey($filters, $from, $to, 'conversion');

        if (isset($this->conversionCache[$key])) {
            return $this->conversionCache[$key];
        }

        $row = $this->applyQuotationFilters(
            Quotation::query()
                ->whereDate('decided_at', '>=', $from->toDateString())
                ->whereDate('decided_at', '<=', $to->toDateString())
                ->whereIn('status', [
                    QuotationStatus::Accepted->value,
                    QuotationStatus::ConvertedToDelivery->value,
                    QuotationStatus::Rejected->value,
                ]),
            $filters,
        )
            ->selectRaw('COUNT(*) as denominator, SUM(CASE WHEN converted_order_id IS NOT NULL THEN 1 ELSE 0 END) as numerator')
            ->first();

        $denominatorRaw = $row?->getAttribute('denominator');
        $numeratorRaw = $row?->getAttribute('numerator');
        $denominator = is_numeric($denominatorRaw) ? (int) $denominatorRaw : 0;
        $numerator = is_numeric($numeratorRaw) ? (int) $numeratorRaw : 0;

        return $this->conversionCache[$key] = [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'percent' => $denominator > 0 ? round($numerator / $denominator * 100, 1) : null,
        ];
    }

    /** @return array{labels: list<string>, values: list<float>, counts: list<int>} */
    private function trendSeries(SalesDashboardFilters $filters, bool $previous): array
    {
        $from = $previous ? $filters->previousFrom : $filters->from;
        $to = $previous ? $filters->previousTo : $filters->to;
        $key = $this->cacheKey($filters, $from, $to, $previous ? 'trend-previous' : 'trend-current');

        if (isset($this->trendSeriesCache[$key])) {
            return $this->trendSeriesCache[$key];
        }

        $orders = $this->confirmedOrdersQuery($filters, $from, $to)->get(['confirmed_at', 'grand_total']);

        return $this->trendSeriesCache[$key] = [
            'labels' => $filters->period->labels($previous),
            'values' => $filters->period->sumSeries(
                $orders->map(static fn (Order $order): array => [$order->confirmed_at, $order->grand_total]),
                $previous,
            ),
            'counts' => $filters->period->countSeries($orders->pluck('confirmed_at'), $previous),
        ];
    }

    /**
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    private function applyQuotationFilters(Builder $query, SalesDashboardFilters $filters): Builder
    {
        return $query
            ->when($filters->customerId, static fn (Builder $q, int $id): Builder => $q->where('customer_id', $id))
            ->when($filters->employeeId, static fn (Builder $q, int $id): Builder => $q->where('employee_id', $id));
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyOrderFilters(Builder $query, SalesDashboardFilters $filters): Builder
    {
        return $query
            ->when($filters->customerId, static fn (Builder $q, int $id): Builder => $q->where('customer_id', $id))
            ->when(
                $filters->employeeId,
                static fn (Builder $q, int $id): Builder => $q->whereHas('quotation', fn (Builder $quotation): Builder => $quotation->where('employee_id', $id)),
            );
    }

    /** @return array{count: int, value: float} */
    private static function countAndValue(Closure $queryFactory): array
    {
        /** @var Builder<Quotation> $query */
        $query = $queryFactory();
        $row = $query
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(grand_total), 0) as aggregate_value')
            ->first();

        $count = $row?->getAttribute('aggregate_count');
        $value = $row?->getAttribute('aggregate_value');

        return [
            'count' => is_numeric($count) ? (int) $count : 0,
            'value' => is_numeric($value) ? (float) $value : 0.0,
        ];
    }

    /**
     * @param  Collection<int, Quotation>  $quotations
     */
    private static function sumConvertedOrderValue(Collection $quotations): float
    {
        return $quotations->sum(function (Quotation $quotation): float {
            $order = $quotation->convertedOrder;

            return $order instanceof Order ? (float) $order->grand_total : 0.0;
        });
    }

    /** @param  iterable<Quotation|Order>  $records */
    private static function sumDecimal(iterable $records, string $attribute): float
    {
        $total = 0.0;
        foreach ($records as $record) {
            $value = $record->getAttribute($attribute);
            $total += is_numeric($value) ? (float) $value : 0.0;
        }

        return $total;
    }

    private static function percentChange(float $previous, float $current): ?float
    {
        if ($previous === 0.0) {
            return $current === 0.0 ? null : 100.0;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    private static function customerLabel(?CustomerProfile $customer, ?int $customerId): string
    {
        if ($customer instanceof CustomerProfile) {
            return $customer->company_name ?: ($customer->customer_code ?: __('dashboards.fallback.customer', ['id' => $customerId]));
        }

        return $customerId !== null ? __('dashboards.fallback.customer', ['id' => $customerId]) : __('dashboards.fallback.unknown_customer');
    }

    private function cacheKey(
        SalesDashboardFilters $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $metric,
    ): string {
        return implode('|', [
            $metric,
            $from->toIso8601String(),
            $to->toIso8601String(),
            (string) ($filters->employeeId ?? 0),
            (string) ($filters->customerId ?? 0),
            $filters->granularity,
        ]);
    }

    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = Number::currency($amount, $currency);

        return $formatted === false ? "{$currency} {$amount}" : $formatted;
    }
}
