<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\BonusSuggestionStatus;
use App\Enums\PlanTaskStatus;
use App\Enums\SalesPlanStatus;
use App\Enums\VisitStatus;
use App\Models\AiKeywordRule;
use App\Models\BonusSuggestion;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\PlanTask;
use App\Models\ProductVariant;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\BonusApprovalService;
use App\Services\Employees\OpportunityReviewService;
use App\Services\Employees\PlanTaskService;
use App\Services\Employees\SalaryCalculationService;
use App\Services\Employees\SalaryRecalculationService;
use App\Services\Employees\SalesPlanService;
use App\Services\Employees\VisitReviewService;
use App\Services\Employees\VoiceNoteIntakeService;
use App\Services\Employees\VoiceNoteTranscriber;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Employees story of the demo month: a September and an October plan for each of the six
 * employees, 47 tasks and 35 customer visits in every state, GPS trails, review notes,
 * attachments, voice notes (scripted offline transcription) with keyword-detected draft
 * opportunities and their review, bonuses, plan completion and salary calculation.
 *
 * Performance percentages are never set: they are produced by PerformanceScoringService when
 * the salary calculation runs, from the task/visit outcomes designed below.
 *
 * Everything is described as dated events that are sorted and replayed under the scene clock.
 * Run after DemoEmployeeRosterSeeder / DemoMasterDataSeeder (and DemoCrmMonthSeeder for the
 * converted customers, which fall back to a roster customer when absent).
 */
final class DemoEmployeeMonthSeeder extends DemoSeeder
{
    private const string AudioPlaceholder = 'DEMO-AUDIO-PLACEHOLDER-NOT-REAL-MEDIA';

    private const string DocumentPlaceholder = 'DEMO-DOCUMENT-PLACEHOLDER-NOT-REAL-MEDIA';

    private const string September = '2026-09-01';

    private const string October = '2026-10-01';

    /**
     * Plan design per employee number: weights [task, visit, schedule, work time] and required visit minutes.
     *
     * @var array<string, array{weights: array{0: int, 1: int, 2: int, 3: int}, minutes: int}>
     */
    private const array Plans = [
        '001' => ['weights' => [40, 30, 20, 10], 'minutes' => 45],
        '002' => ['weights' => [35, 35, 20, 10], 'minutes' => 45],
        '003' => ['weights' => [30, 30, 25, 15], 'minutes' => 45],
        '004' => ['weights' => [35, 35, 20, 10], 'minutes' => 40],
        '005' => ['weights' => [30, 30, 20, 20], 'minutes' => 30],
        '006' => ['weights' => [40, 30, 20, 10], 'minutes' => 45],
    ];

