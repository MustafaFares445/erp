<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\SalesProcurementRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * @deprecated Compatibility facade. Purchasing execution has moved to
 * App\Services\Purchasing\SalesDemandProcurementService.
 */
final readonly class SalesProcurementService
{
    public function __construct(private SalesProcurementRequirementService $requirements) {}

    /** @return Collection<int, SalesProcurementRequirement> */
    public function detectShortages(User $actor, Order $order): Collection
    {
        return $this->requirements->synchronize($order, $actor);
    }

    public function refreshFromPurchaseOrder(PurchaseOrder $purchaseOrder): void
    {
        $this->requirements->refreshFromPurchaseOrder($purchaseOrder);
    }
}
