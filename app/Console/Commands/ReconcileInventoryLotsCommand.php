<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReconciliationScope;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Reconciliation\ReconciliationRunRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:lots:reconcile {--scheduled : Mark this run as scheduler-triggered}')]
#[Description('Verify canonical lot, condition, reservation, and serialized-custody invariants without modifying inventory')]
final class ReconcileInventoryLotsCommand extends Command
{
    public function handle(
        InventoryLotReconciliationService $reconciliation,
        ReconciliationRunRecorder $recorder,
    ): int {
        $inspection = $reconciliation->inspectDetailed();
        $report = $inspection['report'];

        $recorder->record(
            ReconciliationScope::InventoryLots,
            $inspection['invariants'],
            (bool) $this->option('scheduled') ? 'schedule' : 'manual',
        );

        $this->components->info(sprintf(
            'Checked %d lot balances, %d aggregate grains, %d reservation grains, %d serialized lot units, %d return lines, and %d ledger movements.',
            $report['checked_lot_balances'],
            $report['checked_aggregate_balances'],
            $report['checked_reservation_grains'],
            $report['checked_serial_grains'],
            $report['checked_return_lines'],
            $report['checked_movements'],
        ));

        if ($report['errors'] === []) {
            $this->components->info('PASS: canonical lot reconciliation completed with no invariant violations.');

            return self::SUCCESS;
        }

        foreach ($report['errors'] as $error) {
            $this->components->error($error);
        }

        $this->components->error(sprintf(
            'FAIL: %d canonical lot invariant violation(s) detected. No data was modified.',
            count($report['errors']),
        ));

        return self::FAILURE;
    }
}
