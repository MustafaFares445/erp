<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Sales\PriceResolver;
use App\Services\Sales\QuotationService;
use App\Services\Support\TicketIntakeService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;

final readonly class CustomerChannelService
{
    public function __construct(
        private PriceResolver $prices,
        private QuotationService $quotations,
        private AccountsReceivableService $receivables,
        private TicketIntakeService $tickets,
    ) {}

    /** @return list<array<string, mixed>> */
    public function catalog(User $actor): array
    {
        $this->profile($actor);

        return ProductVariant::query()
            ->with('product:id,name')
            ->where('is_active', true)
            ->where('status', 'active')
            ->whereNotNull('base_price')
            ->orderBy('sku')
            ->get()
            ->map(function (ProductVariant $variant) use ($actor): array {
                $resolved = $this->prices->resolve($variant, $actor);

                return [
                    'id' => (int) $variant->getKey(),
                    'sku' => (string) $variant->sku,
                    'name' => $variant->name,
                    'product_name' => $variant->product?->name,
                    'price' => number_format($resolved->amount, 2, '.', ''),
                    'base_price' => number_format($resolved->baseAmount, 2, '.', ''),
                    'minimum_price' => number_format($resolved->minimumPrice, 2, '.', ''),
                    'price_source' => $resolved->source->value,
                    'pricing_tier_id' => $resolved->tierId,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function quotations(User $actor): array
    {
        $profile = $this->profile($actor);

        return Quotation::query()
            ->where('customer_id', $profile->getKey())
            ->with('lines')
            ->latest('id')
            ->get()
            ->map(fn (Quotation $quotation): array => $this->quotationData($quotation))
            ->all();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function requestQuotation(User $actor, array $data): array
    {
        $profile = $this->profile($actor);
        $rawLines = $data['lines'] ?? [];
        $lines = is_array($rawLines) ? array_values(array_filter($rawLines, 'is_array')) : [];

        $quotation = $this->quotations->create([
            'customer_id' => $profile->getKey(),
            'payment_term_id' => $data['payment_term_id'] ?? null,
            'issue_date' => CarbonImmutable::today()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ], $lines);

        activity()
            ->performedOn($quotation)
            ->causedBy($actor)
            ->withProperties(['source_channel' => 'customer_api'])
            ->log('sales.quotation.requested');

        return $this->quotationData($quotation->load('lines'));
    }

    /** @return list<array<string, mixed>> */
    public function orders(User $actor): array
    {
        $profile = $this->profile($actor);

        return Order::query()
            ->where('customer_id', $profile->getKey())
            ->with(['lines', 'deliveries', 'shipments'])
            ->latest('id')
            ->get()
            ->map(fn (Order $order): array => $this->orderData($order))
            ->all();
    }

    /** @return array<string, mixed> */
    public function order(User $actor, int $orderId): array
    {
        return $this->orderData($this->ownedOrder($actor, $orderId)->load(['lines', 'deliveries', 'shipments']));
    }

    /** @return list<array<string, mixed>> */
    public function invoices(User $actor): array
    {
        $profile = $this->profile($actor);

        return Invoice::query()
            ->where('customer_id', $profile->getKey())
            ->with(['lines', 'paymentAllocations'])
            ->latest('id')
            ->get()
            ->map(fn (Invoice $invoice): array => $this->invoiceData($invoice))
            ->all();
    }

    /** @return array<string, mixed> */
    public function invoice(User $actor, int $invoiceId): array
    {
        return $this->invoiceData($this->ownedInvoice($actor, $invoiceId)->load(['lines', 'paymentAllocations']));
    }

    /** @return array{filename:string,content_type:string,content:string} */
    public function document(User $actor, string $type, int $id): array
    {
        $record = match ($type) {
            'quotation' => $this->ownedQuotation($actor, $id)->load('lines'),
            'order' => $this->ownedOrder($actor, $id)->load('lines'),
            'invoice' => $this->ownedInvoice($actor, $id)->load('lines'),
            default => throw new DomainException('Unsupported customer document type.'),
        };

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new LogicException('The document download stream could not be opened.');
        }

        $number = match (true) {
            $record instanceof Quotation => (string) $record->quotation_number,
            $record instanceof Order => (string) $record->order_number,
            $record instanceof Invoice => (string) $record->invoice_number,
            default => (string) $id,
        };

        fputcsv($stream, ['document_type', $type], escape: '\\');
        fputcsv($stream, ['document_number', $number], escape: '\\');
        fputcsv($stream, ['line_id', 'product_variant_id', 'quantity', 'unit_price', 'tax_amount', 'line_total'], escape: '\\');

        /** @var Collection<int, Model> $lines */
        $lines = $record->getRelation('lines');
        foreach ($lines as $line) {
            fputcsv($stream, [
                $line->getKey(),
                $line->getAttribute('product_variant_id'),
                $line->getAttribute('quantity') ?? $line->getAttribute('transaction_quantity'),
                $line->getAttribute('unit_price'),
                $line->getAttribute('tax_amount'),
                $line->getAttribute('line_total'),
            ], escape: '\\');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return [
            'filename' => $type.'-'.$number.'.csv',
            'content_type' => 'text/csv; charset=UTF-8',
            'content' => is_string($content) ? $content : '',
        ];
    }

    /** @return array<string, mixed> */
    public function statement(User $actor, ?string $from, ?string $to): array
    {
        $profile = $this->profile($actor);
        $end = $to !== null ? CarbonImmutable::parse($to) : CarbonImmutable::today();
        $start = $from !== null ? CarbonImmutable::parse($from) : $end->startOfMonth();

        return $this->receivables->statement($profile, $start, $end);
    }

    /** @return list<array<string, mixed>> */
    public function tickets(User $actor): array
    {
        $profile = $this->profile($actor);

        return Ticket::query()
            ->where('customer_id', $profile->getKey())
            ->latest('id')
            ->get()
            ->map(fn (Ticket $ticket): array => $this->ticketData($ticket))
            ->all();
    }

    /** @return array<string, mixed> */
    public function ticket(User $actor, int $ticketId): array
    {
        return $this->ticketData($this->ownedTicket($actor, $ticketId));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createTicket(User $actor, array $data): array
    {
        $profile = $this->profile($actor);
        $continuedFrom = $data['continued_from_ticket_id'] ?? null;

        if (is_numeric($continuedFrom)) {
            $this->ownedTicket($actor, (int) $continuedFrom);
        }

        $ticket = $this->tickets->create([
            'customer_id' => $profile->getKey(),
            'type' => $data['type'],
            'priority' => $data['priority'],
            'title' => $data['title'],
            'description' => $data['description'],
            'continued_from_ticket_id' => is_numeric($continuedFrom) ? (int) $continuedFrom : null,
            'is_chargeable' => false,
        ], $actor);

        activity()
            ->performedOn($ticket)
            ->causedBy($actor)
            ->withProperties(['source_channel' => 'customer_api'])
            ->log('support.ticket.customer_created');

        return $this->ticketData($ticket);
    }

    private function profile(User $actor): CustomerProfile
    {
        $profile = $actor->customerProfile;

        if (! $actor->isCustomer() || ! $profile instanceof CustomerProfile || ! $profile->is_active) {
            throw new DomainException('An active customer profile is required.');
        }

        return $profile;
    }

    private function ownedQuotation(User $actor, int $id): Quotation
    {
        $profile = $this->profile($actor);

        return Quotation::query()->whereKey($id)->where('customer_id', $profile->getKey())->firstOrFail();
    }

    private function ownedOrder(User $actor, int $id): Order
    {
        $profile = $this->profile($actor);

        return Order::query()->whereKey($id)->where('customer_id', $profile->getKey())->firstOrFail();
    }

    private function ownedInvoice(User $actor, int $id): Invoice
    {
        $profile = $this->profile($actor);

        return Invoice::query()->whereKey($id)->where('customer_id', $profile->getKey())->firstOrFail();
    }

    private function ownedTicket(User $actor, int $id): Ticket
    {
        $profile = $this->profile($actor);

        return Ticket::query()->whereKey($id)->where('customer_id', $profile->getKey())->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function quotationData(Quotation $quotation): array
    {
        return [
            'id' => (int) $quotation->getKey(),
            'number' => (string) $quotation->quotation_number,
            'status' => $quotation->status->value,
            'issue_date' => $quotation->issue_date?->toDateString(),
            'expires_at' => $quotation->expires_at?->toDateString(),
            'subtotal' => (string) $quotation->subtotal,
            'tax_total' => (string) $quotation->tax_total,
            'grand_total' => (string) $quotation->grand_total,
            'lines' => $quotation->relationLoaded('lines') ? $quotation->lines->map(fn ($line): array => [
                'id' => (int) $line->getKey(),
                'product_variant_id' => (int) $line->product_variant_id,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'tax_amount' => (string) $line->tax_amount,
                'line_total' => (string) $line->line_total,
            ])->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function orderData(Order $order): array
    {
        return [
            'id' => (int) $order->getKey(),
            'number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'payment_status' => $order->payment_status?->value,
            'subtotal' => $order->subtotal !== null ? (string) $order->subtotal : null,
            'tax_total' => $order->tax_total !== null ? (string) $order->tax_total : null,
            'grand_total' => $order->grand_total !== null ? (string) $order->grand_total : null,
            'deliveries' => $order->relationLoaded('deliveries') ? $order->deliveries->map(fn ($delivery): array => [
                'id' => (int) $delivery->getKey(),
                'number' => (string) $delivery->operation_number,
                'stage' => $delivery->stage->value,
                'scheduled_at' => $delivery->scheduled_at?->toIso8601String(),
                'completed_at' => $delivery->completed_at?->toIso8601String(),
            ])->all() : [],
            'shipments' => $order->relationLoaded('shipments') ? $order->shipments->map(fn ($shipment): array => [
                'id' => (int) $shipment->getKey(),
                'tracking_number' => $shipment->tracking_number,
                'status' => $shipment->status->value,
            ])->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceData(Invoice $invoice): array
    {
        return [
            'id' => (int) $invoice->getKey(),
            'number' => (string) $invoice->invoice_number,
            'status' => $invoice->status->value,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'subtotal' => (string) $invoice->subtotal,
            'tax_total' => (string) $invoice->tax_total,
            'total_amount' => (string) $invoice->total_amount,
            'amount_paid' => (string) $invoice->amount_paid,
            'credited_amount' => (string) $invoice->credited_amount,
        ];
    }

    /** @return array<string, mixed> */
    private function ticketData(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->getKey(),
            'number' => (string) $ticket->ticket_number,
            'type' => $ticket->type->value,
            'priority' => $ticket->priority->value,
            'status' => $ticket->status->value,
            'title' => (string) $ticket->title,
            'description' => (string) $ticket->description,
            'pending_reason' => $ticket->pending_reason,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }
}
