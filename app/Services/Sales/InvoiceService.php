<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Events\InvoiceIssued;
use App\Jobs\SendInvoiceEmail;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentTerm;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class InvoiceService
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
        private InvoicePostingService $posting,
    ) {}

    public function createFromDelivery(User $actor, InventoryOperation $delivery): Invoice
    {
        Gate::forUser($actor)->authorize('create', Invoice::class);

        return DB::transaction(function () use ($actor, $delivery): Invoice {
            /** @var InventoryOperation $lockedDelivery */
            $lockedDelivery = InventoryOperation::query()
                ->with(['lines.orderLine.productVariant', 'sourceDocument'])
                ->whereKey($delivery->getKey())
                ->lockForUpdate()
                ->sole();

            if ($lockedDelivery->operation_type !== OperationType::Delivery
                || $lockedDelivery->stage !== OperationStage::Done) {
                throw new DomainException('Only a completed delivery can be invoiced.');
            }

            if (Invoice::query()->where('inventory_operation_id', $lockedDelivery->getKey())->exists()) {
                throw new DomainException('This delivery has already been invoiced.');
            }

            $order = $lockedDelivery->sourceDocument;

            if (! $order instanceof Order) {
                throw new DomainException('A sales delivery must reference its originating sales order.');
            }

            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->with(['lines.productVariant', 'paymentTerm'])
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->sole();

            $invoiceDate = CarbonImmutable::today();
            $paymentTerm = $lockedOrder->paymentTerm;
            $dueDate = $paymentTerm instanceof PaymentTerm
                ? $paymentTerm->dueDateFrom($invoiceDate)->toDateString()
                : null;

            $invoice = new Invoice([
                'invoice_number' => $this->numbers->next(
                    Invoice::withTrashed(),
                    'invoice_number',
                    'INV-',
                ),
                'customer_id' => $lockedOrder->customer_id,
                'inventory_operation_id' => $lockedDelivery->getKey(),
                'order_id' => $lockedOrder->getKey(),
                'payment_term_id' => $lockedOrder->payment_term_id,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $dueDate,
                'status' => InvoiceStatus::Draft,
            ]);
            $invoice->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $aggregated = $this->aggregateDeliveredLines($lockedDelivery, $lockedOrder);
            $subtotal = 0.0;
            $taxTotal = 0.0;

            foreach (array_values($aggregated) as $index => $row) {
                $invoice->lines()->create([
                    'product_variant_id' => $row['product_variant_id'],
                    'order_line_id' => $row['order_line_id'],
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'tax_amount' => $row['tax_amount'],
                    'line_total' => $row['line_total'],
                    'sort_order' => $index + 1,
                ]);

                $subtotal += $row['net_amount'];
                $taxTotal += $row['tax_amount'];
            }

            $invoice->forceFill([
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'total_amount' => round($subtotal + $taxTotal, 2),
            ])->save();

            activity()
                ->performedOn($invoice)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.invoice.created_from_delivery');

            return $invoice->refresh()->load(['lines', 'order', 'inventoryOperation']);
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createStandalone(User $actor, array $attributes, array $lines): Invoice
    {
        Gate::forUser($actor)->authorize('create', Invoice::class);

        return DB::transaction(function () use ($actor, $attributes, $lines): Invoice {
            if ($lines === []) {
                throw new DomainException('An invoice requires at least one line.');
            }

            $invoiceDate = CarbonImmutable::parse((string) ($attributes['invoice_date'] ?? now()->toDateString()));
            $term = isset($attributes['payment_term_id'])
                ? PaymentTerm::query()->find((int) $attributes['payment_term_id'])
                : null;

            $invoice = new Invoice([
                'invoice_number' => $this->numbers->next(Invoice::withTrashed(), 'invoice_number', 'INV-'),
                'customer_id' => (int) $attributes['customer_id'],
                'payment_term_id' => $term?->getKey(),
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

            foreach ($lines as $index => $line) {
                $quantity = (float) ($line['quantity'] ?? 0);
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $taxAmount = round((float) ($line['tax_amount'] ?? 0), 2);

                if ($quantity <= 0 || $unitPrice < 0 || $taxAmount < 0) {
                    throw new DomainException('Invoice lines require a positive quantity and non-negative amounts.');
                }

                $net = round($quantity * $unitPrice, 2);
                $lineTotal = round($net + $taxAmount, 2);

                $invoice->lines()->create([
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'order_line_id' => null,
                    'description' => (string) ($line['description'] ?? 'Service'),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'sort_order' => $index + 1,
                ]);

                $subtotal += $net;
                $tax += $taxAmount;
            }

            $invoice->forceFill([
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($tax, 2),
                'total_amount' => round($subtotal + $tax, 2),
            ])->save();

            return $invoice->refresh()->load('lines');
        });
    }

    public function issue(User $actor, Invoice $invoice): Invoice
    {
        Gate::forUser($actor)->authorize('issue', $invoice);

        return DB::transaction(function () use ($actor, $invoice): Invoice {
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

            if ($locked->order instanceof Order
                && $locked->order->status === 'pending_supplier_confirmation') {
                throw new DomainException('The source sales order is waiting for supplier confirmation.');
            }

            $subtotal = round((float) $locked->lines->sum(
                fn (InvoiceLine $line): float => (float) $line->line_total - (float) $line->tax_amount,
            ), 2);
            $tax = round((float) $locked->lines->sum('tax_amount'), 2);
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

            SendInvoiceEmail::dispatch((int) $locked->getKey(), (int) $actor->getKey())->afterCommit();

            activity()->performedOn($locked)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard', 'recipient' => $email])
                ->log('sales.invoice.send_queued');

            return $locked->refresh();
        });
    }

    /**
     * @return array<int, array{order_line_id:int,product_variant_id:int,description:string,quantity:float,unit_price:float,net_amount:float,tax_amount:float,line_total:float}>
     */
    private function aggregateDeliveredLines(InventoryOperation $delivery, Order $order): array
    {
        $rows = [];

        foreach ($delivery->lines as $deliveryLine) {
            if (! $deliveryLine instanceof InventoryOperationLine) {
                continue;
            }

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

            $baseDelivered = (float) ($deliveryLine->base_quantity ?? $deliveryLine->quantity);
            $key = (int) $orderLine->getKey();

            if (! isset($rows[$key])) {
                $variant = $orderLine->productVariant;
                $rows[$key] = [
                    'order_line_id' => $key,
                    'product_variant_id' => (int) $orderLine->product_variant_id,
                    'description' => (string) ($variant?->name ?? $variant?->sku ?? "Order line {$key}"),
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
            ];
        }

        return $result;
    }
}
