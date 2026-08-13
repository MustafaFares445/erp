<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\StockTransfer;
use App\Services\Inventory\StockTransferService;

/**
 * Writes one `audit_logs` row for every transfer lifecycle action other than
 * confirmation (FR-014a): created, edited (including item-line changes, which
 * `touch()` the parent), discarded (soft delete), and restored.
 *
 * Confirmation is audited once, by {@see StockTransferService::confirm()}
 * itself, which persists the status/number change with `saveQuietly()` so
 * this observer's {@see self::updated()} does not also fire for that
 * transition (research D5) — avoiding a duplicate audit row for one event.
 *
 * @see /specs/004-stock-transfers/contracts/audit-log.md
 */
final readonly class StockTransferObserver
{
    public function created(StockTransfer $transfer): void
    {
        activity()
            ->performedOn($transfer)
            ->withChanges([
                'attributes' => $this->headerSnapshot($transfer),
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('inventory.transfer.created');
    }

    public function updated(StockTransfer $transfer): void
    {
        $changes = $transfer->getChanges();

        $old = [];

        foreach (array_keys($changes) as $key) {
            $old[$key] = $transfer->getOriginal($key);
        }

        activity()
            ->performedOn($transfer)
            ->withChanges([
                'old' => $old,
                'attributes' => $changes,
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('inventory.transfer.edited');
    }

    public function deleted(StockTransfer $transfer): void
    {
        activity()
            ->performedOn($transfer)
            ->withChanges([
                'old' => $this->headerSnapshot($transfer),
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('inventory.transfer.discarded');
    }

    public function restored(StockTransfer $transfer): void
    {
        activity()
            ->performedOn($transfer)
            ->withChanges([
                'attributes' => $this->headerSnapshot($transfer),
            ])
            ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
            ->log('inventory.transfer.restored');
    }

    /**
     * @return array<string, mixed>
     */
    private function headerSnapshot(StockTransfer $transfer): array
    {
        return [
            'from_warehouse_id' => $transfer->from_warehouse_id,
            'to_warehouse_id' => $transfer->to_warehouse_id,
            'notes' => $transfer->notes,
            'status' => $transfer->status->value,
        ];
    }
}
