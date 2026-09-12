<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\BillStatus;
use App\Models\Bill;
use App\Services\Accounting\GrniClearingService;

final readonly class BillObserver
{
    public function updated(Bill $bill): void
    {
        if (! $bill->wasChanged('status') || $bill->status !== BillStatus::Approved) {
            return;
        }

        app(GrniClearingService::class)->clearForApprovedBill($bill);
    }
}