    /**
     * Tasks: key => [employee, month (09|10), title, customer, created, starts, due, startedAt, end (done|cancel|null), endAt].
     * An open task with a past due date is overdue; a task completed after its due date is a late completion.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: ?string, 4: string, 5: string, 6: string, 7: ?string, 8: ?string, 9: ?string}>
     */
    private const array Tasks = [
        'O1' => ['001', '09', 'Quarterly review at Al Noor Medical Center', 'DEMO-CUST-001', '2026-09-04 08:40', '2026-09-04', '2026-09-11', '2026-09-07 09:00', 'done', '2026-09-09 11:15'],
        'O2' => ['001', '09', 'Implant fixture trial at Bright Smile Dental Clinic', 'DEMO-CUST-002', '2026-09-04 08:45', '2026-09-04', '2026-09-14', '2026-09-10 08:30', 'done', '2026-09-11 12:30'],
        'O3' => ['001', '09', 'Collect signed price list from Pearl Dental Center', 'DEMO-CUST-004', '2026-09-04 08:50', '2026-09-04', '2026-09-16', null, 'done', '2026-09-15 10:10'],
        'O4' => ['001', '09', 'Resin restock check at Prime Dental Laboratory', 'DEMO-CUST-007', '2026-09-07 09:00', '2026-09-07', '2026-09-18', '2026-09-16 12:30', 'done', '2026-09-17 09:30'],
        'O5' => ['001', '09', 'Onboarding visit for Oasis Dental Studio', 'DEMO-CUST-016', '2026-09-14 11:00', '2026-09-14', '2026-09-23', '2026-09-21 09:00', 'done', '2026-09-22 10:30'],
        'O6' => ['001', '09', 'Product training at Elite Surgical Center', 'DEMO-CUST-012', '2026-09-09 09:30', '2026-09-09', '2026-09-22', '2026-09-24 13:30', 'done', '2026-09-25 09:00'],
        'O7' => ['001', '09', 'Follow up open quotation at Harmony Dental Clinic', 'DEMO-CUST-013', '2026-09-14 09:30', '2026-09-14', '2026-09-25', null, 'done', '2026-09-24 11:00'],
        'O8' => ['001', '09', 'Annual renewal discussion with Green Valley Clinic', 'DEMO-CUST-008', '2026-09-16 09:30', '2026-09-16', '2026-09-28', null, 'done', '2026-09-28 15:30'],
        'O9' => ['001', '09', 'Competitor pricing review at Future Health Clinic', 'DEMO-CUST-010', '2026-09-16 09:45', '2026-09-16', '2026-09-30', null, 'cancel', '2026-09-24 16:00'],
        'OO1' => ['001', '10', 'Q4 planning meeting with Al Noor Medical Center', 'DEMO-CUST-001', '2026-09-28 10:05', '2026-10-01', '2026-10-09', null, null, null],

        'L1' => ['002', '09', 'Monthly stock review at City Diagnostic Clinic', 'DEMO-CUST-006', '2026-09-04 08:55', '2026-09-04', '2026-09-10', '2026-09-08 08:30', 'done', '2026-09-09 09:30'],
        'L2' => ['002', '09', 'Model resin demo at Modern Dental Lab', 'DEMO-CUST-011', '2026-09-04 09:00', '2026-09-04', '2026-09-15', null, 'done', '2026-09-14 11:10'],
        'L3' => ['002', '09', 'Overdue payment conversation with Royal Medical Center', 'DEMO-CUST-009', '2026-09-07 09:15', '2026-09-07', '2026-09-18', null, 'done', '2026-09-17 11:00'],
        'L4' => ['002', '09', 'Introductory visit to Family Medical Center', 'DEMO-CUST-015', '2026-09-09 09:45', '2026-09-09', '2026-09-21', '2026-09-21 14:30', 'done', '2026-09-22 09:20'],
        'L5' => ['002', '09', 'Impression tray usage survey at Harmony Dental Clinic', 'DEMO-CUST-013', '2026-09-11 09:30', '2026-09-11', '2026-09-25', null, 'done', '2026-09-24 10:30'],
        'L6' => ['002', '09', 'Customer feedback visit to Advanced Care Center', 'DEMO-CUST-014', '2026-09-14 10:00', '2026-09-14', '2026-09-29', null, null, null],
        'L7' => ['002', '09', 'Equipment demo at Green Valley Clinic', 'DEMO-CUST-008', '2026-09-16 10:00', '2026-09-16', '2026-09-30', null, 'cancel', '2026-09-25 14:00'],
        'LO1' => ['002', '10', 'Installed base check at City Diagnostic Clinic', 'DEMO-CUST-006', '2026-09-28 10:10', '2026-10-01', '2026-10-08', null, null, null],
        'LO2' => ['002', '10', 'September follow-ups with Royal Medical Center', 'DEMO-CUST-009', '2026-09-28 10:15', '2026-10-01', '2026-10-06', '2026-10-02 15:00', null, null],

        'K1' => ['003', '09', 'Price list refresh at New Care Medical Center', 'DEMO-CUST-005', '2026-09-04 09:05', '2026-09-04', '2026-09-12', '2026-09-10 09:30', 'done', '2026-09-11 10:00'],
        'K2' => ['003', '09', 'Collect outstanding documents from Prime Dental Laboratory', 'DEMO-CUST-007', '2026-09-04 09:10', '2026-09-04', '2026-09-16', null, 'done', '2026-09-15 08:45'],
        'K3' => ['003', '09', 'Promotion walk-through at Family Medical Center', 'DEMO-CUST-015', '2026-09-09 10:00', '2026-09-09', '2026-09-22', null, 'done', '2026-09-21 13:30'],
        'K4' => ['003', '09', 'Reorder discussion with Pearl Dental Center', 'DEMO-CUST-004', '2026-09-11 10:00', '2026-09-11', '2026-09-24', null, 'done', '2026-09-26 10:15'],
        'K5' => ['003', '09', 'Prospect visit to Green Valley Clinic', 'DEMO-CUST-008', '2026-09-14 10:30', '2026-09-14', '2026-09-26', '2026-09-22 11:00', null, null],
        'K6' => ['003', '09', 'Update contact details at Advanced Care Center', 'DEMO-CUST-014', '2026-09-16 10:30', '2026-09-16', '2026-09-28', null, null, null],
        'K7' => ['003', '09', 'Staff the Dental Expo stand', null, '2026-09-16 10:45', '2026-09-16', '2026-09-20', null, 'cancel', '2026-09-18 08:30'],
        'KO1' => ['003', '10', 'Q4 reorder planning with Pearl Dental Center', 'DEMO-CUST-004', '2026-09-28 10:20', '2026-10-01', '2026-10-02', null, null, null],
        'KO2' => ['003', '10', 'Resin trial at Prime Dental Laboratory', 'DEMO-CUST-007', '2026-10-01 14:00', '2026-10-01', '2026-10-10', null, null, null],

        'N1' => ['004', '09', 'Surgical drill demo at Al Hayat Day Surgery Center', 'DEMO-CUST-003', '2026-09-04 09:20', '2026-09-04', '2026-09-12', '2026-09-10 14:00', 'done', '2026-09-11 11:00'],
        'N2' => ['004', '09', 'Scan body workflow training at Bright Smile Dental Clinic', 'DEMO-CUST-002', '2026-09-07 09:30', '2026-09-07', '2026-09-18', null, 'done', '2026-09-17 10:00'],
        'N3' => ['004', '09', 'Technical review call with Elite Surgical Center', 'DEMO-CUST-012', '2026-09-09 10:15', '2026-09-09', '2026-09-24', null, 'done', '2026-09-23 16:00'],
        'N4' => ['004', '09', 'Calibration check at Royal Medical Center', 'DEMO-CUST-009', '2026-09-11 10:30', '2026-09-11', '2026-09-25', '2026-09-23 09:30', null, null],
        'N5' => ['004', '09', 'Bur set trial at Future Health Clinic', 'DEMO-CUST-010', '2026-09-14 10:45', '2026-09-14', '2026-09-28', null, null, null],
        'N6' => ['004', '09', 'Co-host product webinar', null, '2026-09-16 11:00', '2026-09-16', '2026-09-22', null, 'cancel', '2026-09-18 17:00'],
        'NO1' => ['004', '10', 'Follow-up demo at Al Hayat Day Surgery Center', 'DEMO-CUST-003', '2026-09-28 10:25', '2026-10-01', '2026-10-12', null, null, null],

        'S1' => ['005', '09', 'On-site handpiece service at Pearl Dental Center', 'DEMO-CUST-004', '2026-09-04 09:25', '2026-09-04', '2026-09-10', '2026-09-09 08:30', 'done', '2026-09-09 10:00'],
        'S2' => ['005', '09', 'Preventive check at Al Noor Medical Center', 'DEMO-CUST-001', '2026-09-04 09:30', '2026-09-04', '2026-09-15', null, 'done', '2026-09-16 09:30'],
        'S3' => ['005', '09', 'Equipment installation support at Advanced Care Center', 'DEMO-CUST-014', '2026-09-09 10:30', '2026-09-09', '2026-09-21', null, 'done', '2026-09-21 11:30'],
        'S4' => ['005', '09', 'Calibrate surgical unit at Elite Surgical Center', 'DEMO-CUST-012', '2026-09-14 11:00', '2026-09-14', '2026-09-24', null, 'done', '2026-09-26 15:00'],
        'S5' => ['005', '09', 'Repair follow-up at Green Valley Clinic', 'DEMO-CUST-008', '2026-09-16 11:15', '2026-09-16', '2026-09-28', '2026-09-25 09:00', null, null],
        'SO1' => ['005', '10', 'Quarterly preventive visits to Dubai clinics', 'DEMO-CUST-001', '2026-09-28 10:30', '2026-10-01', '2026-10-07', null, null, null],

        'H1' => ['006', '09', 'Account activation visit to Prime Dental Laboratory', 'DEMO-CUST-007', '2026-09-04 09:35', '2026-09-04', '2026-09-11', null, 'done', '2026-09-10 16:15'],
        'H2' => ['006', '09', 'Order feedback collection at Modern Dental Lab', 'DEMO-CUST-011', '2026-09-07 09:45', '2026-09-07', '2026-09-18', null, 'done', '2026-09-21 10:00'],
        'H3' => ['006', '09', 'Quarterly check-in at Family Medical Center', 'DEMO-CUST-015', '2026-09-11 10:45', '2026-09-11', '2026-09-24', '2026-09-22 09:30', null, null],
        'H4' => ['006', '09', 'Update customer records at Harmony Dental Clinic', 'DEMO-CUST-013', '2026-09-14 11:00', '2026-09-14', '2026-09-26', null, null, null],
        'H5' => ['006', '09', 'Prepare monthly sales report', null, '2026-09-14 11:15', '2026-09-14', '2026-09-25', null, 'cancel', '2026-09-24 12:00'],
        'HO1' => ['006', '10', 'Prepare October account reviews', null, '2026-09-28 10:35', '2026-10-01', '2026-10-02', '2026-10-01 09:30', null, null],
    ];

