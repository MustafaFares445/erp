<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\PurchaseAgreement;
use App\Models\PurchaseRfq;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Purchasing\PurchaseAgreementService;
use App\Services\Purchasing\PurchaseRfqService;

/** Sourcing scenes: framework agreements, requests for quotation and manual replenishment needs. */
final readonly class DemoPurchasingSourcing
{
    public function __construct(private DemoPurchasingToolkit $toolkit, private DemoContext $context) {}

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $lines  [variant key, unit price, minimum order quantity, lead days]
     */
    public function agreement(string $note, int $supplier, string $startsOn, string $endsOn, array $lines): PurchaseAgreement
    {
        $manager = $this->context->actor('purchasing_manager');
        $payload = [];

        foreach ($lines as [$key, $price, $minimum, $lead]) {
            $variant = $this->toolkit->variant($key);
            $payload[] = [
                'product_variant_id' => DemoContext::keyOf($variant),
                'unit_id' => $variant->unit_id,
                'unit_price' => $price,
                'minimum_order_quantity' => $minimum,
                'lead_time_days' => $lead,
            ];
        }

        return app(PurchaseAgreementService::class)->create($manager, [
            'supplier_id' => DemoContext::keyOf($this->toolkit->supplier($supplier)),
            'currency_code' => 'AED',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'notes' => $note,
        ], $payload);
    }

    public function activateAgreement(string $note): void
    {
        app(PurchaseAgreementService::class)->activate($this->context->actor('purchasing_manager'), $this->agreementByNote($note));
    }

    public function expireAgreement(string $note): void
    {
        app(PurchaseAgreementService::class)->expire($this->context->actor('purchasing_manager'), $this->agreementByNote($note));
    }

    public function cancelAgreement(string $note): void
    {
        app(PurchaseAgreementService::class)->cancel($this->context->actor('purchasing_manager'), $this->agreementByNote($note));
    }

    /**
     * @param  list<array{0: string, 1: int}>  $lines  [variant key, quantity]
     * @param  list<int>  $suppliers
     */
    public function rfq(string $note, array $lines, array $suppliers, ?string $neededBy, ?string $closesAt): PurchaseRfq
    {
        $payload = [];

        foreach ($lines as [$key, $quantity]) {
            $variant = $this->toolkit->variant($key);
            $payload[] = ['product_variant_id' => DemoContext::keyOf($variant), 'unit_id' => $variant->unit_id, 'quantity' => (string) $quantity, 'notes' => null];
        }

        return app(PurchaseRfqService::class)->create($this->context->actor('purchasing_officer'), [
            'currency_code' => 'AED',
            'needed_by' => $neededBy,
            'closes_at' => $closesAt,
            'notes' => $note,
        ], $payload, array_map(fn (int $number): int => DemoContext::keyOf($this->toolkit->supplier($number)), $suppliers));
    }

    public function sendRfq(string $note): void
    {
        app(PurchaseRfqService::class)->send($this->context->actor('purchasing_officer'), $this->rfqByNote($note));
    }

    /**
     * @param  list<string>  $unitPrices  one quoted unit price per RFQ line, in line order
     */
    public function quote(string $note, int $supplier, array $unitPrices, int $leadDays): void
    {
        $rfq = $this->rfqByNote($note);
        $row = $rfq->suppliers()->where('supplier_id', DemoContext::keyOf($this->toolkit->supplier($supplier)))->sole();
        $responses = [];

        foreach ($rfq->lines()->orderBy('id')->get()->values() as $index => $line) {
            $responses[] = [
                'rfq_line_id' => DemoContext::keyOf($line),
                'unit_price' => $unitPrices[$index],
                'offered_quantity' => (string) $line->quantity,
                'lead_time_days' => $leadDays,
                'minimum_order_quantity' => null,
                'notes' => null,
            ];
        }

        app(PurchaseRfqService::class)->recordResponse($this->context->actor('purchasing_officer'), $row, $responses);
    }

    public function award(string $note, int $supplier): void
    {
        $rfq = $this->rfqByNote($note);
        $row = $rfq->suppliers()->where('supplier_id', DemoContext::keyOf($this->toolkit->supplier($supplier)))->sole();

        app(PurchaseRfqService::class)->award($this->context->actor('purchasing_manager'), $row);
    }

    public function closeRfq(string $note): void
    {
        app(PurchaseRfqService::class)->close($this->context->actor('purchasing_manager'), $this->rfqByNote($note));
    }

    public function cancelRfq(string $note): void
    {
        app(PurchaseRfqService::class)->cancel($this->context->actor('purchasing_manager'), $this->rfqByNote($note));
    }

    public function expireRfq(string $note): void
    {
        app(PurchaseRfqService::class)->expire($this->context->actor('purchasing_manager'), $this->rfqByNote($note));
    }

    /** Manual replenishment need: a new reorder policy that is already breached at its warehouse. */
    public function policy(string $warehouseCode, string $variantKey, int $min, int $max): WarehouseReplenishmentPolicy
    {
        $inventory = DemoInventory::make();

        return WarehouseReplenishmentPolicy::query()->create([
            'warehouse_id' => DemoContext::keyOf($inventory->warehouse($warehouseCode)),
            'product_variant_id' => DemoContext::keyOf($this->toolkit->variant($variantKey)),
            'min_quantity' => (string) $min,
            'max_quantity' => (string) $max,
            'is_active' => true,
        ]);
    }

    public function deactivatePolicy(string $warehouseCode, string $variantKey): void
    {
        $inventory = DemoInventory::make();

        WarehouseReplenishmentPolicy::query()
            ->where('warehouse_id', DemoContext::keyOf($inventory->warehouse($warehouseCode)))
            ->where('product_variant_id', DemoContext::keyOf($this->toolkit->variant($variantKey)))
            ->firstOrFail()
            ->update(['is_active' => false]);
    }

    private function rfqByNote(string $note): PurchaseRfq
    {
        return PurchaseRfq::query()->where('notes', $note)->firstOrFail();
    }

    private function agreementByNote(string $note): PurchaseAgreement
    {
        return PurchaseAgreement::query()->where('notes', $note)->firstOrFail();
    }
}
