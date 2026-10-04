<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Invoice;
use App\Models\MaintenanceSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replays the production scheduler over the demo month and verifies the notification and audit trail.
 *
 * No notification or audit row is invented here: every row already came from workflow events in the
 * other demo seeders. This seeder only runs the scheduled commands the real scheduler would have run
 * (overdue invoice reminders, preventive maintenance generation, SLA reconciliation, campaign dispatch,
 * approval and expiry reminders), each on the day it would have run, and tolerates modules that have
 * no data (no invoices, no schedules, no pending documents). Run it last.
 *
 * The commands deduplicate through notification_deliveries (the pending-approval digest is guarded explicitly), so running it twice adds nothing.
 */
final class DemoSystemActivitySeeder extends DemoSeeder
{
    protected function seed(DemoContext $context): void
    {
        $firstDay = Carbon::parse(DemoContext::PeriodStart)->startOfDay();
        $lastDay = Carbon::parse(DemoContext::PeriodEnd)->startOfDay();

        $this->replayDailySweeps($context, $firstDay, $lastDay);

        // End-of-period sweeps (their findings depend on the final state of the other modules).
        $context->at('2026-10-03 09:30');
        // The pending-approval digest is a daily reminder by design (no per-document dedupe), so a rerun must not repeat it.
        if (! DB::table('notification_deliveries')->where('template_key', 'approval.pending')->whereDate('created_at', '2026-10-03')->exists()) {
            $this->sweep('notifications:pending-approvals');
        }

        $this->sweep('notifications:expiring-lots');
        $this->sweep('crm:campaigns:dispatch-due');

        $context->at('2026-10-03 17:58');
        $this->sweep('support:sla:reconcile');

        $this->verify();
    }

    private function replayDailySweeps(DemoContext $context, Carbon $firstDay, Carbon $lastDay): void
    {
        $hasInvoices = Schema::hasTable('invoices') && Invoice::query()->whereNotNull('issued_at')->exists();
        $firstSchedule = Schema::hasTable('maintenance_schedules') ? MaintenanceSchedule::query()->min('created_at') : null;
        $scheduleFrom = is_string($firstSchedule) ? Carbon::parse($firstSchedule)->startOfDay() : null;

        for ($day = $firstDay->copy(); $day->lte($lastDay); $day->addDay()) {
            $context->at($day->format('Y-m-d').' 07:45');

            if ($hasInvoices) {
                $this->sweep('notifications:overdue-invoices');
            }

            if ($scheduleFrom instanceof Carbon && $day->gte($scheduleFrom)) {
                $this->sweep('maintenance:schedules:generate');
            }
        }
    }

    private function sweep(string $command): void
    {
        Artisan::call($command);
        $this->note(sprintf('%s -> %s', $command, mb_trim(Artisan::output())));
    }

    private function verify(): void
    {
        $deliveries = DB::table('notification_deliveries')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $this->note('Notification deliveries by status: '.json_encode($deliveries));

        $queued = DemoContext::intOf($deliveries['queued'] ?? 0);
        if ($queued > 0) {
            $this->note("WARNING: {$queued} deliveries are still Queued; a worker would send them.");
        }

        if (Schema::hasTable('jobs') && DB::table('jobs')->count() > 0) {
            $this->note('WARNING: the jobs table is not empty; a later queue worker would run those jobs.');
        }

        $actions = DB::table('activity_log')
            ->select('description', DB::raw('count(*) as total'))
            ->groupBy('description')
            ->orderByDesc('total')
            ->limit(15)
            ->pluck('total', 'description')
            ->all();

        $this->note('Top audit actions: '.json_encode($actions));
    }
}
