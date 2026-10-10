<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\TicketEquipmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketTriageService;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The same development company's annual support history, not another profile.
 *
 * Creates 120 realistic customer-linked tickets with meaningful issue narratives,
 * triage evidence, assignment and service-owned status transitions.
 */
final class UnifiedSupportHistorySeeder extends DemoSeeder
{
    private const array Issues = [
        [TicketType::SoftwareIssue, 'Intraoral scanner synchronization drops after workstation restart', 'Imaging workstation loses the latest scan session following an update.', 'Dental scanner'],
        [TicketType::HardwareIssue, 'Autoclave chamber pressure is unstable during sterilization', 'Cycle validation fails and the sterilizer needs technical troubleshooting.', 'Class B autoclave'],
        [TicketType::GeneralSupport, 'Request for staff training on printer resin changeover', 'Laboratory team needs guidance to switch from model resin to surgical guide resin.', 'Dental 3D printer'],
        [TicketType::MaintenanceRequest, 'Preventive service requested for high-usage handpiece', 'Handpiece vibration increased after repeated daily sterilization cycles.', 'Dental handpiece'],
        [TicketType::ProductQualityIssue, 'Ceramic block edge chipping reported during milling', 'Technician reported unexpected wear during a routine restoration batch.', 'CAD CAM milling system'],
        [TicketType::SoftwareIssue, 'Workstation cannot export completed implant planning files', 'The planning output is not exported to the laboratory shared workstation.', 'Clinical workstation'],
        [TicketType::MaintenanceRequest, 'Ultrasonic cleaner requires heater inspection', 'Heating element is slow to reach the validated decontamination temperature.', 'Ultrasonic cleaner'],
        [TicketType::GeneralSupport, 'Clarification of zirconia disc compatibility', 'Customer asks which milling insert matches the ordered restoration blanks.', 'Dental milling unit'],
        [TicketType::ProductQualityIssue, 'Resin container seal appears damaged on opening', 'Clinic reports a leaking container and requests lot quality evaluation.', 'Resin bottle'],
        [TicketType::HardwareIssue, 'Intraoral camera intermittent cable disconnect', 'Image feed disappears when the operator rotates the handpiece.', 'Intraoral camera'],
    ];

    protected function seed(DemoContext $context): void
    {
        $anchor = Carbon::parse(config()->string('demo.unified.anchor', now()->toDateString()))->startOfDay();
        $customers = CustomerProfile::query()
            ->where('customer_code', 'like', 'DEMO-CUST-%')
            ->where('is_active', true)->orderBy('customer_code')->get();
        $employees = EmployeeProfile::query()
            ->whereIn('employee_code', ['DEMO-EMP-007', 'DEMO-EMP-008'])->orderBy('id')->get();
        if ($customers->isEmpty() || $employees->isEmpty()) {
            throw new LogicException('Unified support history needs active customers and two support technicians.');
        }

        $manager = $context->actor('support_manager');
        $intake = app(TicketIntakeService::class);
        $triage = app(TicketTriageService::class);
        $lifecycle = app(TicketLifecycleService::class);

        foreach (range(1, 120) as $n) {
            $key = sprintf('[DEMO-HISTORY] Support case %04d: ', $n);
            if (Ticket::query()->where('title', 'like', $key.'%')->exists()) {
                continue;
            }

            [$type, $title, $description, $equipment] = self::Issues[($n - 1) % count(self::Issues)];
            $month = 11 - (($n - 1) % 12);
            $date = $anchor->copy()->subMonths($month)->startOfMonth()
                ->addDays(1 + (($n * 7) % 23))->setTime(8 + ($n % 8), 0);
            if ($date->greaterThan($anchor)) {
                $date = $anchor->copy()->subDays($n % 7 + 1);
            }

            $context->at($date->toDateTimeString());
            $context->as('support_manager');
            // A formal quality complaint requires proven delivered-line context.
            // Generic service intake cannot fabricate delivery evidence, so
            // document a quality enquiry as general support here; the canonical
            // month separately covers the proper quality-complaint workflow.
            $intakeType = $type === TicketType::ProductQualityIssue
                ? TicketType::GeneralSupport
                : $type;
            $customer = $customers[($n * 17) % $customers->count()]
                ?? throw new LogicException('Missing customer for historical support ticket.');
            $customer = $customers[($n * 17) % $customers->count()]
                ?? throw new LogicException('Missing customer for historical support ticket.');
            $customer = $customers[($n * 17) % $customers->count()]
                ?? throw new LogicException('Missing customer for historical support ticket.');
            $ticket = $intake->create([
                'customer_id' => $customer->getKey(),
                'type' => $intakeType,
                'priority' => [TicketPriority::Low, TicketPriority::Normal, TicketPriority::Normal, TicketPriority::High, TicketPriority::Urgent][$n % 5],
                'title' => $key.$title,
                'description' => $description.' Synthetic, for internal QA use only.',
            ], $manager);

            $branch = $n % 10;
            if ($branch === 0) {
                continue; // New, not yet triaged.
            }

            if ($branch === 1) {
                $context->at($date->copy()->addHours(2)->toDateTimeString());
                $lifecycle->transition($ticket, TicketStatus::Cancelled, $manager, 'Duplicate enquiry, closed at intake.');

                continue;
            }

            $context->at($date->copy()->addHours(2)->toDateTimeString());
            $ticket = $triage->triage($ticket, [
                'equipment_source' => TicketEquipmentSource::External,
                'external_equipment_name' => $equipment,
                'service_path' => $type === TicketType::MaintenanceRequest
                    ? TicketServicePath::Maintenance : TicketServicePath::RemoteSupport,
                'diagnostic_fee_required' => false,
            ], $manager);

            if ($branch === 2) {
                continue; // Triage complete, team has not assigned.
            }

            $context->at($date->copy()->addHours(3)->toDateTimeString());
            $employee = $employees[$n % $employees->count()]
                ?? throw new LogicException('Missing technician for support ticket.');
            $lifecycle->assign($ticket->refresh(), $employee, $manager);

            if ($branch === 3) {
                continue;
            }

            $context->at($date->copy()->addHours(4)->toDateTimeString());
            $lifecycle->transition($ticket->refresh(), TicketStatus::InProgress, $manager, 'Diagnosis started.');

            if ($branch === 4) {
                continue;
            }

            if ($branch === 5) {
                $lifecycle->transition($ticket->refresh(), TicketStatus::WaitingCustomer, $manager, 'Awaiting the clinic maintenance window.');

                continue;
            }

            $context->at($date->copy()->addDays(1)->toDateTimeString());
            $lifecycle->transition($ticket->refresh(), TicketStatus::Resolved, $manager,
                'Remote troubleshooting or field support completed; customer confirmed functional test.');

            if ($branch >= 8) {
                $context->at($date->copy()->addDays(2)->toDateTimeString());
                $lifecycle->transition($ticket->refresh(), TicketStatus::Closed, $manager, 'Customer acknowledged the resolution.');
            }
        }

        $this->note('Added 120 ticket histories with triage, assignments, SLA and multiple terminal states.');
    }
}