    /**
     * Visits: key => [task, planned, checkIn, checkOut, status, outcome, created?, review?, attachment?].
     * Missed visits have no timestamps; the re-planned visit V-L2b is the second visit of task L2.
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: ?string, 4: string, 5: ?string, 6: ?string, 7: ?string, 8: ?string}>
     */
    private const array Visits = [
        'V-O1' => ['O1', '2026-09-08 10:00', '2026-09-08 10:03', '2026-09-08 10:58', 'completed', 'Quarterly volumes on track; contract renewal agreed in principle.', null, 'Good customer feedback captured; follow up on the renewal date.', 'visit-report-0908.pdf'],
        'V-O2' => ['O2', '2026-09-10 11:00', '2026-09-10 11:00', '2026-09-10 11:50', 'completed', 'Two implant cases placed with trial fixtures; doctor requests a bulk quote.', null, null, null],
        'V-O3' => ['O3', '2026-09-15 09:00', '2026-09-15 09:05', '2026-09-15 09:42', 'completed', 'Price list signed; delivery schedule still open.', null, 'Short visit: customer only had time to sign. Delivery schedule needs a call-back.', 'signed-price-list.pdf'],
        'V-O4' => ['O4', '2026-09-16 13:00', '2026-09-16 13:02', '2026-09-16 13:51', 'completed', 'Lab is low on resin; urgent delivery requested.', null, null, null],
        'V-O5' => ['O5', '2026-09-21 10:00', '2026-09-21 10:04', '2026-09-21 11:02', 'completed', 'Account walkthrough done; staff trained on the ordering portal.', null, 'Excellent onboarding of a converted lead.', 'onboarding-checklist.pdf'],
        'V-O6' => ['O6', '2026-09-24 14:00', '2026-09-24 14:06', '2026-09-24 15:01', 'completed', 'Training delivered to six staff members.', null, null, 'training-attendance-sheet.pdf'],
        'V-O7' => ['O7', '2026-09-23 10:00', '2026-09-23 10:00', '2026-09-23 10:52', 'completed', 'Quotation questions answered; decision expected next week.', null, null, null],
        'V-OO1' => ['OO1', '2026-10-06 10:00', null, null, 'planned', null, '2026-09-30 16:30', null, null],

        'V-L1' => ['L1', '2026-09-08 09:00', '2026-09-08 09:00', '2026-09-08 09:50', 'completed', 'Stock counted; reorder of sterilisation pouches agreed.', null, null, null],
        'V-L2a' => ['L2', '2026-09-11 10:00', null, null, 'missed', 'Clinic was closed for maintenance when the representative arrived.', null, null, null],
        'V-L2b' => ['L2', '2026-09-14 10:00', '2026-09-14 10:05', '2026-09-14 10:55', 'completed', 'Model resin demo completed with the lab technicians.', '2026-09-11 17:00', null, null],
        'V-L3' => ['L3', '2026-09-17 10:00', '2026-09-17 10:00', '2026-09-17 10:47', 'completed', 'Overdue balance discussed; payment promised within the week.', null, null, null],
        'V-L4' => ['L4', '2026-09-21 14:00', '2026-09-21 14:00', '2026-09-21 14:30', 'completed', 'Introduction to the clinic manager; catalogue left behind.', null, 'Visit shorter than the required 45 minutes; coach on discovery questions.', null],
        'V-L5' => ['L5', '2026-09-23 10:00', '2026-09-23 10:02', '2026-09-23 10:52', 'completed', 'Impression tray usage surveyed across three surgeries.', null, null, 'tray-usage-survey.pdf'],
        'V-LO2' => ['LO2', '2026-10-03 14:00', '2026-10-03 14:05', null, 'inprogress', null, '2026-09-30 16:30', null, null],

        'V-K1' => ['K1', '2026-09-10 10:00', '2026-09-10 10:00', '2026-09-10 10:50', 'completed', 'Price list refreshed and acknowledged by the manager.', null, null, null],
        'V-K2' => ['K2', '2026-09-14 09:30', '2026-09-14 09:30', '2026-09-14 10:05', 'completed', 'Documents collected; two items still missing.', null, 'Documents incomplete; second pickup needed.', null],
        'V-K3' => ['K3', '2026-09-21 12:00', '2026-09-21 12:00', '2026-09-21 12:55', 'completed', 'Promotion explained to the practice owner.', null, null, 'promotion-display.jpg'],
        'V-K4' => ['K4', '2026-09-24 11:00', null, null, 'missed', 'Clinic closed, owner travelling.', null, null, null],
        'V-K5' => ['K5', '2026-09-25 10:00', null, null, 'missed', 'Representative reassigned to an urgent account.', null, null, null],
        'V-KO2' => ['KO2', '2026-10-05 09:00', null, null, 'planned', null, '2026-10-01 14:30', null, null],

        'V-N1' => ['N1', '2026-09-11 10:00', '2026-09-11 10:00', '2026-09-11 10:30', 'completed', 'Drill demo given to two surgeons; both want a quote.', null, 'Demo felt rushed; schedule more time for hands-on use.', 'demo-checklist.pdf'],
        'V-N2' => ['N2', '2026-09-16 14:00', '2026-09-16 14:00', '2026-09-16 14:35', 'completed', 'Scan body workflow shown to the assistant.', null, null, null],
        'V-N4' => ['N4', '2026-09-24 10:00', null, null, 'missed', 'Equipment room locked; nobody available to assist.', null, null, null],
        'V-N5a' => ['N5', '2026-09-25 11:00', null, null, 'missed', 'Customer postponed the trial by phone.', null, null, null],
        'V-N5b' => ['N5', '2026-09-28 11:00', null, null, 'missed', 'Customer postponed again.', '2026-09-25 12:00', null, null],

        'V-S1' => ['S1', '2026-09-09 09:00', '2026-09-09 09:00', '2026-09-09 09:45', 'completed', 'Handpiece serviced and tested; bearings replaced.', null, null, 'service-report-handpiece.pdf'],
        'V-S2' => ['S2', '2026-09-15 13:00', '2026-09-15 13:00', '2026-09-15 13:20', 'completed', 'Quick preventive check; no faults found.', null, 'Very short for a preventive check; confirm full checklist was run.', null],
        'V-S3' => ['S3', '2026-09-21 10:00', '2026-09-21 10:00', '2026-09-21 10:40', 'completed', 'Unit installed and operator briefed.', null, null, 'installation-sign-off.pdf'],
        'V-S4' => ['S4', '2026-09-24 10:00', null, null, 'missed', 'Calibration part delayed; visit rebooked.', null, null, null],
        'V-SO1' => ['SO1', '2026-10-07 09:00', null, null, 'planned', null, '2026-10-01 15:00', null, null],

        'V-H1' => ['H1', '2026-09-10 15:00', '2026-09-10 15:00', '2026-09-10 16:00', 'completed', 'Account activated; lab manager signed the activation form.', null, 'Good detail on the activation form.', 'activation-form.pdf'],
        'V-H2' => ['H2', '2026-09-17 10:00', null, null, 'missed', 'Lab manager unavailable.', null, null, null],
        'V-H3' => ['H3', '2026-09-23 10:00', null, null, 'missed', 'Visit not carried out.', null, null, null],
        'V-H4' => ['H4', '2026-09-25 11:00', null, null, 'missed', 'Visit not carried out.', null, null, null],
    ];

