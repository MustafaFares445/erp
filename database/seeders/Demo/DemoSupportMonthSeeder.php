<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Data\Support\LabourEntryData;
use App\Data\Support\MaintenanceScheduleData;
use App\Data\Support\ThirdPartyCostData;
use App\Enums\DashboardRole;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceStatus;
use App\Enums\NotificationChannel;
use App\Enums\OccurrenceStatus;
use App\Enums\SalaryCalculationMode;
use App\Enums\SerializedCustodyType;
use App\Enums\TicketCustomerImpact;
use App\Enums\TicketEquipmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\UserType;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\MaintenanceTask;
use App\Models\NotificationPreference;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryLotService;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Payments\StripeCheckoutService;
use App\Services\Payments\StripePaymentReconciliationService;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\MaintenanceCostService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\MaintenanceScheduleService;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketMessageService;
use App\Services\Support\TicketPaymentService;
use App\Services\Support\TicketProviderSettlementService;
use App\Services\Support\TicketTriageService;
use App\Services\Support\WarrantyClaimService;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Support and maintenance story of the demo month: 15 tickets covering every ticket status
 * (two paid diagnostic-fee flows, one through the offline Stripe test client), natural SLA
 * breaches produced by letting the scene clock run past the due times and sweeping with the
 * production reconcile command, plus eight maintenance requests (every maintenance status),
 * service records, warranty diagnosis and coverage decisions, parts consumption, labour and
 * third-party costs and a preventive schedule when a delivered unit exists.
 *
 * Two Support Agents (DEMO-EMP-007 / DEMO-EMP-008) are created here: the six roster employees
 * are field-channel users that cannot enter the admin panel, which ticket work requires.
 *
 * Run after DemoMasterDataSeeder, DemoInventoryOpeningSeeder and the baseline (payment methods).
 */
final class DemoSupportMonthSeeder extends DemoSeeder
{
    private const string MarkerTitle = 'Surgical drill handpiece loses torque under load';

    /** @var array<string, array{name: string, email: string, code: string, title: string, phone: string, base: float, rate: int}> */
    private const array Agents = [
        'agent1' => ['name' => 'Rami Haddad', 'email' => 'demo.support.agent1@ierp.test', 'code' => 'DEMO-EMP-007', 'title' => 'Field Support Technician', 'phone' => '+97155500007', 'base' => 4200.0, 'rate' => 6000],
        'agent2' => ['name' => 'Dina Salem', 'email' => 'demo.support.agent2@ierp.test', 'code' => 'DEMO-EMP-008', 'title' => 'Support Technician', 'phone' => '+97155500008', 'base' => 4000.0, 'rate' => 5500],
    ];

    private DemoContext $context;

    /** @var list<array{0: string, 1: int, 2: Closure}> */
    private array $events = [];

    /** @var array<string, Ticket> */
    private array $tickets = [];

    /** @var array<string, MaintenanceRecord> */
    private array $records = [];

    /** @var array<string, MaintenanceTask> */
    private array $tasks = [];

    /** @var array<string, ServiceRecordPart> */
    private array $parts = [];

    /** @var array<string, EmployeeProfile> */
    private array $agents = [];

    private ?SerializedInventoryUnit $unit = null;

    protected function seed(DemoContext $context): void
    {
        if (Ticket::query()->where('title', self::MarkerTitle)->exists()) {
            $this->note('Support month story already seeded (flagship ticket exists) - skipping.');

            return;
        }

        $this->context = $context;
        $this->unit = SerializedInventoryUnit::query()->where('custody_type', SerializedCustodyType::Customer->value)->orderBy('id')->first();

        $this->seedAgents($context);
        $this->planPreferences();
        $this->planClosedMaintenanceTicket();
        $this->planCancelledTicket();
        $this->planPaidTicket();
        $this->planUrgentBreachTicket();
        $this->planResolvedTickets();
        $this->planStripeTicket();
        $this->planWaitingTicket();
        $this->planMaintenanceStandalone();
        $this->planPendingPaymentTickets();
        $this->planLateTickets();
        $this->planSlaSweeps();
        $this->replay();
    }

    // ------------------------------------------------------------------ plumbing

    private function on(string $at, Closure $do): void
    {
        $this->events[] = [$at, count($this->events), $do];
    }

