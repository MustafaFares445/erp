<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Services\Support\MaintenanceRecordService;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Sixty-four standalone preventive/corrective maintenance requests over 12 months.
 * Real service status transitions; these are requests, NOT fictitious signed
 * calibration or installation certificates.
 */
final class UnifiedMaintenanceHistorySeeder extends DemoSeeder
{
    private const array Scenes = [
        [MaintenanceKind::Preventive, 'Quarterly Class B autoclave inspection and cycle validation'],
        [MaintenanceKind::Corrective, 'Inspect and service dental handpiece bearing noise'],
        [MaintenanceKind::Inspection, 'Check sterilization washer water filtration and safety interlocks'],
        [MaintenanceKind::Calibration, 'Schedule intraoral scanner reference calibration'],
        [MaintenanceKind::Installation, 'Plan chair-mounted imaging camera installation'],
        [MaintenanceKind::Corrective, 'Service dental printer curing module and replace worn gasket'],
    ];

    protected function seed(DemoContext $context): void
    {
        $anchor = Carbon::parse(config()->string('demo.unified.anchor', now()->toDateString()));
        $limit = max(1, min(64, (int) (getenv('DEMO_MAINTENANCE_LIMIT') ?: 64)));
        $customers = CustomerProfile::query()->where('customer_code', 'like', 'DEMO-CUST-%')
            ->where('is_active', true)->orderBy('customer_code')->get();
        if ($customers->count() < 100) {
            throw new LogicException('Maintenance history requires the unified customers.');
        }

        $service = app(MaintenanceRecordService::class);
        foreach (range(1, $limit) as $n) {
            [$kind, $task] = self::Scenes[($n - 1) % count(self::Scenes)];
            $description = sprintf('[DEMO-HISTORY] Maintenance %03d — %s', $n, $task);
            if (MaintenanceRecord::query()->where('description', $description)->exists()) {
                continue;
            }

            $date = $anchor->copy()->subMonths(11 - (($n - 1) % 12))
                ->startOfMonth()->addDays(($n * 3) % 24 + 1)->setTime(9, 0);
            if ($date->greaterThan($anchor)) {
                $date = $anchor->copy()->subDays(($n % 6) + 1);
            }

            $context->at($date->toDateTimeString());
            $manager = $context->as('support_manager');
            $customer = $customers[($n * 11) % $customers->count()]
                ?? throw new LogicException('Missing customer for maintenance request.');
            $record = $service->createStandalone([
                'customer_id' => $customer->getKey(),
                'description' => $description,
                'maintenance_kind' => $kind,
                'serial_number' => sprintf('DEMO-EXT-EQUIP-%03d', $n),
                'failure_started_at' => $date->toDateTimeString(),
            ], $manager);

            $branch = $n % 8;
            if ($branch === 0) {
                continue; // New request, still open.
            }

            $context->at($date->copy()->addHours(2)->toDateTimeString());
            if ($branch === 7) {
                $service->transition($record, MaintenanceStatus::Cancelled, $manager,
                    'Client cancelled before the site visit.');

                continue;
            }

            $service->transition($record, MaintenanceStatus::Diagnosing, $manager,
                'Initial technical diagnostics scheduled.');

            if ($branch === 1) {
                continue;
            }

            $context->at($date->copy()->addHours(3)->toDateTimeString());
            if ($branch === 2) {
                $service->transition($record, MaintenanceStatus::AwaitingApproval, $manager,
                    'Waiting for the technical supervisor approval.');

                continue;
            }

            $service->transition($record, MaintenanceStatus::ReadyForRepair, $manager,
                'Diagnostic inspection approved, parts available.');

            if ($branch === 3) {
                continue;
            }

            $context->at($date->copy()->addHours(4)->toDateTimeString());
            $service->transition($record, MaintenanceStatus::InProgress, $manager,
                'Technician has begun the on-site maintenance.');

            if ($branch === 4) {
                continue;
            }

            $context->at($date->copy()->addDays(1)->toDateTimeString());
            $service->transition($record, MaintenanceStatus::QualityAssurance, $manager,
                'Functional check and cleaning completed.');

            if ($branch === 5) {
                continue;
            }

            $context->at($date->copy()->addDays(2)->toDateTimeString());
            $service->transition($record, MaintenanceStatus::Closed, $manager,
                'Customer confirmed device returned to normal operating parameters.');
        }

        $this->note("Added {$limit} historical maintenance requests with valid status paths.");
    }
}
