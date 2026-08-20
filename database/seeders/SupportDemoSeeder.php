<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DashboardRole;
use App\Enums\MaintenanceStatus;
use App\Enums\SalaryCalculationMode;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\UserType;
use App\Enums\WarrantyStatus;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryReceipt;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReceivingService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use App\Services\Support\SlaService;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketMessageService;
use App\Services\Support\TicketPaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Gives the Support and Maintenance module (Tickets, Maintenance Requests,
 * Service Records, SLA Policies, reports) at least one believable record in
 * every status/permission state Filament can show, so a client walkthrough
 * never lands on an empty state — exactly like {@see EmployeeDemoSeeder} and
 * {@see InventoryDemoSeeder}, whose customers, warehouses, and serialized
 * equipment this seeder reuses rather than duplicating.
 *
 * Runs every mutation through the same domain services Filament's own
 * actions call (`TicketIntakeService`, `TicketLifecycleService`,
 * `MaintenanceRecordService`, `ServiceRecordService`,
 * `ServiceRecordPartService`), so ticket numbers, SLA snapshots, audit rows,
 * and stock movements all stay internally consistent. The only places raw
 * `forceFill()` is used are to backdate a clock or a due date into the past
 * for a "this is already overdue" demo state — the same technique
 * {@see EmployeeDemoSeeder::completeTaskAt()} uses for completed-task
 * timestamps — never to fabricate a status a service wouldn't produce.
 *
 * Idempotent as a whole: the first line checks whether the flagship ticket
 * already exists and returns immediately if so, since this seeder's records
 * cross-reference each other (a continuation ticket, a ticket-raised
 * maintenance request) and re-running only part of it would leave dangling
 * references.
 */