    /**
     * Voice notes: visit => [file, language, duration seconds, transcript or null for the corrupt recording].
     * Keyword rules: implant, resin, drill, maintenance kit.
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: ?string}>
     */
    private const array VoiceNotes = [
        'V-O1' => ['vn-001-quarterly-review.m4a', 'en', 94, 'Quarterly review went well. The clinic is happy with delivery times and will confirm the next order after the weekend.'],
        'V-O2' => ['vn-002-fixture-trial.m4a', 'en', 128, 'The doctor tried the implant fixtures on two cases today and wants a bulk quote for next quarter.'],
        'V-O4' => ['vn-003-lab-restock.m4a', 'en', 71, 'The lab is running low on resin and asked for a delivery before the end of the week.'],
        'V-N1' => ['vn-004-drill-demo.m4a', 'en', 156, 'Great demo today. They want to replace the surgical drill and also asked about implant guides for the new surgeon.'],
        'V-L3' => ['vn-005-collections.m4a', 'ar', 63, 'تمت مناقشة الفاتورة المتأخرة مع المدير وقد وعد بالسداد قبل نهاية الأسبوع.'],
        'V-L5' => ['vn-006-corrupt.m4a', 'en', 48, null],
        'V-K1' => ['vn-007-service-kits.m4a', 'en', 82, 'The clinic needs three maintenance kit sets for their autoclave service next month.'],
        'V-H1' => ['vn-008-activation.m4a', 'en', 57, 'Account activation complete. The lab manager will place the first order this week.'],
    ];