    private function replay(): void
    {
        usort($this->events, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($this->events as [$at, , $do]) {
            $this->context->at($at);
            $do();
        }
    }

    private function actor(string $who): User
    {
        if (isset(self::Agents[$who])) {
            $user = $this->agents[$who]->user;

            if (! $user instanceof User) {
                throw new LogicException("Agent {$who} has no user.");
            }

            Auth::setUser($user);

            return $user;
        }

        return $this->context->as($who === 'manager' ? 'support_manager' : $who);
    }

    private function customer(string $number): CustomerProfile
    {
        return CustomerProfile::query()->where('customer_code', "DEMO-CUST-{$number}")->firstOrFail();
    }

    private function seedAgents(DemoContext $context): void
    {
        $context->at('2026-09-04 08:20');
        $admin = $context->as('admin');

        foreach (self::Agents as $key => $agent) {
            $profile = EmployeeProfile::withTrashed()->where('employee_code', $agent['code'])->first();

            if (! $profile instanceof EmployeeProfile) {
                $user = User::query()->create([
                    'name' => $agent['name'],
                    'email' => $agent['email'],
                    'password' => Hash::make(DemoContext::Password),
                    'user_type' => UserType::Admin,
                ]);
                $user->assignRole(DashboardRole::SupportAgent->value);

                $profile = EmployeeProfile::query()->create([
                    'user_id' => $user->getKey(),
                    'employee_code' => $agent['code'],
                    'job_title' => $agent['title'],
                    'phone' => $agent['phone'],
                    'email' => $agent['email'],
                    'is_active' => true,
                    'use_base_salary' => true,
                    'base_salary' => $agent['base'],
                    'salary_calculation_mode' => SalaryCalculationMode::BasePlusPerformance,
                    'default_hourly_rate_minor' => $agent['rate'],
                ]);

                activity()->performedOn($profile)->causedBy($admin)
                    ->withChanges(['attributes' => $profile->getAttributes()])
                    ->withProperties(['source_channel' => 'dashboard'])
                    ->log('employee.created');
            }

            $this->agents[$key] = $profile->loadMissing('user');
        }
    }

    /** One customer has switched off ticket e-mails: their mail deliveries are recorded as Suppressed. */
    private function planPreferences(): void
    {
        $this->on('2026-09-04 08:40', function (): void {
            $user = $this->customer('011')->user;

            if ($user instanceof User) {
                NotificationPreference::query()->firstOrCreate(
                    ['user_id' => $user->getKey(), 'template_key' => 'ticket.updated', 'channel' => NotificationChannel::Mail],
                    ['enabled' => false],
                );
            }
        });
    }

    // ------------------------------------------------------------ ticket helpers

    private function intake(string $key, string $at, string $customer, TicketType $type, TicketPriority $priority, string $title, string $description, ?TicketCustomerImpact $impact = null): void
    {
        $this->on($at, function () use ($key, $customer, $type, $priority, $title, $description, $impact): void {
            $this->tickets[$key] = app(TicketIntakeService::class)->create([
                'customer_id' => $this->customer($customer)->getKey(),
                'type' => $type->value,
                'priority' => $priority->value,
                'title' => $title,
                'description' => $description,
                'customer_impact' => $impact?->value,
            ], $this->actor('manager'));
        });
    }

    private function triage(string $key, string $at, string $equipment, TicketServicePath $path, ?string $serial = null, ?float $fee = null): void
    {
        $this->on($at, function () use ($key, $equipment, $path, $serial, $fee): void {
            $data = [
                'equipment_source' => TicketEquipmentSource::External->value,
                'external_equipment_name' => $equipment,
                'external_serial_number' => $serial,
                'service_path' => $path->value,
                'diagnostic_fee_required' => $fee !== null,
            ];

            if ($fee !== null) {
                $data['diagnostic_fee_amount'] = $fee;
                $data['diagnostic_fee_currency'] = 'AED';
            }

            $this->tickets[$key] = app(TicketTriageService::class)->triage($this->tickets[$key]->refresh(), $data, $this->actor('manager'));
        });
    }

    private function assign(string $key, string $at, string $agent): void
    {
        $this->on($at, function () use ($key, $agent): void {
            app(TicketLifecycleService::class)->assign($this->tickets[$key]->refresh(), $this->agents[$agent], $this->actor('manager'));
        });
    }

    private function say(string $key, string $at, string $who, string $text, bool $internal = false): void
    {
        $this->on($at, function () use ($key, $who, $text, $internal): void {
            app(TicketMessageService::class)->post($this->tickets[$key]->refresh(), $text, $internal, $this->actor($who));
        });
    }

    private function move(string $key, string $at, string $who, TicketStatus $to, ?string $note = null): void
    {
        $this->on($at, function () use ($key, $who, $to, $note): void {
            app(TicketLifecycleService::class)->transition($this->tickets[$key]->refresh(), $to, $this->actor($who), $note);
        });
    }

    // -------------------------------------------------------- maintenance helpers

    /** @param array<string, mixed> $extra standalone equipment data */
    private function record(string $key, string $at, ?string $ticketKey, string $description, array $extra = []): void
    {
        $this->on($at, function () use ($key, $ticketKey, $description, $extra): void {
            $manager = $this->actor('manager');
            $service = app(MaintenanceRecordService::class);

            $this->records[$key] = $ticketKey === null
                ? $service->createStandalone(['description' => $description, ...$extra], $manager)
                : $service->createFromTicket($this->tickets[$ticketKey]->refresh(), ['description' => $description], $manager);
        });
    }

    private function recordMove(string $key, string $at, string $who, MaintenanceStatus $to, string $note): void
    {
        $this->on($at, function () use ($key, $who, $to, $note): void {
            app(MaintenanceRecordService::class)->transition($this->records[$key]->refresh(), $to, $this->actor($who), $note);
        });
    }

    private function task(string $key, string $recordKey, string $at, string $title, string $description, string $agent, string $due): void
    {
        $this->on($at, function () use ($key, $recordKey, $title, $description, $agent, $due): void {
            $this->tasks[$key] = app(ServiceRecordService::class)->create($this->records[$recordKey]->refresh(), [
                'title' => $title,
                'description' => $description,
                'employee_id' => $this->agents[$agent]->getKey(),
                'due_at' => Carbon::parse(mb_strlen($due) === 10 ? $due.' 17:00' : $due),
            ], $this->actor('manager'));
        });
    }

    private function taskMove(string $key, string $at, string $who, MaintenanceStatus $to, ?string $note = null, ?string $work = null): void
    {
        $this->on($at, function () use ($key, $who, $to, $note, $work): void {
            app(ServiceRecordService::class)->transition($this->tasks[$key]->refresh(), $to, $this->actor($who), $note, $work);
        });
    }

    private function diagnose(string $key, string $at, string $summary, string $rootCause, WarrantyFailureCategory $category): void
    {
        $this->on($at, function () use ($key, $summary, $rootCause, $category): void {
            $this->records[$key] = app(WarrantyClaimService::class)->recordDiagnosis($this->records[$key]->refresh(), [
                'diagnosis_summary' => $summary,
                'root_cause' => $rootCause,
                'failure_category' => $category->value,
            ], $this->actor('manager'));
        });
    }

    /** @param list<array{0: WarrantyLineCategory, 1: string, 2: int, 3?: float}> $lines */
    private function coverage(string $key, string $at, WarrantyClaimDecision $decision, string $reason, ?string $explanation, array $lines): void
    {
        $this->on($at, function () use ($key, $decision, $reason, $explanation, $lines): void {
            $this->records[$key] = app(WarrantyClaimService::class)->decideCoverage($this->records[$key]->refresh(), [
                'coverage_decision' => $decision->value,
                'coverage_reason' => $reason,
                'customer_coverage_explanation' => $explanation,
                'coverage_lines' => array_map(fn (array $line): array => [
                    'category' => $line[0]->value,
                    'description' => $line[1],
                    'amount_minor' => $line[2],
                    'coverage_percent' => $line[3] ?? 100.0,
                ], $lines),
            ], $this->actor('manager'));
        });
    }

    private function labour(string $recordKey, string $taskKey, string $at, string $agent, int $minutes, string $note): void
    {
        $this->on($at, function () use ($recordKey, $taskKey, $agent, $minutes, $note): void {
            $user = $this->actor('manager');
            $agentUser = $this->agents[$agent]->user;

            app(MaintenanceCostService::class)->recordLabour(new LabourEntryData(
                maintenanceRecordId: (int) $this->records[$recordKey]->getKey(),
                serviceRecordId: (int) $this->tasks[$taskKey]->getKey(),
                employeeId: (int) $agentUser?->getKey(),
                performedOn: now()->toDateString(),
                minutes: $minutes,
                notes: $note,
            ), $user);
        });
    }

    private function consume(string $partKey, string $taskKey, string $at, string $agent, float $quantity): void
    {
        $this->on($at, function () use ($partKey, $taskKey, $agent, $quantity): void {
            $variant = ProductVariant::query()->where('sku', DemoMasterDataSeeder::sku('P020', 'BASIC'))->firstOrFail();
            $warehouse = Warehouse::query()->where('code', 'WH-REPAIR')->firstOrFail();
            $lot = app(InventoryLotService::class)->availableLots((int) $variant->getKey(), (int) $warehouse->getKey())->first();

            if (! $lot instanceof InventoryLot) {
                throw new LogicException('Repair bench has no usable maintenance kit lot; run DemoInventoryOpeningSeeder first.');
            }

            $this->parts[$partKey] = app(ServiceRecordPartService::class)->consume(
                $this->tasks[$taskKey]->refresh(),
                (int) $variant->getKey(),
                (int) $warehouse->getKey(),
                $quantity,
                $this->actor($agent),
                (int) $lot->getKey(),
            );
        });
    }

    // --------------------------------------------------------------- the stories

    /** Maintenance request raised from a ticket, warranty-covered, repaired with parts, closed and settled. */
    private function planClosedMaintenanceTicket(): void
    {
        $this->intake('T13', '2026-09-08 09:15', '001', TicketType::MaintenanceRequest, TicketPriority::High, self::MarkerTitle, 'The handpiece slows down and loses torque under load during implant drilling. Two procedures were delayed this week.', TicketCustomerImpact::Degraded);
        $this->triage('T13', '2026-09-08 09:40', 'Surgical Drill Handpiece', TicketServicePath::Maintenance, 'EXT-HP-77201');
        $this->assign('T13', '2026-09-08 10:30', 'agent1');
        $this->say('T13', '2026-09-08 11:00', 'agent1', 'Thanks for reporting this. We will collect the handpiece for bench diagnosis tomorrow morning.');
        $this->move('T13', '2026-09-08 14:00', 'agent1', TicketStatus::InProgress);
        $this->record('M1', '2026-09-08 14:30', 'T13', 'Handpiece loses torque under sustained load; unit in warranty according to the delivery note.');
        $this->on('2026-09-08 14:40', function (): void {
            app(MaintenanceRecordService::class)->overrideWarranty(
                $this->records['M1']->refresh(),
                WarrantyStatus::Covered,
                Carbon::parse('2027-03-31'),
                'Delivery note and warranty card verified: sold June 2026 with a 12 month seller warranty.',
                $this->actor('manager'),
            );
        });
        $this->diagnose('M1', '2026-09-08 15:00', 'Planetary gear assembly worn; torque sensor reads within tolerance.', 'Premature gear wear under high duty cycle.', WarrantyFailureCategory::ManufacturingDefect);
        $this->coverage('M1', '2026-09-08 15:30', WarrantyClaimDecision::FullyCovered, 'Failure inside the warranty period and consistent with a manufacturing defect.', null, [
            [WarrantyLineCategory::Part, 'Gear assembly replacement', 42000],
            [WarrantyLineCategory::Labour, 'Bench labour', 18000],
        ]);
        $this->task('M1-repair', 'M1', '2026-09-08 15:45', 'Replace gear assembly and recalibrate', 'Replace the worn gear assembly, lubricate and run a 30 minute load test.', 'agent1', '2026-09-12');
        $this->task('M1-pickup', 'M1', '2026-09-08 15:50', 'Pick up and return handpiece on site', 'Courier pickup and return handled by the support technician.', 'agent1', '2026-09-11');
        $this->recordMove('M1', '2026-09-08 16:00', 'manager', MaintenanceStatus::InProgress, 'Repair approved under warranty.');
        $this->taskMove('M1-repair', '2026-09-08 16:10', 'agent1', MaintenanceStatus::InProgress);
        $this->consume('M1-kit', 'M1-repair', '2026-09-08 16:30', 'agent1', 2.0);
        $this->consume('M1-kit-mistake', 'M1-repair', '2026-09-08 17:15', 'agent1', 1.0);
        $this->on('2026-09-09 08:00', function (): void {
            app(ServiceRecordPartService::class)->reverse($this->parts['M1-kit-mistake']->refresh(), $this->actor('admin'));
        });
        $this->labour('M1', 'M1-repair', '2026-09-08 17:45', 'agent1', 90, 'Bench repair and calibration.');
        $this->taskMove('M1-repair', '2026-09-09 08:15', 'agent1', MaintenanceStatus::Closed, 'Calibration test passed.', 'Replaced gear assembly, lubricated, load tested for 30 minutes.');
        $this->taskMove('M1-pickup', '2026-09-09 08:20', 'agent1', MaintenanceStatus::Cancelled, 'Customer brought the unit to the bench themselves.');
        $this->recordMove('M1', '2026-09-09 08:30', 'manager', MaintenanceStatus::QualityAssurance, 'Final inspection.');
        $this->recordMove('M1', '2026-09-09 08:45', 'manager', MaintenanceStatus::Closed, 'Passed quality assurance.');
        $this->on('2026-09-09 09:00', function (): void {
            $this->records['M1'] = app(MaintenanceBillingService::class)->settleCoverage($this->records['M1']->refresh(), $this->actor('manager'), 'Covered in full under the seller warranty.');
        });
        $this->move('T13', '2026-09-09 09:10', 'agent1', TicketStatus::Resolved, 'Replaced the worn gear assembly under warranty and completed load testing.');
        $this->move('T13', '2026-09-12 10:00', 'manager', TicketStatus::Closed);
    }

    /** A diagnostic fee the customer declined: pending payment, then cancelled with the payment link. */
    private function planCancelledTicket(): void
    {
        $this->intake('T14', '2026-09-12 15:00', '010', TicketType::HardwareIssue, TicketPriority::High, 'Emergency same-day repair visit requested', 'Intraoral camera stopped working before the afternoon patient list; the clinic asked for a same-day on-site visit.', TicketCustomerImpact::ServiceUnavailable);
        $this->triage('T14', '2026-09-12 15:20', 'Intraoral Camera', TicketServicePath::OnSiteVisit, null, 400.0);
        $this->move('T14', '2026-09-13 09:00', 'manager', TicketStatus::Cancelled, 'Customer declined the emergency call-out fee.');
    }

    /** Diagnostic fee paid by bank transfer (manual settlement), then worked remotely and closed. */
    private function planPaidTicket(): void
    {
        $this->intake('T04', '2026-09-14 08:50', '005', TicketType::HardwareIssue, TicketPriority::High, 'Autoclave controller shows error E21', 'The autoclave controller stops every cycle with error E21 after the pressure test.', TicketCustomerImpact::ServiceUnavailable);
        $this->triage('T04', '2026-09-14 09:10', 'Autoclave Controller', TicketServicePath::RemoteSupport, null, 150.0);
        $this->on('2026-09-15 10:20', function (): void {
            $ticket = $this->tickets['T04']->refresh();
            $method = PaymentMethod::query()->where('name', 'Customer Bank Transfer')->firstOrFail();

            app(TicketPaymentService::class)->settle($ticket->paymentLink()->firstOrFail(), 'BT-20260915-0457', $this->actor('admin'), (int) $method->getKey());
        });
        $this->assign('T04', '2026-09-15 10:45', 'agent2');
        $this->say('T04', '2026-09-15 11:30', 'agent2', 'Payment received, thank you. Please keep the controller powered on; we will connect remotely at 14:00.');
        $this->move('T04', '2026-09-15 11:35', 'agent2', TicketStatus::InProgress);
        $this->move('T04', '2026-09-16 15:00', 'agent2', TicketStatus::Resolved, 'Re-flashed the controller firmware and guided the replacement of the pressure sensor cable.');
        $this->move('T04', '2026-09-19 09:00', 'manager', TicketStatus::Closed);
    }

    /** Urgent ticket worked for weeks: the resolution deadline passes and the SLA sweep flags it. */
    private function planUrgentBreachTicket(): void
    {
        $this->intake('T10', '2026-09-18 08:30', '002', TicketType::HardwareIssue, TicketPriority::Urgent, 'Surgical motor stopped before the morning surgery list', 'The surgical motor unit seized during a procedure. The clinic has surgeries booked for the next days.', TicketCustomerImpact::ServiceUnavailable);
        $this->triage('T10', '2026-09-18 08:45', 'Surgical Motor Unit', TicketServicePath::Maintenance, 'EXT-MT-40318');
        $this->assign('T10', '2026-09-18 08:50', 'agent1');
        $this->say('T10', '2026-09-18 09:10', 'agent1', 'We are on it. The unit will be collected within the hour for bench diagnosis.');
        $this->move('T10', '2026-09-18 09:15', 'agent1', TicketStatus::InProgress);
        $this->record('M2', '2026-09-18 09:30', 'T10', 'Surgical motor seized mid-procedure; unit out of service until repaired.');
        $this->task('M2-diag', 'M2', '2026-09-18 09:40', 'Bench diagnosis of the seized motor', 'Open the motor housing, inspect bearings and windings.', 'agent1', '2026-09-18');
        $this->taskMove('M2-diag', '2026-09-18 09:50', 'agent1', MaintenanceStatus::InProgress);
        $this->labour('M2', 'M2-diag', '2026-09-18 11:30', 'agent1', 90, 'Strip down and diagnosis.');
        $this->taskMove('M2-diag', '2026-09-18 11:40', 'agent1', MaintenanceStatus::Closed, 'Rotor bearing failed; replacement motor required.', 'Disassembled the motor and confirmed a failed rotor bearing.');
        $this->task('M2-repair', 'M2', '2026-09-18 12:00', 'Replace motor and test under load', 'Fit the replacement motor when it arrives and verify torque curves.', 'agent1', '2026-09-30');
        $this->taskMove('M2-repair', '2026-09-24 09:00', 'agent1', MaintenanceStatus::InProgress);
        $this->on('2026-09-19 09:00', function (): void {
            app(MaintenanceCostService::class)->recordThirdPartyCost(new ThirdPartyCostData(
                maintenanceRecordId: (int) $this->records['M2']->getKey(),
                description: 'Express courier for the replacement motor',
                amountMinor: 12500,
                incurredOn: '2026-09-19',
            ), $this->actor('manager'));
        });
        $this->say('T10', '2026-09-25 10:00', 'agent1', 'The replacement motor is delayed at customs; expected on site next week. Loan unit offered to the clinic.', true);
    }

    private function planResolvedTickets(): void
    {
        $this->intake('T12a', '2026-09-21 13:00', '007', TicketType::GeneralSupport, TicketPriority::Low, 'How to calibrate print settings for the new model resin', 'The lab wants the recommended exposure and layer settings for the new model resin batch.', TicketCustomerImpact::GeneralQuestion);
        $this->triage('T12a', '2026-09-21 13:20', 'Resin Printer', TicketServicePath::RemoteSupport);
        $this->assign('T12a', '2026-09-21 13:40', 'agent2');
        $this->say('T12a', '2026-09-21 15:00', 'agent2', 'Sending the datasheet with the recommended settings now.');
        $this->move('T12a', '2026-09-21 15:05', 'agent2', TicketStatus::InProgress);
        $this->move('T12a', '2026-09-23 10:00', 'agent2', TicketStatus::Resolved, 'Shared the exposure and layer settings datasheet; the lab confirmed good test prints.');

        $this->intake('T12b', '2026-09-26 10:00', '004', TicketType::MaintenanceRequest, TicketPriority::Normal, 'Routine maintenance guidance for the suction unit', 'The clinic asked how often the suction unit filters need replacing and for a maintenance schedule.', TicketCustomerImpact::GeneralQuestion);
        $this->triage('T12b', '2026-09-26 10:20', 'Dental Suction Unit', TicketServicePath::RemoteSupport);
        $this->assign('T12b', '2026-09-26 10:30', 'agent2');
        $this->say('T12b', '2026-09-26 11:00', 'agent2', 'We will send the manufacturer maintenance schedule and filter guide today.');
        $this->move('T12b', '2026-09-26 11:05', 'agent2', TicketStatus::InProgress);
        $this->move('T12b', '2026-09-28 09:45', 'agent2', TicketStatus::Resolved, 'Shared the maintenance schedule and filter replacement guide; the unit is running normally.');
    }

    /** Diagnostic fee paid through the offline Stripe test client; ticket stays in progress with a maintenance request in QA. */
    private function planStripeTicket(): void
    {
        $this->intake('T05', '2026-09-22 09:00', '008', TicketType::HardwareIssue, TicketPriority::Normal, 'Dental compressor shuts down intermittently', 'The compressor trips off several times a day and the chairs lose air pressure.', TicketCustomerImpact::Degraded);
        $this->triage('T05', '2026-09-22 09:20', 'Dental Compressor', TicketServicePath::OnSiteVisit, 'EXT-CP-88120', 200.0);
        $this->on('2026-09-22 11:00', fn () => $this->settleWithStripe());
        $this->assign('T05', '2026-09-22 11:30', 'agent1');
        $this->say('T05', '2026-09-22 12:00', 'agent1', 'Card payment confirmed. We will visit the clinic tomorrow morning for the on-site inspection.');
        $this->move('T05', '2026-09-22 12:05', 'agent1', TicketStatus::InProgress);
        $this->record('M3', '2026-09-23 08:30', 'T05', 'Compressor trips on thermal overload; on-site inspection and pressure test required.');
        $this->task('M3-inspect', 'M3', '2026-09-23 08:40', 'On-site inspection and pressure test', 'Inspect motor, capacitor and pressure switch; run a 2 hour pressure test.', 'agent1', '2026-09-24');
        $this->taskMove('M3-inspect', '2026-09-23 09:30', 'agent1', MaintenanceStatus::InProgress);
        $this->taskMove('M3-inspect', '2026-09-24 11:30', 'agent1', MaintenanceStatus::QualityAssurance, 'Capacitor replaced; pressure test running overnight.');
        $this->recordMove('M3', '2026-09-24 11:35', 'manager', MaintenanceStatus::QualityAssurance, 'Awaiting overnight pressure test result.');
    }

    private function settleWithStripe(): void
    {
        $client = app(StripeClientInterface::class);
        $ticket = $this->tickets['T05']->refresh();
        $link = $ticket->paymentLink()->firstOrFail();

        if (! $client instanceof FakeStripeClient) {
            // Stripe is live in this environment: never touch it, settle manually instead.
            app(TicketPaymentService::class)->settle($link, 'CARD-20260922-0116', $this->actor('admin'));

            return;
        }

        // Deterministic fake provider ids and idempotency keys (the fake client draws random strings).
        $counter = 0;
        Str::createRandomStringsUsing(function (int $length) use (&$counter): string {
            return mb_str_pad((string) ++$counter, $length, '0', STR_PAD_LEFT);
        });
        Str::createUuidsUsing(fn (): UuidInterface => Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', ++$counter)));

        try {
            $transaction = app(StripeCheckoutService::class)->createForTicket(
                $ticket->customer ?? $this->customer('008'),
                $link,
                'https://demo.ierp.test/support/payment/success',
                'https://demo.ierp.test/support/payment/cancel',
            );
            $client->markSucceeded((string) $transaction->payment_intent_id);
            $transaction = app(StripePaymentReconciliationService::class)->reconcile($transaction->refresh());
            app(TicketProviderSettlementService::class)->settle($transaction->refresh());
        } finally {
            Str::createRandomStringsNormally();
            Str::createUuidsNormally();
        }
    }

    private function planWaitingTicket(): void
    {
        $this->intake('T11', '2026-10-02 08:30', '012', TicketType::SoftwareIssue, TicketPriority::Normal, 'Design software export fails with error 114', 'Exporting a finished design to STL stops with error 114 on the clinic workstation.', TicketCustomerImpact::Degraded);
        $this->triage('T11', '2026-10-02 08:45', 'Design Workstation', TicketServicePath::RemoteSupport);
        $this->assign('T11', '2026-10-02 09:00', 'agent2');
        $this->say('T11', '2026-10-02 09:15', 'agent2', 'We have seen this error after the latest update. Connecting remotely this morning.');
        $this->move('T11', '2026-10-02 09:20', 'agent2', TicketStatus::InProgress);
        $this->say('T11', '2026-10-02 14:00', 'agent2', 'Please send us the application log file from the export folder so we can pinpoint the failing step.');
        $this->move('T11', '2026-10-02 14:05', 'agent2', TicketStatus::WaitingCustomer, 'Waiting for the application log from the clinic.');
    }

    /** Standalone maintenance requests: awaiting approval, ready for repair (goodwill), a preventive request and a cancelled one. */
    private function planMaintenanceStandalone(): void
    {
        // Cancelled before any work started.
        $this->record('M8', '2026-09-20 10:00', null, 'Intraoral scanner calibration requested by the clinic.', [
            'customer_id' => $this->customer('009')->getKey(),
            'serial_number' => 'EXT-SC-20977',
            'warranty_status' => WarrantyStatus::Unknown->value,
        ]);
        $this->recordMove('M8', '2026-09-22 09:30', 'manager', MaintenanceStatus::Cancelled, 'Customer withdrew the request; the scanner was replaced.');

        // Partial warranty cover: waiting for the customer to approve their share.
        $this->record('M5', '2026-09-24 10:00', null, 'Autoclave door sensor fails intermittently and aborts cycles.', [
            'customer_id' => $this->customer('012')->getKey(),
            'serial_number' => 'EXT-AC-3310',
            'warranty_status' => WarrantyStatus::Covered->value,
            'warranty_expiry_date' => '2027-01-31',
        ]);
        $this->diagnose('M5', '2026-09-25 11:00', 'Door sensor contacts corroded; heating element has minor scale build-up.', 'Sensor contact corrosion from steam exposure.', WarrantyFailureCategory::NormalComponentFailure);
        $this->coverage('M5', '2026-09-25 11:30', WarrantyClaimDecision::PartiallyCovered, 'Sensor is covered; scale build-up is a maintenance responsibility.', 'The door sensor is covered by warranty. Descaling the heating element is a customer maintenance cost.', [
            [WarrantyLineCategory::Part, 'Door sensor replacement', 36000, 100.0],
            [WarrantyLineCategory::Labour, 'Descale heating element and refit', 24000, 0.0],
        ]);
        $this->task('M5-sensor', 'M5', '2026-09-25 12:00', 'Replace door sensor and descale', 'Replace the sensor and descale once the customer approves.', 'agent2', '2026-10-06');

        // Goodwill repair approved, ready to start.
        $this->record('M6', '2026-09-26 09:30', null, 'Foot pedal responds intermittently on the surgical drill.', [
            'customer_id' => $this->customer('003')->getKey(),
            'serial_number' => 'EXT-FP-55120',
            'warranty_status' => WarrantyStatus::Expired->value,
            'warranty_expiry_date' => '2026-05-31',
        ]);
        $this->diagnose('M6', '2026-09-26 14:00', 'Pedal switch contacts worn; cable strain relief cracked.', 'Normal wear on the switch contacts.', WarrantyFailureCategory::NormalComponentFailure);
        $this->coverage('M6', '2026-09-26 14:30', WarrantyClaimDecision::Goodwill, 'Warranty expired four months ago; first repair offered as goodwill to a long-standing customer.', null, [
            [WarrantyLineCategory::Part, 'Foot pedal assembly', 24000],
            [WarrantyLineCategory::Labour, 'Replacement labour', 8000],
        ]);
        $this->task('M6-pedal', 'M6', '2026-09-26 15:00', 'Replace foot pedal assembly', 'Fit the new pedal assembly and test with the handpiece.', 'agent2', '2026-10-05');

        // Preventive maintenance: raised by a schedule when a delivered unit exists, otherwise opened manually.
        if ($this->unit instanceof SerializedInventoryUnit) {
            $this->on('2026-09-10 09:00', function (): void {
                $unit = $this->unit;
                if (! $unit instanceof SerializedInventoryUnit) {
                    return;
                }

                app(MaintenanceScheduleService::class)->create(new MaintenanceScheduleData(
                    serializedInventoryUnitId: (int) $unit->getKey(),
                    customerId: (int) $unit->custody_reference_id,
                    name: 'Annual preventive service',
                    intervalType: MaintenanceIntervalType::Months,
                    intervalValue: 12,
                    leadTimeDays: 21,
                    firstDueOn: '2026-09-25',
                    billingType: MaintenanceBillingType::WarrantyCovered,
                ), $this->actor('manager'));
            });
            $this->on('2026-09-11 08:00', function (): void {
                Artisan::call('maintenance:schedules:generate');
                $occurrence = MaintenanceScheduleOccurrence::query()->where('status', OccurrenceStatus::Raised->value)->orderByDesc('id')->first();

                if (! $occurrence instanceof MaintenanceScheduleOccurrence) {
                    throw new LogicException('The preventive schedule raised no occurrence on 2026-09-11.');
                }

                $this->records['M7'] = MaintenanceRecord::query()->findOrFail($occurrence->maintenance_record_id);
            });
        } else {
            $this->record('M7', '2026-09-10 09:00', null, 'Annual preventive maintenance of the clinic compressor.', [
                'customer_id' => $this->customer('014')->getKey(),
                'serial_number' => 'EXT-CP-10077',
                'warranty_status' => WarrantyStatus::NotCovered->value,
            ]);
        }
        $this->task('M7-service', 'M7', '2026-09-27 09:00', 'Preventive service visit', 'Replace filters, check pressure switch and lubricate moving parts.', 'agent2', '2026-10-08');
    }

    private function planPendingPaymentTickets(): void
    {
        $this->intake('T03', '2026-09-30 11:00', '011', TicketType::SoftwareIssue, TicketPriority::Normal, 'Intraoral scanner software will not calibrate', 'The scanner calibration wizard fails at the colour step; scans are unusable.', TicketCustomerImpact::Degraded);
        $this->triage('T03', '2026-09-30 11:15', 'Intraoral Scanner', TicketServicePath::RemoteSupport, null, 150.0);

        $this->intake('T02', '2026-10-01 09:30', '003', TicketType::HardwareIssue, TicketPriority::High, 'Surgical drill motor overheating', 'The drill motor becomes too hot after ten minutes of use; the surgeons stopped using it.', TicketCustomerImpact::Degraded);
        $this->triage('T02', '2026-10-01 09:50', 'Surgical Drill Motor', TicketServicePath::OnSiteVisit, 'EXT-MT-51207', 250.0);
    }

    /** Tickets of the last days: unassigned, assigned but unanswered, untriaged. */
    private function planLateTickets(): void
    {
        $this->intake('T08', '2026-09-30 14:00', '006', TicketType::HardwareIssue, TicketPriority::High, 'Steam sterilizer door gasket leaking', 'Steam escapes around the door during cycles and the chamber no longer reaches full pressure.', TicketCustomerImpact::Degraded);
        $this->triage('T08', '2026-09-30 14:20', 'Steam Sterilizer', TicketServicePath::Maintenance, 'EXT-ST-5521');
        $this->assign('T08', '2026-10-01 09:00', 'agent1');
        $this->record('M4', '2026-10-01 09:30', 'T08', 'Door gasket leak on the steam sterilizer; chamber pressure drops during cycles.');
        $this->task('M4-inspect', 'M4', '2026-10-01 09:40', 'Initial gasket and door seal inspection', 'Inspect the gasket, hinge alignment and chamber seal.', 'agent1', '2026-10-05');
        $this->diagnose('M4', '2026-10-02 11:00', 'Gasket hardened and cracked along the lower edge; hinge alignment within tolerance.', 'Age-related gasket degradation.', WarrantyFailureCategory::NormalComponentFailure);

        $this->intake('T06', '2026-10-01 11:00', '013', TicketType::GeneralSupport, TicketPriority::Low, 'Staff training on the customer ordering portal', 'The clinic wants a short remote session for new staff on placing orders and downloading invoices.', TicketCustomerImpact::GeneralQuestion);
        $this->triage('T06', '2026-10-01 11:20', 'Customer Portal', TicketServicePath::RemoteSupport);
        $this->say('T06', '2026-10-01 15:00', 'manager', 'We can offer a 45 minute remote session next week. Please suggest two time slots.');

        $this->intake('T09', '2026-10-02 09:00', '010', TicketType::SoftwareIssue, TicketPriority::Normal, 'Barcode scanner no longer syncs with the portal', 'The handheld barcode scanner stopped sending counts to the ordering portal since yesterday.', TicketCustomerImpact::Degraded);
        $this->triage('T09', '2026-10-02 09:15', 'Handheld Barcode Scanner', TicketServicePath::RemoteSupport);
        $this->assign('T09', '2026-10-02 09:30', 'agent2');
        $this->say('T09', '2026-10-02 10:00', 'agent2', 'Thanks, we are reproducing the sync problem and will update you shortly.');

        $this->intake('T01', '2026-10-02 16:00', '009', TicketType::SoftwareIssue, TicketPriority::Normal, 'Portal login error when downloading invoices', 'Downloading an invoice PDF redirects to the login page even though the session is active.', TicketCustomerImpact::Degraded);

        $this->intake('T07', '2026-10-03 09:30', '001', TicketType::HardwareIssue, TicketPriority::Urgent, 'Sterilizer not reaching temperature before morning surgeries', 'The sterilizer stops at 105 C and cannot complete a cycle. Instruments for the afternoon list are not ready.', TicketCustomerImpact::ServiceUnavailable);
        $this->triage('T07', '2026-10-03 09:45', 'Steam Sterilizer', TicketServicePath::OnSiteVisit, 'EXT-ST-7710');
    }

    /** The production scheduler sweeps SLA breaches every few minutes; replay it at the end of each working day. */
    private function planSlaSweeps(): void
    {
        $days = [];

        foreach ($this->events as [$at]) {
            $days[mb_substr($at, 0, 10)] = true;
        }

        $days['2026-10-03'] = true;

        foreach (array_keys($days) as $day) {
            $this->on($day === '2026-10-03' ? "{$day} 17:55" : "{$day} 18:30", function (): void {
                Artisan::call('support:sla:reconcile');
            });
        }
    }
}
