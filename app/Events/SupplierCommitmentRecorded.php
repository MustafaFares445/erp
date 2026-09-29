<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupplierCommitmentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public SupplierConfirmation $confirmation,
    ) {}
}
