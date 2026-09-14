<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesProcurementRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SalesProcurementRequirementService
{
    public function __construct(private OrderFulfillmentQuantityService $quantities) {}

    /** @return Collection<int, SalesProcurementRequirement> */
    public function synchronize(Order $order, ?User $actor = null): Collection
    {
        return DB::transaction(function () use ($order, $actor): Collection {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->sole();
            if ($locked->status !== OrderStatus::Released) {
                return new Collection;
            }

            $progress = $this->quantities->forOrder($locked)->sortBy('orderLineId');
            $variantIds = $progress->pluck('productVariantId')->unique()->values()->all();
            $available = InventoryStock::query()
                ->whereIn('product_variant_id', $variantIds)
                ->selectRaw('product_variant_id, SUM(available_quantity) AS available_quantity')
                ->groupBy('product_variant_id')
                ->pluck('available_quantity', 'product_variant_id')
                ->map(fn (mixed $quantity): float => is_numeric($quantity) ? (float) $quantity : 0.0)
                ->all();

            $existing = $locked->procurementRequirements()->orderBy('id')->lockForUpdate()->get()->keyBy('order_line_id');
            $active = new Collection;

            foreach ($progress as $lineProgress) {
                $availableForVariant = $available[$lineProgress->productVariantId] ?? 0.0;
                $covered = min($availableForVariant, $lineProgress->remainingToPlanBase);
                $available[$lineProgress->productVariantId] = max(0.0, $availableForVariant - $covered);
                $shortage = round(max(0.0, $lineProgress->remainingToPlanBase - $covered), 6);
                $requirement = $existing->get($lineProgress->orderLineId);

                if ($shortage <= 0.000001) {
                    if ($requirement instanceof SalesProcurementRequirement
                        && $requirement->purchase_order_id === null
                        && ! $requirement->isFulfilled()) {
                        $requirement->forceFill(['status' => 'cancelled'])->save();
                    }
                    continue;
                }

                if (! $requirement instanceof SalesProcurementRequirement) {
                    $requirement = $locked->procurementRequirements()->create([
                        'order_line_id' => $lineProgress->orderLineId,
                        'product_variant_id' => $lineProgress->productVariantId,
                        'required_base_quantity' => $shortage,
                        'fulfilled_base_quantity' => 0,
                        'status' => 'open',
                    ]);
                } elseif ($requirement->purchase_order_id === null) {
                    $fulfilled = min($shortage, (float) $requirement->fulfilled_base_quantity);
                    $requirement->forceFill([
                        'required_base_quantity' => $shortage,
                        'fulfilled_base_quantity' => $fulfilled,
                        'status' => $fulfilled + 0.000001 >= $shortage ? 'fulfilled' : 'open',
                    ])->save();
                }

                if (! in_array($requirement->status, ['fulfilled', 'cancelled'], true)) {
                    $active->push($requirement->refresh());
                }
            }

            if ($actor instanceof User) {
                activity()->performedOn($locked)->causedBy($actor)
                    ->withProperties(['source_channel' => 'logistics', 'active_requirement_count' => $active->count()])
                    ->log('sales.order.procurement_requirements_synchronized');
            }

            return $active;
        }, attempts: 5);
    }

    public function refreshFromPurchaseOrder(PurchaseOrder $purchaseOrder): void
    {
        DB::transaction(function () use ($purchaseOrder): void {
            $requirements = SalesProcurementRequirement::query()
                ->where('purchase_order_id', $purchaseOrder->getKey())
                ->whereNotIn('status', ['fulfilled', 'cancelled'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($requirements as $requirement) {
                $line = PurchaseOrderLine::query()->find($requirement->purchase_order_line_id);
                $received = $line instanceof PurchaseOrderLine ? (float) $line->received_base_quantity : 0.0;
                $fulfilled = min((float) $requirement->required_base_quantity, $received);
                $requirement->forceFill([
                    'fulfilled_base_quantity' => round($fulfilled, 6),
                    'status' => $fulfilled + 0.000001 >= (float) $requirement->required_base_quantity
                        ? 'fulfilled'
                        : 'purchasing',
                ])->save();
            }
        }, attempts: 5);
    }
}
