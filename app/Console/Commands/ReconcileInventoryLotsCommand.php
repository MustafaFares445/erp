<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReconciliationScope;
use App\Models\ReconciliationRun;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Reconciliation\ReconciliationRunRecorder;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * WP-4.1 (PHASE_4_PLAN.md §1): a full replay of every canonical grain gets
 * slower as the ledger grows, so the default mode here is incremental —
 * checking only grains touched since the last clean (all-invariants-passed)
 * run. `--full` forces a complete replay regardless, and is the mode the
 * weekly backstop schedule entry uses (see routes/console.php) so a full
 * replay still runs at least once a week even if every daily incremental
 * run keeps passing.
 *
 * Incremental mode is only ever a *narrower* scope than full — it can never
 * report an invariant clean that a full replay would have flagged, because
 * every check it narrows is scoped to rows whose own `updated_at` (or, for
 * append-only movements, `created_at`) moved since the watermark. But an
 * incremental run's watermark is only as trustworthy as the last clean run
 * it is measured from; if no clean run has ever completed, this command
 * automatically falls back to a full replay.
 */
#[Signature('inventory:lots:reconcile {--scheduled : Mark this run as scheduler-triggered} {--full : Force a full replay, ignoring the last clean run}')]
#[Description('Verify canonical lot, condition, reservation, and serialized-custody invariants without modifying inventory')]
final class ReconcileInventoryLotsCommand extends Command
{
    public function handle(
        InventoryLotReconciliationService $reconciliation,
        ReconciliationRunRecorder $recorder,
    ): int {
        $since = (bool) $this->option('full') ? null : $this->lastCleanRunStartedAt();

        $inspection = $reconciliation->inspectDetailed($since);
        $report = $inspection['report'];

        $recorder->record(
            ReconciliationScope::InventoryLots,
            $inspection['invariants'],
            (bool) $this->option('scheduled') ? 'schedule' : 'manual',
        );

        $this->components->info($since instanceof CarbonInterface
            ? sprintf('Incremental replay: checking grains touched since %s.', $since->toDateTimeString())
            : 'Full replay: checking every canonical grain.');

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

    /**
     * The start time of the most recent run for this scope in which every
     * invariant passed — the safe incremental watermark. A run is a batch of
     * `ReconciliationRun` rows (one per invariant) sharing the same
     * `started_at`; grouping by it and rejecting any batch containing a
     * failure keeps a partially-failed run from being trusted as a
     * watermark.
     */
    private function lastCleanRunStartedAt(): ?CarbonInterface
    {
        $row = ReconciliationRun::query()
            ->where('scope', ReconciliationScope::InventoryLots)
            ->select('started_at')
            ->groupBy('started_at')
            ->havingRaw('SUM(CASE WHEN passed = 0 THEN 1 ELSE 0 END) = 0')
            ->orderByDesc('started_at')
            ->first();

        return $row?->started_at;
    }
}