    /** Keyword rules: keyword => [product, variant suffix]. */
    private const array Keywords = [
        'implant' => ['P004', '35X10'],
        'resin' => ['P002', '1L'],
        'drill' => ['P019', 'HANDPIECE'],
        'maintenance kit' => ['P020', 'BASIC'],
    ];

    private DemoContext $context;

    /** @var list<array{0: string, 1: int, 2: Closure}> */
    private array $events = [];

    /** @var array<string, SalesPlan> keyed by "<employee number>-<month>" */
    private array $plans = [];

    /** @var array<string, PlanTask> */
    private array $tasks = [];

    /** @var array<string, CustomerVisit> */
    private array $visits = [];

    /** @var array<string, EmployeeSalaryCalculation> */
    private array $calculations = [];

    protected function seed(DemoContext $context): void
    {
        $roster = EmployeeProfile::query()->where('employee_code', 'DEMO-EMP-001')->first();

        if (! $roster instanceof EmployeeProfile) {
            throw new LogicException('Run DemoEmployeeRosterSeeder before DemoEmployeeMonthSeeder.');
        }

        if (SalesPlan::query()->where('employee_id', $roster->getKey())->whereDate('month', self::September)->exists()) {
            $this->note('Employees month story already seeded (DEMO-EMP-001 has a September plan) - skipping.');

            return;
        }

        $this->context = $context;

        $script = [];
        foreach (self::VoiceNotes as [$file, $language, , $transcript]) {
            if ($transcript !== null) {
                $script[$file] = ['text' => $transcript, 'confidence' => 91.5, 'language' => $language];
            }
        }
        app()->instance(VoiceNoteTranscriber::class, new DemoScriptedTranscriber($script));

        $this->seedKeywordRules($context);
        $this->planPlans();
        $this->planTasks();
        $this->planVisits();
        $this->planVoiceNotes();
        $this->planReminders();
        $this->planMonthEnd();
        $this->replay();
        $this->report();
    }

    // -------------------------------------------------------------- scheduling

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

    private function asManager(): User
    {
        return $this->context->as('employee_manager');
    }

    private function asEmployee(string $number): User
    {
        $user = $this->employee($number)->user;

        if (! $user instanceof User) {
            throw new LogicException("Employee {$number} has no user.");
        }

        Auth::setUser($user);

        return $user;
    }

    // ------------------------------------------------------------------- plans

