<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\ResolvedPriceSource;
use App\Events\InvoiceIssued;
use App\Jobs\SendInvoiceEmail;
use App\Models\CustomerProfile;
use App\Models\DepositApplicationIssue;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentTerm;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use App\Services\Payments\CustomerDepositApplicationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

final readonly class InvoiceService
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
        private InvoicePostingService $posting,
        private PriceResolver $priceResolver,
        private PriceProvenanceService $priceProvenance,
        private CustomerDepositApplicationService $depositApplication,
    ) {}

    /**
     * A thin wrapper over {@see self::createFromDeliveries()} so a single-delivery invoice and a
     * consolidated one share exactly one implementation (WP-2.13, GAP-MW-13).
     */
    public function createFromDelivery(User $actor, InventoryOperation $delivery): Invoice
    {
        return $this->createFromDeliveries($actor, new Collection([$delivery]));
    }

    /**
     * Raises one invoice covering several completed deliveries for the same customer (WP-2.13,
     * GAP-MW-13), or a single one when called with a one-element collection. Asserts every
     * delivery is `Done`, shares one customer, and is not already linked to another invoice —
     * standalone invoices included, because {@see InvoiceDeliveryLink}'s unique index is the only
     * control checked, not the deprecated `invoices.inventory_operation_id` column. Lines are
     * aggregated by variant and unit price so the same item sold twice lands on one invoice line,
     * with per-delivery provenance preserved in the line description.
     *
     * @param  Collection<int, InventoryOperation>  $deliveries
     */
    public function createFromDeliveries(User $actor, Collection $deliveries): Invoice
    {
        Gate::forUser($actor)->authorize('create', Invoice::class);

        $deliveryIds = self::deliveryIds($deliveries);

        return DB::transaction(function () use ($actor, $deliveryIds): Invoice {
            $lockedDeliveries = $this->lockAndValidateDeliveries($deliveryIds);

            $orderIds = $lockedDeliveries
                ->map(fn (InventoryOperation $delivery): ?int => $delivery->sourceDocument instanceof Order
                    ? $delivery->sourceDocument->id
                    : null)
                ->filter()
                ->unique()
                ->sort()
                ->values();

            if ($orderIds->isEmpty()) {
                throw new DomainException('A sales delivery must reference its originating sales order.');
            }

            /** @var Collection<int, Order> $lockedOrders */
            $lockedOrders = Order::query()
                ->with(['lines.productVariant', 'paymentTerm'])
                ->whereKey($orderIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Order $order): int => $order->id);

            if ($lockedOrders->count() !== $orderIds->count()) {
                throw new DomainException('A sales delivery must reference its originating sales order.');
            }

            $firstOrder = $lockedOrders->first();

            if (! $firstOrder instanceof Order) {
                throw new DomainException('A sales delivery must reference its originating sales order.');
            }

            $invoiceDate = CarbonImmutable::today();
            $paymentTerm = $firstOrder->paymentTerm;
            $dueDate = $paymentTerm instanceof PaymentTerm
                ? $paymentTerm->dueDateFrom($invoiceDate)->toDateString()
                : null;

            $invoice = new Invoice([
                'invoice_number' => $this->numbers->next(
                    Invoice::withTrashed(),
                    'invoice_number',
                    'INV-',
                ),
                'customer_id' => $firstOrder->customer_id,
                // Kept in sync only for the single-delivery case; a consolidated invoice cannot
                // point at one delivery, so the deprecated column stays null and the join table
                // is the authoritative link (WP-2.13, GAP-MW-13).
                'inventory_operation_id' => $lockedDeliveries->count() === 1
                    ? $lockedDeliveries->sole()->id
                    : null,
                'order_id' => $orderIds->count() === 1 ? $orderIds->first() : null,
                'payment_term_id' => $firstOrder->payment_term_id,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $dueDate,
                'status' => InvoiceStatus::Draft,
            ]);
            $invoice->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $aggregated = $this->aggregateDeliveries($lockedDeliveries, $lockedOrders);
            $subtotal = 0.0;
            $taxTotal = 0.0;

            foreach ($aggregated as $index => $row) {
                $invoice->lines()->create([
                    'product_variant_id' => $row['product_variant_id'],
                    'order_line_id' => $row['order_line_id'],
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'tax_amount' => $row['tax_amount'],
                    'line_total' => $row['line_total'],
                    'sort_order' => $index + 1,
                    ...$row['price_provenance'],
                ]);

                $subtotal += $row['net_amount'];
                $taxTotal += $row['tax_amount'];
            }

            $invoice->forceFill([
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'total_amount' => round($subtotal + $taxTotal, 2),
            ])->save();

            $this->createDeliveryLinks($invoice, $lockedDeliveries);

            activity()
                ->performedOn($invoice)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'delivery_count' => $lockedDeliveries->count(),
                ])
                ->log($lockedDeliveries->count() > 1
                    ? 'sales.invoice.created_from_deliveries'
                    : 'sales.invoice.created_from_delivery');

            return $invoice->refresh()->load(['lines', 'order', 'inventoryOperation', 'deliveryLinks.inventoryOperation']);
        }, attempts: 5);
    }

    /**
     * A standalone invoice may now optionally be attributed to one or more completed deliveries
     * (WP-2.13, GAP-MW-13), so it no longer has to hide its delivery from the "invoiced at most
     * once" control the way it silently did before this change: the join table's unique index
     * catches it exactly as it would a consolidated invoice.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, InventoryOperation>|null  $deliveries
     */
    public function createStandalone(User $actor, array $attributes, array $lines, ?Collection $deliveries = null): Invoice
    {
        Gate::forUser($actor)->authorize('create', Invoice::class);

        return DB::transaction(function () use ($actor, $attributes, $lines, $deliveries): Invoice {
            if ($lines === []) {
                throw new DomainException('An invoice requires at least one line.');
            }

            $invoiceDate = CarbonImmutable::parse(self::textValue(
                $attributes['invoice_date'] ?? now()->toDateString(),
            ));
            $term = isset($attributes['payment_term_id'])
                ? PaymentTerm::query()->find(self::integerValue($attributes['payment_term_id']))
                : null;
            $customer = CustomerProfile::query()
                ->with('user')
                ->find(self::integerValue($attributes['customer_id'] ?? null));

            if (! $customer instanceof CustomerProfile) {
                throw new DomainException('A standalone invoice requires a valid customer.');
            }

            $invoice = new Invoice([
                'invoice_number' => $this->numbers->next(Invoice::withTrashed(), 'invoice_number', 'INV-'),
                'customer_id' => $customer->id,
                'payment_term_id' => $term?->id,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $attributes['due_date']
                    ?? ($term instanceof PaymentTerm ? $term->dueDateFrom($invoiceDate)->toDateString() : null),
                'description' => $attributes['description'] ?? null,
                'status' => InvoiceStatus::Draft,
            ]);
            $invoice->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $subtotal = 0.0;
            $tax = 0.0;
            $customerUser = $customer->user instanceof User ? $customer->user : null;

            foreach ($lines as $index => $line) {
                $quantity = self::decimalValue($line['quantity'] ?? 0);
                $taxAmount = round(self::decimalValue($line['tax_amount'] ?? 0), 2);
                $variant = null;
                $priceEvidence = [];

                if ($quantity <= 0 || $taxAmount < 0) {
                    throw new DomainException('Invoice lines require a positive quantity and non-negative amounts.');
                }

                if (isset($line['product_variant_id'])) {
                    $variant = ProductVariant::query()->find(self::integerValue($line['product_variant_id']));
                    if (! $variant instanceof ProductVariant) {
                        throw new DomainException('A standalone invoice product line requires a valid product variant.');
                    }

                    if (array_key_exists('unit_price', $line) && $line['unit_price'] !== null) {
                        if (! is_numeric($line['unit_price']) || (float) $line['unit_price'] < 0) {
                            throw new DomainException('Invoice line unit price must be non-negative.');
                        }
                        $unitPrice = (float) $line['unit_price'];
                        $priceEvidence = $this->priceProvenance->forManualPrice(
                            variant: $variant,
                            customer: $customerUser,
                            unitPrice: $unitPrice,
                            floorOverrideId: isset($line['price_floor_override_id'])
                                ? self::integerValue($line['price_floor_override_id'])
                                : null,
                        );
                    } else {
                        $resolved = $this->priceResolver->resolve($variant, $customerUser);
                        $unitPrice = $resolved->amount;
                        $priceEvidence = $this->priceProvenance->fromResolved($resolved);
                    }
                } else {
                    if (! array_key_exists('unit_price', $line) || ! is_numeric($line['unit_price'])) {
                        throw new DomainException('A standalone service line requires an explicit unit price.');
                    }
                    $unitPrice = (float) $line['unit_price'];
                    if ($unitPrice < 0) {
                        throw new DomainException('Invoice line unit price must be non-negative.');
                    }
                }

                $net = round($quantity * $unitPrice, 2);
                $lineTotal = round($net + $taxAmount, 2);
                $description = self::textValue(
                    $line['description']
                        ?? ($variant instanceof ProductVariant
                            ? ($variant->name !== '' ? $variant->name : $variant->sku)
                            : 'Service'),
                );

                $invoice->lines()->create([
                    'product_variant_id' => $variant?->id,
                    'order_line_id' => null,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'sort_order' => $index + 1,
                    ...$priceEvidence,
                ]);

                $subtotal += $net;
                $tax += $taxAmount;
            }

            $invoice->forceFill([
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($tax, 2),
                'total_amount' => round($subtotal + $tax, 2),
            ])->save();

            if ($deliveries instanceof Collection && $deliveries->isNotEmpty()) {
                $deliveryIds = self::deliveryIds($deliveries);

                $lockedDeliveries = $this->lockAndValidateDeliveries($deliveryIds, $customer->id);

                if ($lockedDeliveries->count() === 1) {
                    $invoice->forceFill([
                        'inventory_operation_id' => $lockedDeliveries->sole()->id,
                    ])->save();
                }

                $this->createDeliveryLinks($invoice, $lockedDeliveries);
            }

            return $invoice->refresh()->load(['lines', 'deliveryLinks.inventoryOperation']);
        });
    }

    public function issue(User $actor, Invoice $invoice): Invoice
    {
        Gate::forUser($actor)->authorize('issue', $invoice);

        $issued = DB::transaction(function () use ($actor, $invoice): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()
                ->with(['lines', 'order'])
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->sole();

            $locked->assertCanTransitionTo(InvoiceStatus::Issued);

            if ($locked->lines->isEmpty()) {
                throw new DomainException('An invoice requires at least one line before issue.');
            }

            $subtotal = round((float) $locked->lines->sum(
                fn (InvoiceLine $line): float => (float) $line->line_total - (float) $line->tax_amount,
            ), 2);
            $tax = round((float) $locked->lines->sum(
                static fn (InvoiceLine $line): float => (float) $line->tax_amount,
            ), 2);
            $total = round($subtotal + $tax, 2);

            if ($total <= 0.0) {
                throw new DomainException('An issued invoice must have a positive total.');
            }

            $locked->forceFill([
                'subtotal' => $subtotal,
                'tax_total' => $tax,
                'total_amount' => $total,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->posting->post($actor, $locked);

            $locked->forceFill([
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['status' => InvoiceStatus::Issued->value]])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.invoice.issued');

            DB::afterCommit(static fn () => InvoiceIssued::dispatch(
                $locked->refresh()->load('customer.user'),
            ));

            return $locked->refresh();
        }, attempts: 5);

        $this->applyEligibleDepositsSafely($issued);

        return $issued;
    }

    /**
     * Deliberately runs after the issuance transaction has committed, and
     * never rethrows: a deposit-application failure must never undo a
     * successfully issued invoice. The failure is recorded instead of
     * swallowed, so it surfaces on the dashboard and can be retried safely
     * (Customer App V1 plan §15).
     */
    private function applyEligibleDepositsSafely(Invoice $issued): void
    {
        if (! SalesSetting::current()->auto_apply_customer_deposits) {
            return;
        }

        try {
            $this->depositApplication->applyEligibleDeposits($issued);
        } catch (Throwable $throwable) {
            report($throwable);

            DepositApplicationIssue::query()->updateOrCreate(
                ['invoice_id' => $issued->getKey(), 'resolved_at' => null],
                ['error_message' => $throwable->getMessage(), 'occurred_at' => now()],
            );
        }
    }

    public function send(User $actor, Invoice $invoice): Invoice
    {
        Gate::forUser($actor)->authorize('send', $invoice);

        return DB::transaction(function () use ($actor, $invoice): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()
                ->with('customer')
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->sole();

            if (! in_array($locked->status, [InvoiceStatus::Issued, InvoiceStatus::Sent], true)) {
                throw new DomainException('Only an issued or sent invoice can be emailed.');
            }

            if (! $locked->getFirstMedia('invoice-pdf')) {
                throw new DomainException('Generate the invoice PDF before sending it.');
            }

            $email = $locked->customer?->email;
            if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('The invoice customer needs a valid email address before sending.');
            }

            SendInvoiceEmail::dispatch($locked->id, $actor->id)->afterCommit();

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'recipient' => $email])
                ->log('sales.invoice.send_queued');

            return $locked->refresh();
        });
    }

    /**
     * Locks the given deliveries — and only those deliveries — in ascending id order, so two
     * concurrent consolidations racing over an overlapping set of deliveries serialize on the
     * same row-lock order (WP-2.13, GAP-MW-13). Validates each is a completed delivery, none is
     * already linked to any invoice, and all share one customer.
     *
     * @param  list<int>  $deliveryIds
     * @return Collection<int, InventoryOperation>
     */
    private function lockAndValidateDeliveries(array $deliveryIds, ?int $expectedCustomerId = null): Collection
    {
        if ($deliveryIds === []) {
            throw new DomainException('Consolidated invoicing requires at least one delivery.');
        }

        $uniqueIds = array_values(array_unique($deliveryIds));
        sort($uniqueIds);

        /** @var Collection<int, InventoryOperation> $deliveries */
        $deliveries = InventoryOperation::query()
            ->with(['lines.orderLine.productVariant', 'sourceDocument'])
            ->whereKey($uniqueIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($deliveries->count() !== count($uniqueIds)) {
            throw new DomainException('One or more selected deliveries could not be found.');
        }

        foreach ($deliveries as $delivery) {
            if ($delivery->operation_type !== OperationType::Delivery
                || $delivery->stage !== OperationStage::Done) {
                throw new DomainException('Only a completed delivery can be invoiced.');
            }
        }

        // The unique index on invoice_delivery_links.inventory_operation_id is the control that a
        // delivery is invoiced at most once, across every invoice including standalone ones. This
        // pre-check is the friendly path; createDeliveryLinks() below catches the same violation
        // when a competing writer wins the race between this check and the insert.
        if (InvoiceDeliveryLink::query()->whereIn('inventory_operation_id', $uniqueIds)->exists()) {
            throw new DomainException('This delivery has already been invoiced.');
        }

        $customerIds = $deliveries
            ->map(fn (InventoryOperation $delivery): ?int => $delivery->sourceDocument instanceof Order
                ? $delivery->sourceDocument->customer_id
                : $delivery->customer_id)
            ->unique();

        if ($customerIds->count() !== 1 || $customerIds->first() === null) {
            throw new DomainException('Consolidated invoicing requires every delivery to share one customer.');
        }

        if ($expectedCustomerId !== null && (int) $customerIds->first() !== $expectedCustomerId) {
            throw new DomainException('The selected deliveries must belong to the invoice customer.');
        }

        return $deliveries;
    }

    /**
     * @param  Collection<int, InventoryOperation>  $deliveries
     */
    private function createDeliveryLinks(Invoice $invoice, Collection $deliveries): void
    {
        foreach ($deliveries as $delivery) {
            try {
                InvoiceDeliveryLink::query()->create([
                    'invoice_id' => $invoice->id,
                    'inventory_operation_id' => $delivery->id,
                ]);
            } catch (QueryException $exception) {
                if ($this->isDeliveryAlreadyLinkedViolation($exception)) {
                    throw new DomainException('This delivery has already been invoiced.', 0, $exception);
                }

                throw $exception;
            }
        }
    }

    private function isDeliveryAlreadyLinkedViolation(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'invoice_delivery_links_inventory_operation_id_unique')
            || (str_contains($message, 'unique') && str_contains($message, 'inventory_operation_id'));
    }

    private static function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new DomainException('Expected an integer invoice value.');
    }

    /**
     * @param  Collection<int, InventoryOperation>  $deliveries
     * @return list<int>
     */
    private static function deliveryIds(Collection $deliveries): array
    {
        /** @var list<int> $ids */
        $ids = [];

        foreach ($deliveries as $delivery) {
            $ids[] = $delivery->id;
        }

        return $ids;
    }

    private static function decimalValue(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new DomainException('Expected a numeric invoice value.');
    }

    private static function textValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new DomainException('Expected a scalar invoice text value.');
    }

    /**
     * Aggregates lines across every delivery being consolidated, grouped by variant and unit
     * price (WP-2.13, GAP-MW-13) — so the same item sold at the same price, even across different
     * source orders, lands on one invoice line instead of several. The price-provenance columns
     * can only snapshot one delivery's pricing evidence, so each contributing delivery's quantity
     * is instead recorded in the line description once more than one delivery is involved; a
     * single-delivery invoice keeps its original, unadorned description.
     *
     * @param  Collection<int, InventoryOperation>  $deliveries
     * @param  Collection<int, Order>  $ordersById
     * @return list<array{
     *     order_line_id:int,
     *     product_variant_id:int,
     *     description:string,
     *     quantity:float,
     *     unit_price:float,
     *     net_amount:float,
     *     tax_amount:float,
     *     line_total:float,
     *     price_provenance:array{
     *         resolved_price_source:ResolvedPriceSource|null,
     *         resolved_price_tier_id:int|null,
     *         price_floor_override_id:int|null,
     *         list_price_minor:int|null,
     *         floor_price_minor:int|null
     *     }
     * }>
     */
    private function aggregateDeliveries(Collection $deliveries, Collection $ordersById): array
    {
        $multiDelivery = $deliveries->count() > 1;

        /** @var array<string, array{order_line_id:int, product_variant_id:int, base_description:string, unit_price:float, quantity:float, net_amount:float, tax_amount:float, price_provenance:array{resolved_price_source:ResolvedPriceSource|null, resolved_price_tier_id:int|null, price_floor_override_id:int|null, list_price_minor:int|null, floor_price_minor:int|null}, contributions:list<string>}> $buckets */
        $buckets = [];

        foreach ($deliveries as $delivery) {
            $order = $delivery->sourceDocument instanceof Order
                ? $ordersById->get($delivery->sourceDocument->id)
                : null;

            if (! $order instanceof Order) {
                throw new DomainException('A sales delivery must reference its originating sales order.');
            }

            foreach ($this->aggregateDeliveredLines($delivery, $order) as $row) {
                $key = $row['product_variant_id'].'|'.number_format($row['unit_price'], 4, '.', '');

                if (! isset($buckets[$key])) {
                    $buckets[$key] = [
                        'order_line_id' => $row['order_line_id'],
                        'product_variant_id' => $row['product_variant_id'],
                        'base_description' => $row['description'],
                        'unit_price' => $row['unit_price'],
                        'quantity' => 0.0,
                        'net_amount' => 0.0,
                        'tax_amount' => 0.0,
                        'price_provenance' => $row['price_provenance'],
                        'contributions' => [],
                    ];
                }

                $buckets[$key]['quantity'] += $row['quantity'];
                $buckets[$key]['net_amount'] += $row['net_amount'];
                $buckets[$key]['tax_amount'] += $row['tax_amount'];
                $buckets[$key]['contributions'][] = sprintf(
                    '%s x%s',
                    $delivery->operation_number ?? ('delivery #'.$delivery->id),
                    mb_rtrim(mb_rtrim(number_format($row['quantity'], 6, '.', ''), '0'), '.'),
                );
            }
        }

        $result = [];

        foreach (array_values($buckets) as $bucket) {
            $net = round($bucket['net_amount'], 2);
            $tax = round($bucket['tax_amount'], 2);

            $result[] = [
                'order_line_id' => $bucket['order_line_id'],
                'product_variant_id' => $bucket['product_variant_id'],
                'description' => $multiDelivery
                    ? sprintf('%s (%s)', $bucket['base_description'], implode('; ', $bucket['contributions']))
                    : $bucket['base_description'],
                'quantity' => round($bucket['quantity'], 6),
                'unit_price' => $bucket['unit_price'],
                'net_amount' => $net,
                'tax_amount' => $tax,
                'line_total' => round($net + $tax, 2),
                'price_provenance' => $bucket['price_provenance'],
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{
     *     order_line_id:int,
     *     product_variant_id:int,
     *     description:string,
     *     quantity:float,
     *     unit_price:float,
     *     net_amount:float,
     *     tax_amount:float,
     *     line_total:float,
     *     price_provenance:array{
     *         resolved_price_source:ResolvedPriceSource|null,
     *         resolved_price_tier_id:int|null,
     *         price_floor_override_id:int|null,
     *         list_price_minor:int|null,
     *         floor_price_minor:int|null
     *     }
     * }>
     */
    private function aggregateDeliveredLines(InventoryOperation $delivery, Order $order): array
    {
        $rows = [];

        foreach ($delivery->lines as $deliveryLine) {
            $orderLine = $deliveryLine->orderLine;

            if (! $orderLine instanceof OrderLine) {
                $candidates = $order->lines
                    ->where('product_variant_id', $deliveryLine->product_variant_id)
                    ->values();

                if ($candidates->count() !== 1) {
                    throw new DomainException(
                        'A delivery line without order-line provenance cannot be priced unambiguously.',
                    );
                }

                $orderLine = $candidates->first();
            }

            if (! $orderLine instanceof OrderLine || $orderLine->unit_price === null) {
                throw new DomainException('Every delivered order line requires a commercial unit price before invoicing.');
            }

            $variant = $orderLine->productVariant;

            if (! $variant instanceof ProductVariant) {
                throw new DomainException('Every delivered order line requires a product variant.');
            }

            $baseDelivered = (float) ($deliveryLine->base_quantity ?? $deliveryLine->quantity);
            $key = $orderLine->id;

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'order_line_id' => $key,
                    'product_variant_id' => (int) $orderLine->product_variant_id,
                    'description' => $variant->name,
                    'base_delivered' => 0.0,
                    'order_line' => $orderLine,
                ];
            }

            $rows[$key]['base_delivered'] += $baseDelivered;
        }

        $result = [];

        foreach ($rows as $key => $row) {
            $orderLine = $row['order_line'];
            $factor = max(0.000001, (float) ($orderLine->conversion_factor_snapshot ?? 1));
            $orderedBase = max(0.000001, (float) ($orderLine->base_quantity ?? $orderLine->quantity));
            $quantity = round(((float) $row['base_delivered']) / $factor, 6);
            $ratio = min(1.0, ((float) $row['base_delivered']) / $orderedBase);
            $unitPrice = (float) $orderLine->unit_price;
            $net = round($quantity * $unitPrice, 2);
            $tax = round((float) $orderLine->tax_amount * $ratio, 2);

            $result[$key] = [
                'order_line_id' => $key,
                'product_variant_id' => (int) $row['product_variant_id'],
                'description' => (string) $row['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'net_amount' => $net,
                'tax_amount' => $tax,
                'line_total' => round($net + $tax, 2),
                'price_provenance' => $orderLine->priceProvenanceAttributes(),
            ];
        }

        return $result;
    }
}
