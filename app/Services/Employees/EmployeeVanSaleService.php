<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Data\Orders\OrderFulfillmentData;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\Sales\DirectOrderLinePricingService;
use App\Services\Sales\InvoiceService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class EmployeeVanSaleService
{
    public function __construct(
        private OrderFulfillmentService $fulfillment,
        private DirectOrderLinePricingService $pricing,
        private InventoryOperationService $inventoryOperations,
        private InvoiceService $invoices,
    ) {}

    /** @param array<string, mixed> $data @return array{order:Order,invoice:Invoice} */
    public function create(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $profile = $this->profile($actor);
            $warehouse = $this->warehouse($profile);
            $customer = CustomerProfile::query()
                ->whereKey((int) $data['customer_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $products = is_array($data['products'] ?? null) ? array_values($data['products']) : [];

            if ($products === []) {
                throw new DomainException('A van sale requires at least one product.');
            }

            $order = $this->fulfillment->create(new OrderFulfillmentData(
                customer: $customer,
                products: $products,
                shipments: [[
                    'warehouse_id' => $warehouse->getKey(),
                    'delivery_type' => 'inner',
                    'assignments' => $products,
                ]],
                actor: $actor,
                notes: is_string($data['notes'] ?? null) ? $data['notes'] : 'Employee van sale',
                scheduledAt: now(),
                responsible: $actor,
            ));

            $lastPricedLine = null;
            foreach ($order->lines()->orderBy('id')->get() as $line) {
                if (! $line instanceof OrderLine) {
                    continue;
                }

                if ($line->unit_price === null) {
                    $this->pricing->prepare($line);
                    $line->save();
                }
                $lastPricedLine = $line;
            }

            if ($lastPricedLine instanceof OrderLine) {
                $this->pricing->refreshOrderTotals($lastPricedLine);
            }

            /** @var Collection<int, InventoryOperation> $deliveries */
            $deliveries = $order->deliveries()->orderBy('id')->get();
            $completed = new Collection;
            foreach ($deliveries as $delivery) {
                $completed->push($this->inventoryOperations->complete($delivery, $actor));
            }

            $invoice = $this->invoices->createFromDeliveries($actor, $completed);
            $invoice = $this->invoices->issue($actor, $invoice);

            activity()->performedOn($order)->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'employee_api',
                    'warehouse_id' => $warehouse->getKey(),
                    'invoice_id' => $invoice->getKey(),
                ])
                ->log('sales.van_sale.completed');

            return ['order' => $order->refresh(), 'invoice' => $invoice];
        }, attempts: 5);
    }

    private function profile(User $actor): EmployeeProfile
    {
        $profile = $actor->employeeProfile;

        if (! $actor->isEmployee() || ! $profile instanceof EmployeeProfile || ! $profile->is_active) {
            throw new DomainException('An active employee profile is required.');
        }

        return $profile;
    }

    private function warehouse(EmployeeProfile $profile): Warehouse
    {
        $warehouse = $profile->vanWarehouse;

        if (! $warehouse instanceof Warehouse || ! $warehouse->is_active) {
            throw new DomainException('The employee does not have an active van warehouse assigned.');
        }

        return $warehouse;
    }
}