    private function planPlans(): void
    {
        foreach (self::Plans as $number => $design) {
            $this->on('2026-09-04 08:30', function () use ($number, $design): void {
                $this->asManager();
                $this->plans["{$number}-09"] = $this->createPlan($number, self::September, 'September 2026 Territory Plan', $design);
            });

            $this->on('2026-09-04 10:00', function () use ($number): void {
                $this->asManager();
                $this->plans["{$number}-09"] = app(SalesPlanService::class)->transition($this->plans["{$number}-09"]->refresh(), SalesPlanStatus::Active);
            });

            $this->on('2026-09-28 10:00', function () use ($number, $design): void {
                $this->asManager();
                $this->plans["{$number}-10"] = $this->createPlan($number, self::October, 'October 2026 Territory Plan', $design);
            });

            $this->on('2026-10-01 09:00', function () use ($number): void {
                $this->asManager();
                $this->plans["{$number}-10"] = app(SalesPlanService::class)->transition($this->plans["{$number}-10"]->refresh(), SalesPlanStatus::Active);
            });
        }
    }

    /** @param array{weights: array{0: int, 1: int, 2: int, 3: int}, minutes: int} $design */
    private function createPlan(string $number, string $month, string $name, array $design): SalesPlan
    {
        return app(SalesPlanService::class)->create([
            'employee_id' => $this->employee($number)->getKey(),
            'name' => $name,
            'month' => $month,
            'task_weight' => $design['weights'][0],
            'visit_weight' => $design['weights'][1],
            'schedule_weight' => $design['weights'][2],
            'work_time_weight' => $design['weights'][3],
            'required_visit_minutes' => $design['minutes'],
        ]);
    }

    // ------------------------------------------------------------------- tasks

    private function planTasks(): void
    {
        foreach (self::Tasks as $key => [$number, $month, $title, $customer, $created, $starts, $due, $startedAt, $end, $endAt]) {
            $this->on($created, function () use ($key, $number, $month, $title, $customer, $starts, $due): void {
                $this->asManager();
                $this->tasks[$key] = app(PlanTaskService::class)->create($this->plans["{$number}-{$month}"], [
                    'title' => $title,
                    'description' => 'Assigned from the monthly territory plan.',
                    'customer_id' => $customer === null ? null : $this->customer($customer)->getKey(),
                    'starts_at' => Carbon::parse($starts),
                    'due_at' => Carbon::parse($due),
                ]);
            });

            if ($startedAt !== null) {
                $this->on($startedAt, function () use ($key): void {
                    $this->asManager();
                    $this->tasks[$key] = app(PlanTaskService::class)->transition($this->tasks[$key]->refresh(), PlanTaskStatus::InProgress, 'Started by the employee.');
                });
            }

            if ($end !== null && $endAt !== null) {
                $this->on($endAt, function () use ($key, $end): void {
                    $this->asManager();
                    $this->tasks[$key] = $end === 'done'
                        ? app(PlanTaskService::class)->transition($this->tasks[$key]->refresh(), PlanTaskStatus::Completed, 'Completed and logged by the employee.')
                        : app(PlanTaskService::class)->transition($this->tasks[$key]->refresh(), PlanTaskStatus::Cancelled, 'Cancelled: no longer required.');
                });
            }
        }
    }

    // ------------------------------------------------------------------ visits

    private function planVisits(): void
    {
        foreach (self::Visits as $key => [$taskKey, $planned, $checkIn, $checkOut, $status, $outcome, $created, $review, $attachment]) {
            $number = self::Tasks[$taskKey][0];
            $createdAt = $created ?? Carbon::parse($planned)->subDay()->setTime(16, 30)->format('Y-m-d H:i');

            $this->on($createdAt, function () use ($key, $taskKey, $number, $planned): void {
                $this->asEmployee($number);
                $task = $this->tasks[$taskKey]->refresh();

                $this->visits[$key] = CustomerVisit::query()->create([
                    'employee_id' => $this->employee($number)->getKey(),
                    'plan_task_id' => $task->getKey(),
                    'customer_id' => $task->customer_id,
                    'planned_at' => Carbon::parse($planned),
                    'status' => VisitStatus::Planned,
                ]);
            });

            if ($checkIn !== null) {
                $this->on($checkIn, function () use ($key, $number, $checkIn): void {
                    $this->asEmployee($number);
                    $visit = $this->visits[$key]->refresh();
                    $visit->update(['checked_in_at' => Carbon::parse($checkIn), 'status' => VisitStatus::InProgress]);
                    $this->trail($visit, 0, $checkIn);
                });
            }

            if ($checkOut !== null) {
                $this->on($checkOut, function () use ($key, $number, $checkOut, $outcome, $attachment): void {
                    $this->asEmployee($number);
                    $visit = $this->visits[$key]->refresh();
                    $visit->update([
                        'checked_out_at' => Carbon::parse($checkOut),
                        'status' => VisitStatus::Completed,
                        'outcome' => $outcome,
                    ]);
                    $this->trail($visit, 1, $checkOut);

                    if ($attachment !== null) {
                        $visit->addMediaFromString(self::DocumentPlaceholder)
                            ->usingFileName($attachment)
                            ->toMediaCollection('visit-attachments', 'local');
                    }
                });

                if ($review !== null) {
                    $this->on(Carbon::parse($checkOut)->addDay()->setTime(9, 0)->format('Y-m-d H:i'), function () use ($key, $review): void {
                        $this->asManager();
                        app(VisitReviewService::class)->updateReviewNote($this->visits[$key]->refresh(), $review);
                    });
                }
            }

            if ($status === 'missed') {
                $this->on(Carbon::parse($planned)->setTime(18, 0)->format('Y-m-d H:i'), function () use ($key, $number, $outcome): void {
                    $this->asEmployee($number);
                    $this->visits[$key]->refresh()->update(['status' => VisitStatus::Missed, 'outcome' => $outcome]);
                });
            }
        }
    }

