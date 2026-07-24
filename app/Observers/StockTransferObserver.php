<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\StockTransfer;
use App\Services\Audit\AuditLogger;
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
    public function __construct(
        private AuditLogger $auditLogger,
    ) {}

    public function created(StockTransfer $transfer): void
    {
        $this->auditLogger->log(
            action: 'inventory.transfer.created',
            entity: $transfer,
            newValues: $this->headerSnapshot($transfer),
            sourceChannel: 'dashboard',
        );
    }

    public function updated(StockTransfer $transfer): void
    {
        $changes = $transfer->getChanges();

        $old = [];

        foreach (array_keys($changes) as $key) {
            $old[$key] = $transfer->getOriginal($key);
        }

        $this->auditLogger->log(
            action: 'inventory.transfer.edited',
            entity: $transfer,
            oldValues: $old,
            newValues: $changes,
            sourceChannel: 'dashboard',
        );
    }

    public function deleted(StockTransfer $transfer): void
    {
        $this->auditLogger->log(
            action: 'inventory.transfer.discarded',
            entity: $transfer,
            oldValues: $this->headerSnapshot($transfer),
            sourceChannel: 'dashboard',
        );
    }

    public function restored(StockTransfer $transfer): void
    {
        $this->auditLogger->log(
            action: 'inventory.transfer.restored',
            entity: $transfer,
            newValues: $this->headerSnapshot($transfer),
            sourceChannel: 'dashboard',
        );
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