final class SupportDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SupportPermissionSeeder::class, SlaPolicySeeder::class]);

        if (Ticket::query()->where('title', 'Form 4B — print engine grinding noise mid-print')->exists()) {
            return;
        }

        $admin = $this->demoAdmin();
        $manager = $this->dashboardUser('support.manager@ierp.com', 'Yasmin Zubaidi', DashboardRole::SupportManager);
        $this->dashboardUser('audit.reviewer@ierp.com', 'Dina Qasem', DashboardRole::Reviewer);
        $fadi = $this->supportAgent('support.agent.fadi@ierp.com', 'Fadi Haddad', 'Field Service Technician', '+971 50 555 0201', 3600.00, 'SPT-0001');
        $rasha = $this->supportAgent('support.agent.rasha@ierp.com', 'Rasha Odeh', 'Field Service Technician', '+971 50 555 0202', 3400.00, 'SPT-0002');

        $smile = CustomerProfile::query()->where('email', 'smile-dental-clinic@ierp.com')->firstOrFail();
        $bright = CustomerProfile::query()->where('email', 'bright-orthodontics@ierp.com')->firstOrFail();

        $bench = Warehouse::query()->where('code', 'BENCH')->firstOrFail();
        $resin = ProductVariant::query()->where('sku', 'FORMLABS-PRECISION-MODEL-1L')->firstOrFail();
        $this->stockRepairBenchSpares($bench, $resin, $admin);

        $this->seedUntriagedTicket($smile, $manager);
        $this->seedUnsettledChargeableTicket($bright, $manager);
        $this->seedSettledInProgressTicket($bright, $fadi, $manager, $admin);
        $this->seedUnassignedLiveTicket($smile, $manager);
        $this->seedAssignedNotStartedTicket($bright, $manager, $rasha);
        $this->seedPausedResumedTicket($smile, $manager, $fadi);
        $this->seedBreachedTicket($bright, $manager, $rasha);
        $this->seedResolvedTicket($smile, $manager, $fadi);

        $flagshipTicket = $this->seedFlagshipMaintenanceTicket($bright, $manager, $fadi, $admin, $bench, $resin);
        $this->seedContinuationTicket($flagshipTicket, $bright, $manager);
        $this->seedCancelledChargeableTicket($smile, $manager);
        $this->seedArchivedDuplicateTicket($smile, $manager);

        $this->seedUnlinkedEquipmentRequest($smile, $manager);
        $this->seedCrossAgentMaintenanceRequest($bright, $manager, $fadi, $rasha);
    }

    private function demoAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ierp.com')->first();

        if (! $admin instanceof User) {
            $admin = User::query()->firstOrCreate(
                ['user_type' => UserType::Admin],
                ['name' => 'Admin User', 'email' => 'admin@ierp.com', 'password' => Hash::make('password')],
            );
        }

        if (! $admin->hasRole(DashboardRole::SystemAdmin->value)) {
            $admin->assignRole(DashboardRole::SystemAdmin->value);
        }

        return $admin->refresh();
    }

    private function dashboardUser(string $email, string $name, DashboardRole $role): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'user_type' => UserType::Admin],
        );

        if (! $user->hasRole($role->value)) {
            $user->assignRole($role->value);
        }

        return $user;
    }

    /**
     * A Support Agent is both a dashboard user (needs `/admin` access and
     * the fixed role for permission checks) and an {@see EmployeeProfile}
     * (needed so `assigned_employee_id`/`employee_id` ownership checks —
     * FR-075, FR-081's own-record consumption — resolve against a real
     * record). `EmployeeOnboardingService::onboard()` can't be reused here
     * because it always creates `UserType::Employee`, which
     * `User::canAccessPanel()` denies `/admin` to.
     */
    private function supportAgent(string $email, string $name, string $jobTitle, string $phone, float $baseSalary, string $employeeCode): EmployeeProfile
    {
        $existing = EmployeeProfile::withTrashed()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('email', $email))
            ->first();

        if ($existing instanceof EmployeeProfile) {
            return $existing;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'user_type' => UserType::Admin,
        ]);
        $user->assignRole(DashboardRole::SupportAgent->value);

        return EmployeeProfile::query()->create([
            'user_id' => $user->id,
            'employee_code' => $employeeCode,
            'job_title' => $jobTitle,
            'phone' => $phone,
            'email' => $email,
            'is_active' => true,
            'use_base_salary' => true,
            'base_salary' => $baseSalary,
            'salary_calculation_mode' => SalaryCalculationMode::BasePlusPerformance,
        ]);
    }

    /**
     * Stocks the Repair Bench warehouse with resin used for post-repair
     * calibration test prints, so {@see ServiceRecordPartService::consume()}
     * has real stock to draw from — self-contained rather than depending on
     * {@see InventoryDemoSeeder}'s own (unrelated) stock movements leaving a
     * particular balance behind.
     */
    private function stockRepairBenchSpares(Warehouse $bench, ProductVariant $resin, User $actor): void
    {
        if (InventoryReceipt::query()->where('supplier_reference', 'FL-BENCH-SPARES-2026-9001')->exists()) {
            return;
        }

        $supplier = Supplier::query()->where('code', 'FORMLABS-US')->firstOrFail();

        $receipt = InventoryReceipt::query()->create([
            'warehouse_id' => $bench->getKey(),
            'supplier_id' => $supplier->getKey(),
            'supplier_reference' => 'FL-BENCH-SPARES-2026-9001',
            'notes' => 'Resin stocked at the repair bench for post-repair calibration test prints.',
        ]);
        $receipt->items()->create([
            'product_variant_id' => $resin->getKey(),
            'quantity' => 10,
            'purchase_cost' => 85,
            'lot_number' => 'LOT-BENCH-SPARES-01',
            'expires_at' => now()->addYear(),
        ]);

        app(InventoryReceivingService::class)->confirm($receipt, $actor);
    }

    /**
     * `pending` — freshly logged, not yet triaged (US2).
     */
    private function seedUntriagedTicket(CustomerProfile $customer, User $manager): Ticket
    {
        return app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::SoftwareIssue->value,
            'priority' => TicketPriority::Normal->value,
            'title' => 'Ceramill design app keeps freezing during STL export',
            'description' => "The clinic's design workstation freezes every time a case is exported to STL for printing. Started after last week's OS update.",
        ], $manager);
    }

    /**
     * `pending_payment`, unsettled — a chargeable ticket held before any
     * work can begin (US4, FR-027).
     */
    private function seedUnsettledChargeableTicket(CustomerProfile $customer, User $manager): Ticket
    {
        return app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::HardwareIssue->value,
            'priority' => TicketPriority::High->value,
            'title' => 'On-site printer recalibration — billable visit requested',
            'description' => 'Out-of-warranty Form 4B is producing slightly undersized models; customer requested a paid on-site recalibration visit.',
            'is_chargeable' => true,
            'amount' => 250.00,
            'currency' => 'AED',
        ], $manager);
    }

    /**
     * `pending_payment` settled by System Admin, then assigned and worked to
     * `in_progress` with a first response posted — demonstrates FR-042/043
     * (settlement unblocks the ticket) and FR-033 (first-response
     * timestamp).
     */
    private function seedSettledInProgressTicket(CustomerProfile $customer, EmployeeProfile $fadi, User $manager, User $admin): Ticket
    {
        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::SoftwareIssue->value,
            'priority' => TicketPriority::Urgent->value,
            'title' => 'PrograPrint PR5 print jobs failing at 90%',
            'description' => 'Every job on the PrograPrint PR5 aborts at roughly 90% completion with no error message. Blocking same-day case delivery.',
            'is_chargeable' => true,
            'amount' => 150.00,
            'currency' => 'USD',
        ], $admin);

        app(TicketPaymentService::class)->settle($ticket->paymentLink()->firstOrFail(), 'VISA-DEMO-4471', $admin);

        app(TicketLifecycleService::class)->assign($ticket->refresh(), $fadi, $manager);
        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $this->userFor($fadi));

        app(TicketMessageService::class)->post(
            $ticket->refresh(),
            'Remote diagnostics show a firmware mismatch between the printer and the slicer plugin. Dispatching a technician with the corrected firmware today.',
            false,
            $this->userFor($fadi),
        );

        return $ticket;
    }

    /**
     * `live`, unassigned — triaged and ready for a Support Manager to
     * assign (US3 scenario 1).
     */
    private function seedUnassignedLiveTicket(CustomerProfile $customer, User $manager): Ticket
    {
        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::GeneralSupport->value,
            'priority' => TicketPriority::Low->value,
            'title' => 'Request for bulk dental stone delivery schedule',
            'description' => 'Clinic wants a recurring monthly delivery schedule for 25kg dental stone sacks instead of ad-hoc orders.',
        ], $manager);

        app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);

        return $ticket;
    }

    /**
     * `assigned`, not yet started — the assignee hasn't begun work (US3
     * scenario 2, before scenario 4).
     */
    private function seedAssignedNotStartedTicket(CustomerProfile $customer, User $manager, EmployeeProfile $rasha): Ticket
    {
        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::HardwareIssue->value,
            'priority' => TicketPriority::Normal->value,
            'title' => 'Form Wash V2 making grinding noise',
            'description' => 'Automated wash unit makes a grinding noise during the spin cycle; still completes the wash but the noise is new.',
        ], $manager);

        app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
        app(TicketLifecycleService::class)->assign($ticket->refresh(), $rasha, $manager);

        return $ticket;
    }

    /**
     * `in_progress` after a `waiting_customer` pause and resume —
     * demonstrates FR-055's resolution-clock extension with a real,
     * observable gap between `resolution_due_at` before and after the
     * pause.
     */
    private function seedPausedResumedTicket(CustomerProfile $customer, User $manager, EmployeeProfile $fadi): Ticket
    {
        $agent = $this->userFor($fadi);

        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::SoftwareIssue->value,
            'priority' => TicketPriority::Normal->value,
            'title' => 'Intermittent Wi-Fi disconnect on Primeprint Solution',
            'description' => 'Primeprint Solution drops off the clinic Wi-Fi network several times a day, pausing in-progress jobs.',
        ], $manager);

        app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
        app(TicketLifecycleService::class)->assign($ticket->refresh(), $fadi, $manager);
        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

        app(TicketMessageService::class)->post($ticket->refresh(), 'Suspect the clinic router\'s DHCP lease time is too short — checking with IT before advising a fix.', true, $agent);
        app(TicketMessageService::class)->post($ticket->refresh(), 'Could you confirm which Wi-Fi router model the clinic is using?', false, $agent);

        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::WaitingCustomer, $agent);
        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

        return $ticket;
    }

    /**
     * `in_progress` with both SLA clocks already past due — its `live_at`
     * is backdated after the normal transition so the breach is real
     * elapsed time, not a fabricated flag; {@see SlaService::refreshBreachFlags()}
     * then persists the sticky flags exactly as the scheduled sweep would.
     */
    private function seedBreachedTicket(CustomerProfile $customer, User $manager, EmployeeProfile $rasha): Ticket
    {
        $agent = $this->userFor($rasha);

        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::HardwareIssue->value,
            'priority' => TicketPriority::Urgent->value,
            'title' => "Form 4B printer won't power on",
            'description' => 'Printer is completely unresponsive — no lights, no fan noise. Clinic has an urgent case queue waiting.',
        ], $manager);

        app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
        app(TicketLifecycleService::class)->assign($ticket->refresh(), $rasha, $manager);
        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

        $ticket->refresh();
        $liveAt = now()->subHours(8);
        $ticket->forceFill([
            'live_at' => $liveAt,
            'response_due_at' => $liveAt->clone()->addMinutes((int) $ticket->sla_response_target_minutes),
            'resolution_due_at' => $liveAt->clone()->addMinutes((int) $ticket->sla_resolution_target_minutes),
        ])->save();

        app(SlaService::class)->refreshBreachFlags($ticket->refresh());

        return $ticket;
    }

    /**
     * `resolved`, awaiting closure — the customer's problem is fixed but a
     * Support Manager hasn't closed it yet (US3 scenario 6).
     */
    private function seedResolvedTicket(CustomerProfile $customer, User $manager, EmployeeProfile $fadi): Ticket
    {
        $agent = $this->userFor($fadi);

        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::GeneralSupport->value,
            'priority' => TicketPriority::Low->value,
            'title' => 'Cleaning solution recommendation for surgical guide resin',
            'description' => 'Clinic asked which isopropyl alcohol concentration is recommended for washing surgical guide resin prints.',
        ], $manager);

        app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
        app(TicketLifecycleService::class)->assign($ticket->refresh(), $fadi, $manager);
        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);
        app(TicketMessageService::class)->post($ticket->refresh(), '99% IPA for the first wash stage, followed by a second wash in fresh 99% IPA — matches the resin datasheet.', false, $agent);
        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::Resolved, $agent);

        return $ticket;
    }

    /**
     * The flagship end-to-end scenario: a `maintenance_request`-type ticket
     * raises a Maintenance Request against a real serialized Form 4B unit
     * (`FORM4B-DEMO-0001`) with warranty cover, planned as two Service
     * Records — one closed with spare parts consumed and one mistaken
     * consumption reversed by System Admin (FR-086), one cancelled after
     * diagnosis found no fault — closing the maintenance request and then
     * the ticket itself (FR-026's closure gate).
     */
    private function seedFlagshipMaintenanceTicket(CustomerProfile $customer, User $manager, EmployeeProfile $fadi, User $admin, Warehouse $bench, ProductVariant $resin): Ticket
    {
        $agent = $this->userFor($fadi);

        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::MaintenanceRequest->value,
            'priority' => TicketPriority::High->value,
            'title' => 'Form 4B — print engine grinding noise mid-print',
            'description' => 'The Form 4B print engine makes a grinding noise partway through longer jobs and occasionally aborts. Unit is still under warranty.',
        ], $manager);

        app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
        app(TicketLifecycleService::class)->assign($ticket->refresh(), $fadi, $manager);
        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

        $record = app(MaintenanceRecordService::class)->createFromTicket($ticket->refresh(), [
            'description' => 'Print engine emits a grinding noise and aborts on jobs longer than ~2 hours. Unit is under manufacturer warranty.',
            'serial_number' => 'FORM4B-DEMO-0001',
            'warranty_status' => WarrantyStatus::Covered->value,
            'warranty_expiry_date' => now()->addMonths(8)->toDateString(),
        ], $manager);

        $diagnosis = app(ServiceRecordService::class)->create($record, [
            'title' => 'Diagnose grinding noise source',
            'description' => 'Isolate whether the noise comes from the build platform lead screw or the resin tank tilt mechanism.',
            'employee_id' => $fadi->getKey(),
            'due_at' => now()->addDay(),
        ], $manager);
        app(ServiceRecordService::class)->transition($diagnosis, MaintenanceStatus::InProgress, $agent);
        app(ServiceRecordService::class)->transition($diagnosis, MaintenanceStatus::Cancelled, $agent, 'Root-caused to the build platform lead screw during diagnosis — folded into the repair record below instead of a separate visit.');

        $repair = app(ServiceRecordService::class)->create($record, [
            'title' => 'Replace build platform lead screw and recalibrate',
            'description' => 'Replace the worn lead screw, reseat the build platform, and run calibration test prints.',
            'employee_id' => $fadi->getKey(),
            'due_at' => now()->addDays(2),
        ], $manager);
        app(ServiceRecordService::class)->transition($repair, MaintenanceStatus::InProgress, $agent);

        app(ServiceRecordPartService::class)->consume($repair, $this->modelId($resin), $this->modelId($bench), 2.0, $agent);

        $mistakenEntry = app(ServiceRecordPartService::class)->consume($repair, $this->modelId($resin), $this->modelId($bench), 1.0, $agent);
        app(ServiceRecordPartService::class)->reverse($mistakenEntry, $admin);

        app(ServiceRecordService::class)->transition($repair, MaintenanceStatus::Closed, $agent);
        app(MaintenanceRecordService::class)->transition($record->refresh(), MaintenanceStatus::Closed, $manager);

        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::Resolved, $agent);
        app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::Closed, $manager);

        return $ticket->refresh();
    }

    /**
     * A new ticket recording its link to the closed flagship ticket above
     * (FR-017 — "continuing the matter requires a new ticket that records
     * its link to the closed one") after the same fault recurs, raising a
     * follow-up Maintenance Request against the same physical unit — left
     * open and unassigned as the "needs attention" state.
     */
    private function seedContinuationTicket(Ticket $closedTicket, CustomerProfile $customer, User $manager): void
    {
        $followUp = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::MaintenanceRequest->value,
            'priority' => TicketPriority::High->value,
            'title' => 'Form 4B — grinding noise returned after last repair',
            'description' => 'The grinding noise from the previous repair has come back after about three weeks of normal use.',
            'continued_from_ticket_id' => $closedTicket->getKey(),
        ], $manager);

        app(TicketLifecycleService::class)->transition($followUp, TicketStatus::Live, $manager);

        app(MaintenanceRecordService::class)->createFromTicket($followUp->refresh(), [
            'description' => 'Grinding noise recurred roughly three weeks after the lead-screw replacement on the linked prior ticket.',
            'serial_number' => 'FORM4B-DEMO-0001',
            'warranty_status' => WarrantyStatus::Covered->value,
            'warranty_expiry_date' => now()->addMonths(8)->toDateString(),
        ], $manager);
    }

    /**
     * `cancelled` — a chargeable ticket the customer abandoned before
     * settling, cancelling its pending payment link with it (FR-045, US4
     * scenario 6).
     */
    private function seedCancelledChargeableTicket(CustomerProfile $customer, User $manager): void
    {
        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::HardwareIssue->value,
            'priority' => TicketPriority::High->value,
            'title' => 'Emergency same-day repair visit — quote declined',
            'description' => 'Customer requested an emergency same-day visit; declined once the call-out fee was quoted and will schedule a standard visit instead.',
            'is_chargeable' => true,
            'amount' => 400.00,
            'currency' => 'AED',
        ], $manager);

        app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Cancelled, $manager, 'Customer declined the emergency call-out fee.');
    }

    /**
     * An archived (soft-deleted) duplicate — FR-015's "deleting a ticket
     * archives it rather than physically removing it," visible under the
     * list's Trashed filter.
     */
    private function seedArchivedDuplicateTicket(CustomerProfile $customer, User $manager): void
    {
        $ticket = app(TicketIntakeService::class)->create([
            'customer_id' => $customer->getKey(),
            'type' => TicketType::GeneralSupport->value,
            'priority' => TicketPriority::Low->value,
            'title' => 'Duplicate — submitted twice by mistake',
            'description' => 'Front-desk submitted this ticket twice in a row; keeping the other one.',
        ], $manager);

        $ticket->delete();
    }

    /**
     * A standalone Maintenance Request (no ticket) whose serial number
     * matches no known serialized unit — FR-063's "unlinked equipment"
     * state, distinct from simply having no serial number at all.
     */
    private function seedUnlinkedEquipmentRequest(CustomerProfile $customer, User $manager): void
    {
        app(MaintenanceRecordService::class)->createStandalone([
            'customer_id' => $customer->getKey(),
            'description' => "Customer brought in an old 3D printer for assessment — model plate is worn, serial number they provided doesn't match anything in our records.",
            'serial_number' => 'FL-LEGACY-UNKNOWN-0007',
            'warranty_status' => WarrantyStatus::Expired->value,
            'warranty_expiry_date' => now()->subYear()->toDateString(),
        ], $manager);
    }

    /**
     * A standalone Maintenance Request against the Form Wash V2 unit
     * already sitting at the Repair Bench warehouse (`WASHV2-DEMO-0001`,
     * transferred there by {@see InventoryDemoSeeder} "for scheduled
     * maintenance"), planned as one overdue Service Record assigned to
     * Rasha and one due-soon Service Record assigned to Fadi — FR-076's
     * overdue/due-soon visual states, and cross-agent ownership in the same
     * request.
     */
    private function seedCrossAgentMaintenanceRequest(CustomerProfile $customer, User $manager, EmployeeProfile $fadi, EmployeeProfile $rasha): void
    {
        $record = app(MaintenanceRecordService::class)->createStandalone([
            'customer_id' => $customer->getKey(),
            'description' => 'Scheduled preventive maintenance on the Form Wash V2 unit — drum motor bearing due for replacement per the service interval.',
            'serial_number' => 'WASHV2-DEMO-0001',
            'warranty_status' => WarrantyStatus::Unknown->value,
        ], $manager);

        $motorReplacement = app(ServiceRecordService::class)->create($record, [
            'title' => 'Replace wash station drum motor',
            'description' => 'Replace the drum motor bearing and verify smooth operation across a full wash cycle.',
            'employee_id' => $rasha->getKey(),
            'due_at' => now()->addDay(),
        ], $manager);
        app(ServiceRecordService::class)->transition($motorReplacement, MaintenanceStatus::InProgress, $this->userFor($rasha));
        $motorReplacement->forceFill(['due_at' => now()->subDays(2)])->save();

        app(ServiceRecordService::class)->create($record, [
            'title' => 'Full wash cycle calibration check',
            'description' => 'Run a full calibration wash cycle and confirm timing matches the manufacturer spec after the motor replacement.',
            'employee_id' => $fadi->getKey(),
            'due_at' => now()->addHours(10),
        ], $manager);
    }

    private function userFor(EmployeeProfile $employee): User
    {
        $user = $employee->user;

        if (! $user instanceof User) {
            throw new LogicException('A Support Agent EmployeeProfile must always belong to a User.');
        }

        return $user;
    }

    private function modelId(Model $model): int
    {
        $id = $model->getKey();

        if (! is_int($id)) {
            throw new LogicException('A persisted model with an integer key is required.');
        }

        return $id;
    }
}