    /** One GPS point near the customer: 0 = arrival, 1 = departure; offsets are fixed per visit id. */
    private function trail(CustomerVisit $visit, int $phase, string $at): void
    {
        $customer = $visit->customer;

        if (! $customer instanceof CustomerProfile || $customer->latitude === null || $customer->longitude === null) {
            return;
        }

        $offset = ((DemoContext::keyOf($visit) % 5) + 1) * 0.0001;

        $visit->gpsLogs()->create([
            'latitude' => round((float) $customer->latitude + ($phase === 0 ? $offset : -$offset), 7),
            'longitude' => round((float) $customer->longitude + ($phase === 0 ? -$offset : $offset), 7),
            'recorded_at' => Carbon::parse($at),
        ]);
    }

    // -------------------------------------------------------------- voice notes

    private function seedKeywordRules(DemoContext $context): void
    {
        $context->at('2026-09-04 08:20');
        $this->asManager();

        foreach (self::Keywords as $keyword => [$product, $suffix]) {
            $variant = ProductVariant::query()->where('sku', DemoMasterDataSeeder::sku($product, $suffix))->firstOrFail();

            AiKeywordRule::query()->firstOrCreate(
                ['keyword' => $keyword],
                ['product_id' => $variant->product_id, 'product_variant_id' => $variant->getKey(), 'is_active' => true],
            );
        }
    }

    private function planVoiceNotes(): void
    {
        foreach (self::VoiceNotes as $visitKey => [$file, $language, $duration]) {
            $checkOut = self::Visits[$visitKey][3];
            $number = self::Tasks[self::Visits[$visitKey][0]][0];

            $this->on(Carbon::parse($checkOut)->addMinutes(12)->format('Y-m-d H:i'), function () use ($visitKey, $file, $language, $duration, $number): void {
                $this->asEmployee($number);
                $path = 'demo-tmp/'.$file;
                Storage::disk('local')->put($path, self::AudioPlaceholder);

                try {
                    app(VoiceNoteIntakeService::class)->intake($this->visits[$visitKey]->refresh(), $path, $file, $language, $duration);
                } finally {
                    Storage::disk('local')->delete($path);
                }
            });
        }

        // Review of AI-detected draft opportunities: two approved, one rejected, two left awaiting review.
        $this->on('2026-09-12 10:00', fn () => $this->reviewOpportunity('V-O2', 'implant', true, 'Matches the trial discussed with the doctor; owner to raise a quotation.'));
        $this->on('2026-09-16 11:00', fn () => $this->reviewOpportunity('V-K1', 'maintenance kit', true, 'Confirmed with the clinic; create a kit quotation.'));
        $this->on('2026-09-18 09:30', fn () => $this->reviewOpportunity('V-O4', 'resin', false, 'Lab already ordered through a distributor; no opportunity.'));
    }

    private function reviewOpportunity(string $visitKey, string $keyword, bool $approve, string $notes): void
    {
        $this->context->as('employee_manager');

        $opportunity = SalesOpportunity::query()
            ->whereHas('keywordRule', fn ($query) => $query->where('keyword', $keyword))
            ->whereHas('transcription.employeeVoiceNote', fn ($query) => $query->where('customer_visit_id', $this->visits[$visitKey]->getKey()))
            ->firstOrFail();

        $service = app(OpportunityReviewService::class);
        $approve ? $service->approve($opportunity, $notes) : $service->reject($opportunity, $notes);
    }

    // ---------------------------------------------------------------- reminders

    /** The daily scheduler: visit reminders go out on the morning of every planned visit day up to today. */
    private function planReminders(): void
    {
        $days = [];

        foreach (self::Visits as [, $planned]) {
            $day = Carbon::parse($planned)->toDateString();

            if ($day <= '2026-10-03') {
                $days[$day] = true;
            }
        }

        foreach (array_keys($days) as $day) {
            $this->on("{$day} 07:30", function (): void {
                Artisan::call('notifications:visits-due');
            });
        }
    }

    // ---------------------------------------------------------------- month end

