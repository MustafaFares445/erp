<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryAlertData;
use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Enums\InventoryImportRunStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryAlert;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventorySetting;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class InventoryAlertService
{
    public function syncStock(InventoryStock $stock): void
    {
        $available = (float) $stock->available_quantity;

        if ($available <= 0) {
            $this->activate(
                InventoryAlertType::OutOfStock,
                $stock,
                new InventoryAlertData(
                    __('admin.inventory.alerts.out_of_stock'),
                    InventoryAlertSeverity::Critical,
                    $this->stockContext($stock),
                ),
            );
            $this->resolve(InventoryAlertType::LowStock, $stock);
        } else {
            $this->resolve(InventoryAlertType::OutOfStock, $stock);
            $this->syncLowStock($stock);
        }

        $this->syncMissingDeviceIdentity($stock);
    }

    public function syncExpiry(InventoryLot $lot): void
    {
        $threshold = now()->addDays(InventorySetting::current()->expiry_alert_days);

        if ((float) $lot->on_hand_quantity <= 0 || $lot->expires_at === null || $lot->expires_at->greaterThan($threshold)) {
            $this->resolve(InventoryAlertType::Expiry, $lot);

            return;
        }

        $this->activate(
            InventoryAlertType::Expiry,
            $lot,
            new InventoryAlertData(
                __('admin.inventory.alerts.expiry'),
                $lot->expires_at->isPast() ? InventoryAlertSeverity::Critical : InventoryAlertSeverity::Warning,
                [
                    'expires_at' => $lot->expires_at->toDateString(),
                    'days_remaining' => $lot->daysRemaining(),
                    'available_quantity' => $lot->availableQuantity(),
                ],
            ),
        );
    }

    public function syncTransferDiscrepancy(StockTransfer $transfer): void
    {
        if (! $transfer->isDispatched() || $transfer->items()->doesntExist()) {
            $this->resolve(InventoryAlertType::TransferDiscrepancy, $transfer);

            return;
        }

        $this->activate(
            InventoryAlertType::TransferDiscrepancy,
            $transfer,
            new InventoryAlertData(
                __('admin.inventory.alerts.transfer_discrepancy'),
                InventoryAlertSeverity::Warning,
                ['transfer_number' => $transfer->transfer_number],
            ),
        );
    }

    public function syncImport(InventoryImportRun $run): void
    {
        if (! in_array($run->status, [
            InventoryImportRunStatus::ReadyWithErrors,
            InventoryImportRunStatus::Invalid,
            InventoryImportRunStatus::ConfirmedWithErrors,
            InventoryImportRunStatus::Failed,
        ], true)) {
            $this->resolve(InventoryAlertType::ImportError, $run);

            return;
        }

        $severity = in_array($run->status, [
            InventoryImportRunStatus::Invalid,
            InventoryImportRunStatus::Failed,
        ], true)
            ? InventoryAlertSeverity::Critical
            : InventoryAlertSeverity::Warning;

        $this->activate(
            InventoryAlertType::ImportError,
            $run,
            new InventoryAlertData(
                __('admin.inventory.alerts.import_error'),
                $severity,
                [
                    'status' => $run->status->value,
                    'failed_rows' => $run->failed_rows,
                    'rejected_rows' => $run->rejected_rows,
                    'failure_message' => $run->failure_message,
                ],
            ),
        );
    }

    /**
     * Records that expired goods were deliberately released by an actor holding the
     * expired-stock override, so the decision is visible after the fact rather than invisible.
     */
    public function raiseExpiredStockReleased(InventoryLot $lot, User $actor): void
    {
        $this->activate(
            InventoryAlertType::ExpiredStockReleased,
            $lot,
            new InventoryAlertData(
                __('admin.inventory.alerts.expired_stock_released'),
                InventoryAlertSeverity::Critical,
                [
                    'lot_number' => $lot->lot_number,
                    'expires_at' => $lot->expires_at?->toDateString(),
                    'days_expired' => $lot->daysRemaining() === null ? null : abs($lot->daysRemaining()),
                    'released_by' => $actor->getKey(),
                ],
            ),
        );
    }

    public function raiseDuplicateIdentity(string $kind, string $value, Model $subject): void
    {
        $this->activate(
            InventoryAlertType::DuplicateIdentity,
            $subject,
            new InventoryAlertData(
                __('admin.inventory.alerts.duplicate_identity'),
                InventoryAlertSeverity::Critical,
                ['kind' => $kind, 'value' => $value],
            ),
        );
    }

    public function syncMissingDeviceIdentity(InventoryStock $stock): void
    {
        $variant = ProductVariant::query()->find($stock->product_variant_id);

        if (! $variant instanceof ProductVariant || ! $variant->track_serials) {
            $this->resolve(InventoryAlertType::MissingDeviceIdentity, $stock);

            return;
        }

        $registeredDevices = SerializedInventoryUnit::query()
            ->where('product_variant_id', $stock->product_variant_id)
            ->where('warehouse_id', $stock->warehouse_id)
            ->whereIn('status', [
                SerializedInventoryUnitStatus::Available->value,
                SerializedInventoryUnitStatus::Damaged->value,
            ])
            ->count();
        $physicalQuantity = (float) $stock->on_hand_quantity;

        if (abs($physicalQuantity - $registeredDevices) < 0.0005) {
            $this->resolve(InventoryAlertType::MissingDeviceIdentity, $stock);

            return;
        }

        $this->activate(
            InventoryAlertType::MissingDeviceIdentity,
            $stock,
            new InventoryAlertData(
                __('admin.inventory.alerts.missing_device_identity'),
                InventoryAlertSeverity::Critical,
                [
                    'physical_quantity' => $physicalQuantity,
                    'registered_devices' => $registeredDevices,
                    'difference' => $physicalQuantity - $registeredDevices,
                ],
            ),
        );
    }

    private function syncLowStock(InventoryStock $stock): void
    {
        if ($stock->reorder_level === null || (float) $stock->available_quantity > (float) $stock->reorder_level) {
            $this->resolve(InventoryAlertType::LowStock, $stock);

            return;
        }

        $this->activate(
            InventoryAlertType::LowStock,
            $stock,
            new InventoryAlertData(
                __('admin.inventory.alerts.low_stock'),
                InventoryAlertSeverity::Warning,
                $this->stockContext($stock),
            ),
        );
    }

    private function activate(
        InventoryAlertType $type,
        Model $subject,
        InventoryAlertData $data,
    ): void {
        InventoryAlert::query()->updateOrCreate(
            [
                'type' => $type->value,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
            ],
            [
                'message' => $data->message,
                'severity' => $data->severity->value,
                'context' => $data->context,
                'resolved_at' => null,
            ],
        );
    }

    private function resolve(InventoryAlertType $type, Model $subject): void
    {
        InventoryAlert::query()
            ->where('type', $type->value)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    /** @return array{on_hand_quantity: float, reserved_quantity: float, damaged_quantity: float, available_quantity: float, reorder_level: float|null} */
    private function stockContext(InventoryStock $stock): array
    {
        return [
            'on_hand_quantity' => (float) $stock->on_hand_quantity,
            'reserved_quantity' => (float) $stock->reserved_quantity,
            'damaged_quantity' => (float) $stock->damaged_quantity,
            'available_quantity' => (float) $stock->available_quantity,
            'reorder_level' => $stock->reorder_level === null ? null : (float) $stock->reorder_level,
        ];
    }
}
