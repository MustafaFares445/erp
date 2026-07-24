<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryAlert;
use App\Models\InventoryLot;
use App\Models\InventorySetting;
use App\Models\InventoryStock;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Model;

final class InventoryAlertService
{
    public function syncStock(InventoryStock $stock): void
    {
        if ($stock->reorder_level === null || (float) $stock->available_quantity > (float) $stock->reorder_level) {
            $this->resolve('low_stock', $stock);

            return;
        }

        $this->raise('low_stock', $stock, __('admin.inventory.alerts.low_stock'));
    }

    public function syncExpiry(InventoryLot $lot): void
    {
        $threshold = now()->addDays(InventorySetting::current()->expiry_alert_days);

        if ((float) $lot->on_hand_quantity <= 0 || $lot->expires_at === null || $lot->expires_at->greaterThan($threshold)) {
            $this->resolve('expiry', $lot);

            return;
        }

        $this->raise('expiry', $lot, __('admin.inventory.alerts.expiry'));
    }

    public function syncTransferDiscrepancy(StockTransfer $transfer): void
    {
        if (! $transfer->isDispatched() || $transfer->items()->doesntExist()) {
            $this->resolve('transfer_discrepancy', $transfer);

            return;
        }

        $this->raise('transfer_discrepancy', $transfer, __('admin.inventory.alerts.transfer_discrepancy'));
    }

    private function raise(string $type, Model $subject, string $message): void
    {
        InventoryAlert::query()->updateOrCreate(
            ['type' => $type, 'subject_type' => $subject::class, 'subject_id' => $subject->getKey()],
            ['message' => $message, 'resolved_at' => null],
        );
    }

    private function resolve(string $type, Model $subject): void
    {
        InventoryAlert::query()
            ->where('type', $type)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }
}