    private function planMonthEnd(): void
    {
        // Bonus suggestions for the September plans (no service creates suggestions; decisions go through BonusApprovalService).
        $this->on('2026-09-30 10:00', fn () => $this->suggestBonus('001', 300.0, 'Closed the Oasis Dental Studio starter contract and onboarded the account.', 'V-O2', 'implant'));
        $this->on('2026-09-30 10:30', fn () => $this->suggestBonus('003', 150.0, 'Extra weekend coverage during the Dental Expo preparation.', null, null));
        $this->on('2026-09-30 11:00', fn () => $this->suggestBonus('004', 200.0, 'Technical demo that created two drill and implant opportunities.', null, null));
        $this->on('2026-09-30 16:00', fn () => $this->decideBonus('001', true, 'Approved: signed contract confirmed by Sales.'));
        $this->on('2026-09-30 16:10', fn () => $this->decideBonus('003', false, 'Rejected: coverage was not agreed in advance.'));

        // September plans complete, then payroll calculates and confirms (Hala stays pending confirmation).
        foreach (array_keys(self::Plans) as $number) {
            $this->on('2026-10-01 08:30', function () use ($number): void {
                $this->asManager();
                $this->plans["{$number}-09"] = app(SalesPlanService::class)->transition($this->plans["{$number}-09"]->refresh(), SalesPlanStatus::Completed);
            });

            $this->on('2026-10-01 10:00', function () use ($number): void {
                $this->context->as('payroll');
                $this->calculations[$number] = app(SalaryCalculationService::class)->calculate($this->plans["{$number}-09"]->refresh());
            });

            if ($number !== '006') {
                $this->on('2026-10-01 10:30', function () use ($number): void {
                    $this->context->as('payroll');
                    $this->calculations[$number] = app(SalaryRecalculationService::class)->confirm($this->calculations[$number]->refresh());
                });
            }
        }

        // A late bonus is approved after confirmation: recalculation supersedes the confirmed figure.
        $this->on('2026-10-02 09:30', fn () => $this->suggestBonus('002', 250.0, 'Recovered the overdue Royal Medical Center balance.', null, null));
        $this->on('2026-10-02 09:45', fn () => $this->decideBonus('002', true, 'Approved after review of the collection.'));
        $this->on('2026-10-02 10:00', function (): void {
            $this->context->as('payroll');
            $this->calculations['002'] = app(SalaryRecalculationService::class)->recalculate($this->plans['002-09']->refresh());
        });
        $this->on('2026-10-02 10:15', function (): void {
            $this->context->as('payroll');
            $this->calculations['002'] = app(SalaryRecalculationService::class)->confirm($this->calculations['002']->refresh());
        });
    }

    private function suggestBonus(string $number, float $amount, string $reason, ?string $visitKey, ?string $keyword): void
    {
        $this->context->as('employee_manager');
        $opportunityId = null;

        if ($visitKey !== null && $keyword !== null) {
            $opportunityId = SalesOpportunity::query()
                ->whereHas('keywordRule', fn ($query) => $query->where('keyword', $keyword))
                ->whereHas('transcription.employeeVoiceNote', fn ($query) => $query->where('customer_visit_id', $this->visits[$visitKey]->getKey()))
                ->value('id');
        }

        BonusSuggestion::query()->create([
            'employee_id' => $this->employee($number)->getKey(),
            'sales_plan_id' => $this->plans["{$number}-09"]->getKey(),
            'sales_opportunity_id' => $opportunityId,
            'amount' => $amount,
            'reason' => $reason,
            'status' => BonusSuggestionStatus::Pending,
        ]);
    }

    private function decideBonus(string $number, bool $approve, string $notes): void
    {
        $this->context->as('employee_manager');

        $suggestion = BonusSuggestion::query()
            ->where('employee_id', $this->employee($number)->getKey())
            ->where('status', BonusSuggestionStatus::Pending->value)
            ->orderBy('id')
            ->firstOrFail();

        $service = app(BonusApprovalService::class);
        $approve ? $service->approve($suggestion, $notes) : $service->reject($suggestion, $notes);
    }

    // ----------------------------------------------------------------- reporting

    private function report(): void
    {
        foreach (self::Plans as $number => $design) {
            $calculation = EmployeeSalaryCalculation::query()
                ->where('employee_id', $this->employee($number)->getKey())
                ->latest('id')
                ->first();

            $this->note(sprintf('%s performance %s%% (%s)', $this->employee($number)->employee_code, $calculation->performance_percent ?? 'n/a', $calculation?->status->value ?? 'n/a'));
        }
    }

    // ------------------------------------------------------------------ lookups

    /** @var array<string, EmployeeProfile> */
    private array $employeeCache = [];

    private function employee(string $number): EmployeeProfile
    {
        return $this->employeeCache[$number] ??= EmployeeProfile::query()->where('employee_code', "DEMO-EMP-{$number}")->with('user')->firstOrFail();
    }

    private function customer(string $code): CustomerProfile
    {
        // Converted leads exist only after DemoCrmMonthSeeder; fall back to a roster customer otherwise.
        return CustomerProfile::query()->where('customer_code', $code)->first()
            ?? CustomerProfile::query()->where('customer_code', 'DEMO-CUST-008')->firstOrFail();
    }
}
