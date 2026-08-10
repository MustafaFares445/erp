<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BonusSuggestionStatus;
use App\Enums\DashboardRole;
use App\Enums\OpportunityDraftStatus;
use App\Enums\PlanTaskStatus;
use App\Enums\SalesPlanStatus;
use App\Enums\TranscriptionConfidenceSource;
use App\Enums\TranscriptionStatus;
use App\Enums\UserType;
use App\Enums\VisitStatus;
use App\Enums\VoiceNoteStatus;
use App\Models\AiKeywordRule;
use App\Models\BonusSuggestion;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVoiceNote;
use App\Models\PlanTask;
use App\Models\ProductVariant;
use App\Models\SalesOpportunityDraft;
use App\Models\SalesPlan;
use App\Models\User;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\BonusApprovalService;
use App\Services\Employees\EmployeeAccessService;
use App\Services\Employees\EmployeeOnboardingService;
use App\Services\Employees\OpportunityReviewService;
use App\Services\Employees\PlanTaskService;
use App\Services\Employees\SalaryCalculationService;
use App\Services\Employees\SalaryRecalculationService;
use App\Services\Employees\SalesPlanService;
use App\Services\Employees\VisitReviewService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * Gives every employees-module screen (profiles, monthly plans, tasks,
 * visits, voice notes, AI opportunity review, performance, salary, bonuses)
 * at least one believable record spanning a completed prior month and an
 * in-progress current month, so a client walkthrough never lands on an
 * empty state. Runs its business logic through the same services Filament
 * uses, so balances, status logs, and audit rows stay internally
 * consistent — exactly like {@see InventoryDemoSeeder}.
 *
 * Idempotent per employee: each seed*() method's first line checks whether
 * that employee already has a plan and returns early if so, since plans
 * (unlike catalogue rows) have no natural unique key to `updateOrCreate`
 * against.
 */
final class EmployeeDemoSeeder extends Seeder
{
    private const string DemoAudioPlaceholder = 'DEMO-AUDIO-PLACEHOLDER-NOT-REAL-MEDIA';

    private const string DemoDocumentPlaceholder = 'DEMO-DOCUMENT-PLACEHOLDER-NOT-REAL-MEDIA';

    public function run(): void
    {
        $this->call(EmployeePermissionSeeder::class);

        $admin = $this->demoAdmin();
        $manager = $this->dashboardUser('hr.manager@ierp.com', 'Hiba Suleiman', DashboardRole::EmployeeManager);
        $payroll = $this->dashboardUser('payroll.officer@ierp.com', 'Marwan Fakhoury', DashboardRole::PayrollOfficer);
        $this->dashboardUser('audit.reviewer@ierp.com', 'Dina Qasem', DashboardRole::Reviewer);

        $smile = $this->customer('smile-dental-clinic@ierp.com');
        $bright = $this->customer('bright-orthodontics@ierp.com');
        $rules = $this->seedAiKeywordRules();

        $currentMonth = now()->startOfMonth();
        $previousMonth = $currentMonth->copy()->subMonthNoOverflow();

        Auth::login($manager);

        $rania = $this->onboardEmployee([
            'name' => 'Rania Al-Khateeb',
            'login_email' => 'rania.alkhateeb@ierp.com',
            'job_title' => 'Senior Territory Manager',
            'phone' => '+971 50 555 0111',
            'use_base_salary' => true,
            'base_salary' => 7000.00,
        ]);
        $omar = $this->onboardEmployee([
            'name' => 'Omar Nasser',
            'login_email' => 'omar.nasser@ierp.com',
            'job_title' => 'Field Sales Representative',
            'phone' => '+971 50 555 0112',
            'use_base_salary' => true,
            'base_salary' => 4200.00,
        ]);
        $khaled = $this->onboardEmployee([
            'name' => 'Khaled Mansour',
            'login_email' => 'khaled.mansour@ierp.com',
            'job_title' => 'Field Sales Representative',
            'phone' => '+971 50 555 0113',
            'use_base_salary' => true,
            'base_salary' => 3800.00,
        ]);
        $layla = $this->onboardEmployee([
            'name' => 'Layla Haddad',
            'login_email' => 'layla.haddad@ierp.com',
            'job_title' => 'Clinical Accounts Executive',
            'phone' => '+971 50 555 0114',
            'use_base_salary' => false,
            'commission_target_amount' => 5500.00,
        ]);
        $nadia = $this->onboardEmployee([
            'name' => 'Nadia Fares',
            'login_email' => 'nadia.fares@ierp.com',
            'job_title' => 'Junior Sales Associate',
            'phone' => '+971 50 555 0115',
            'use_base_salary' => true,
            'base_salary' => 3000.00,
        ]);
        $yousef = $this->onboardEmployee([
            'name' => 'Yousef Kanaan',
            'login_email' => 'yousef.kanaan@ierp.com',
            'job_title' => 'Field Sales Representative',
            'phone' => '+971 50 555 0116',
            'use_base_salary' => true,
            'base_salary' => 3600.00,
        ]);
        $hana = $this->onboardEmployee([
            'name' => 'Hana Zureik',
            'login_email' => 'hana.zureik@ierp.com',
            'job_title' => 'Field Sales Representative',
            'phone' => '+971 50 555 0117',
            'use_base_salary' => true,
            'base_salary' => 3400.00,
        ]);

        $this->seedRania($rania, $previousMonth, $currentMonth, $smile, $bright, $manager, $payroll);
        $this->seedOmar($omar, $previousMonth, $currentMonth, $smile, $bright, $manager, $payroll);
        $this->seedKhaled($khaled, $previousMonth, $currentMonth, $smile, $bright, $manager, $payroll);
        $this->seedLayla($layla, $currentMonth, $smile, $bright, $rules, $manager, $admin);
        $this->seedNadia($nadia, $currentMonth);
        $this->seedYousef($yousef, $previousMonth, $smile, $bright, $manager, $payroll);
        $this->seedHana($hana, $previousMonth, $bright, $manager, $payroll);

        Auth::logout();
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

    private function customer(string $email): ?CustomerProfile
    {
        return CustomerProfile::query()->where('email', $email)->first();
    }

    private function variant(string $sku): ?ProductVariant
    {
        return ProductVariant::query()->where('sku', $sku)->first();
    }

    /** @return array{form4b: AiKeywordRule, dentalStone: AiKeywordRule, discount: AiKeywordRule} */
    private function seedAiKeywordRules(): array
    {
        return [
            'form4b' => AiKeywordRule::query()->firstOrCreate(
                ['keyword' => 'Form 4B'],
                ['product_variant_id' => $this->variant('FORMLABS-FORM-4B')?->getKey(), 'is_active' => true],
            ),
            'dentalStone' => AiKeywordRule::query()->firstOrCreate(
                ['keyword' => 'dental stone'],
                ['product_variant_id' => $this->variant('DENTSPLY-DENTAL-STONE-25KG')?->getKey(), 'is_active' => true],
            ),
            'discount' => AiKeywordRule::query()->firstOrCreate(
                ['keyword' => 'discount'],
                ['is_active' => true],
            ),
        ];
    }

    /** @param array<string, mixed> $data */
    private function onboardEmployee(array $data): EmployeeProfile
    {
        $existing = EmployeeProfile::withTrashed()
            ->whereHas('user', fn (Builder $query): Builder => $query->where('email', $data['login_email']))
            ->first();

        if ($existing instanceof EmployeeProfile) {
            return $existing;
        }

        return app(EmployeeOnboardingService::class)->onboard($data);
    }

    private function modelId(Model $model): int
    {
        $id = $model->getKey();

        if (! is_int($id)) {
            throw new LogicException('A persisted model with an integer key is required.');
        }

        return $id;
    }

    private function dateIn(Carbon $month, int $day): Carbon
    {
        $lastDay = $month->copy()->endOfMonth()->day;
        $date = Carbon::create($month->year, $month->month, min($day, $lastDay));

        if (! $date instanceof Carbon) {
            throw new LogicException('Failed to construct a calendar date for the demo seeder.');
        }

        return $date;
    }

    private function timeIn(Carbon $month, int $day, string $time): Carbon
    {
        return Carbon::parse($this->dateIn($month, $day)->toDateString().' '.$time);
    }

    /** @param array<string, mixed> $attributes */
    private function visit(array $attributes): CustomerVisit
    {
        return CustomerVisit::query()->create($attributes);
    }

    /** @param list<array{0: float, 1: float, 2: Carbon}> $points */
    private function logGpsTrail(CustomerVisit $visit, array $points): void
    {
        foreach ($points as [$latitude, $longitude, $recordedAt]) {
            $visit->gpsLogs()->create([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'recorded_at' => $recordedAt,
            ]);
        }
    }

    private function completeTaskAt(PlanTaskService $service, PlanTask $task, Carbon $completedAt, ?string $note = null): PlanTask
    {
        $task = $service->transition($task, PlanTaskStatus::Completed, $note);
        $task->forceFill(['completed_at' => $completedAt])->save();

        return $task->refresh();
    }

    private function attachDocument(CustomerVisit $visit, string $fileName): void
    {
        $visit->addMediaFromString(self::DemoDocumentPlaceholder)
            ->usingFileName($fileName)
            ->toMediaCollection('visit-attachments', 'local');
    }

    private function reviewVisit(CustomerVisit $visit, string $note): CustomerVisit
    {
        return app(VisitReviewService::class)->updateReviewNote($visit, $note);
    }

    private function attachVoiceNote(CustomerVisit $visit, EmployeeProfile $employee, ?string $language, int $durationSeconds): EmployeeVoiceNote
    {
        $voiceNote = EmployeeVoiceNote::query()->create([
            'customer_visit_id' => $visit->getKey(),
            'employee_id' => $employee->getKey(),
            'language' => $language,
            'duration_seconds' => $durationSeconds,
            'status' => VoiceNoteStatus::Transcribed,
        ]);

        $voiceNote->addMediaFromString(self::DemoAudioPlaceholder)
            ->usingFileName('voice-note-'.$this->modelId($voiceNote).'.m4a')
            ->toMediaCollection('voice-note-audio', 'local');

        return $voiceNote;
    }

    /** @param array<string, mixed> $data */
    private function transcribe(EmployeeVoiceNote $voiceNote, array $data): VoiceNoteTranscription
    {
        return VoiceNoteTranscription::query()->create([
            'employee_voice_note_id' => $voiceNote->getKey(),
            ...$data,
        ]);
    }

    private function draftOpportunity(VoiceNoteTranscription $transcription, ?AiKeywordRule $rule, string $summary): SalesOpportunityDraft
    {
        return SalesOpportunityDraft::query()->create([
            'voice_note_transcription_id' => $transcription->getKey(),
            'ai_keyword_rule_id' => $rule?->getKey(),
            'summary' => $summary,
            'status' => OpportunityDraftStatus::Draft,
        ]);
    }

    private function suggestBonus(EmployeeProfile $employee, SalesPlan $plan, ?SalesOpportunityDraft $draft, float $amount, string $reason): BonusSuggestion
    {
        return BonusSuggestion::query()->create([
            'employee_id' => $employee->getKey(),
            'sales_plan_id' => $plan->getKey(),
            'sales_opportunity_draft_id' => $draft?->getKey(),
            'amount' => $amount,
            'reason' => $reason,
            'status' => BonusSuggestionStatus::Pending,
        ]);
    }

    /**
     * Strong performer: a fully completed, salary-confirmed prior month
     * (including a bonus approved *after* the first confirmation, forcing a
     * realistic recalculation/supersession), plus an in-progress current
     * month with one overdue task.
     */
    private function seedRania(EmployeeProfile $employee, Carbon $previousMonth, Carbon $currentMonth, ?CustomerProfile $smile, ?CustomerProfile $bright, User $manager, User $payroll): void
    {
        if ($employee->salesPlans()->exists()) {
            return;
        }

        $planService = app(SalesPlanService::class);
        $taskService = app(PlanTaskService::class);

        $plan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'July 2026 Territory Plan',
            'month' => $previousMonth->toDateString(),
            'task_weight' => 35,
            'visit_weight' => 35,
            'schedule_weight' => 20,
            'work_time_weight' => 10,
            'required_visit_minutes' => 45,
        ]);

        $t1 = $taskService->create($plan, [
            'title' => 'Renew annual supply contract',
            'description' => 'Negotiate and close the annual supply contract renewal.',
            'customer_id' => $smile?->getKey(),
            'starts_at' => $this->dateIn($previousMonth, 1),
            'due_at' => $this->dateIn($previousMonth, 8),
        ]);
        $t2 = $taskService->create($plan, [
            'title' => 'Deliver Form 4B demo unit',
            'description' => 'Set up and demonstrate the Form 4B printer for the clinic team.',
            'customer_id' => $bright?->getKey(),
            'starts_at' => $this->dateIn($previousMonth, 5),
            'due_at' => $this->dateIn($previousMonth, 12),
        ]);
        $t3 = $taskService->create($plan, [
            'title' => 'Collect feedback on resin trial',
            'description' => 'Gather structured feedback on the Precision Model resin trial batch.',
            'customer_id' => $smile?->getKey(),
            'starts_at' => $this->dateIn($previousMonth, 10),
            'due_at' => $this->dateIn($previousMonth, 18),
        ]);
        $t4 = $taskService->create($plan, [
            'title' => 'Territory competitor survey',
            'description' => 'Document competitor pricing and offerings across the territory.',
            'customer_id' => null,
            'starts_at' => $this->dateIn($previousMonth, 12),
            'due_at' => $this->dateIn($previousMonth, 22),
        ]);
        $t5 = $taskService->create($plan, [
            'title' => 'Prepare Q3 pricing proposal',
            'description' => 'Draft and deliver the Q3 volume-discount pricing proposal.',
            'customer_id' => $bright?->getKey(),
            'starts_at' => $this->dateIn($previousMonth, 20),
            'due_at' => $this->dateIn($previousMonth, 28),
        ]);

        $planService->transition($plan, SalesPlanStatus::Active);
        $this->completeTaskAt($taskService, $t1, $this->timeIn($previousMonth, 7, '17:00'));
        $this->completeTaskAt($taskService, $t2, $this->timeIn($previousMonth, 11, '17:00'));
        $this->completeTaskAt($taskService, $t3, $this->timeIn($previousMonth, 17, '17:00'));
        $t4 = $taskService->transition($t4, PlanTaskStatus::InProgress);
        $this->completeTaskAt($taskService, $t4, $this->timeIn($previousMonth, 20, '17:00'));
        $this->completeTaskAt($taskService, $t5, $this->timeIn($previousMonth, 30, '17:00'), 'Delivered two days late due to a client scheduling conflict.');

        $contractVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t1->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 7, '09:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 7, '09:00'), 'checked_out_at' => $this->timeIn($previousMonth, 7, '09:50'),
            'outcome' => 'Renewed annual supply contract for another 12 months.', 'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($contractVisit, [
            [24.49340, 54.36850, $this->timeIn($previousMonth, 7, '09:00')],
            [24.49355, 54.36868, $this->timeIn($previousMonth, 7, '09:20')],
            [24.49361, 54.36879, $this->timeIn($previousMonth, 7, '09:50')],
        ]);
        $this->attachDocument($contractVisit, 'smile-dental-supply-contract-renewal.pdf');
        $this->reviewVisit($contractVisit, 'Verified signed renewal terms match the approved pricing tier.');

        $demoVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t2->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 11, '11:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 11, '11:00'), 'checked_out_at' => $this->timeIn($previousMonth, 11, '11:40'),
            'outcome' => 'Demonstrated Form 4B; clinic requested formal pricing.', 'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($demoVisit, [
            [24.45390, 54.37730, $this->timeIn($previousMonth, 11, '11:00')],
            [24.45403, 54.37744, $this->timeIn($previousMonth, 11, '11:20')],
            [24.45411, 54.37752, $this->timeIn($previousMonth, 11, '11:40')],
        ]);
        $note = $this->attachVoiceNote($demoVisit, $employee, 'en', 42);
        $this->transcribe($note, [
            'transcript' => 'Clinic team asked for a formal quote covering the Form 4B and a larger resin tank.',
            'confidence' => 88.20,
            'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
            'detected_language' => 'en',
            'provider' => 'openai.whisper-1',
            'status' => TranscriptionStatus::Succeeded,
        ]);
        $this->reviewVisit($demoVisit, 'Demo went well; pricing desk to follow up within the week.');

        $feedbackVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t3->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 17, '14:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 17, '14:00'), 'checked_out_at' => $this->timeIn($previousMonth, 17, '15:10'),
            'outcome' => 'Very positive feedback on Precision Model resin quality.', 'status' => VisitStatus::Completed,
        ]);
        $this->attachDocument($feedbackVisit, 'precision-model-resin-trial-feedback-form.pdf');
        $this->reviewVisit($feedbackVisit, 'Feedback form reviewed — no quality concerns raised.');

        $fieldVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t5->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 30, '10:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 30, '10:00'), 'checked_out_at' => $this->timeIn($previousMonth, 30, '10:50'),
            'outcome' => 'Delivered Q3 pricing proposal in person; discussed volume discount.', 'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($fieldVisit, [
            [24.45390, 54.37730, $this->timeIn($previousMonth, 30, '10:00')],
            [24.45402, 54.37748, $this->timeIn($previousMonth, 30, '10:12')],
            [24.45415, 54.37761, $this->timeIn($previousMonth, 30, '10:25')],
            [24.45409, 54.37769, $this->timeIn($previousMonth, 30, '10:38')],
            [24.45397, 54.37755, $this->timeIn($previousMonth, 30, '10:50')],
        ]);
        $this->attachDocument($fieldVisit, 'bright-orthodontics-q3-pricing-proposal.pdf');
        $this->reviewVisit($fieldVisit, 'Confirmed proposal terms match the approved pricing tier.');
        $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t1->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 22, '09:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 22, '09:00'), 'checked_out_at' => $this->timeIn($previousMonth, 22, '09:40'),
            'outcome' => 'Courtesy follow-up on the renewed supply contract; no new business raised.', 'status' => VisitStatus::Completed,
        ]);

        $planService->transition($plan, SalesPlanStatus::Completed);

        $bonus = $this->suggestBonus($employee, $plan, null, 300.00, 'Delivered the Q3 pricing proposal ahead of schedule and secured a 12-month contract renewal.');

        Auth::login($payroll);
        $calculation = app(SalaryCalculationService::class)->calculate($plan);
        app(SalaryRecalculationService::class)->confirm($calculation);
        app(BonusApprovalService::class)->approve($bonus, 'Approved — strong quarter close.');

        $recalculated = app(SalaryRecalculationService::class)->recalculate($plan);
        app(SalaryRecalculationService::class)->confirm($recalculated);
        Auth::login($manager);

        // Current, in-progress month.
        $augustPlan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'August 2026 Territory Plan',
            'month' => $currentMonth->toDateString(),
            'task_weight' => 35,
            'visit_weight' => 35,
            'schedule_weight' => 20,
            'work_time_weight' => 10,
            'required_visit_minutes' => 45,
        ]);
        $a1 = $taskService->create($augustPlan, [
            'title' => 'Quarterly business review — Smile Dental Clinic',
            'description' => 'Present the quarterly business review and renewal roadmap.',
            'customer_id' => $smile?->getKey(),
            'starts_at' => $this->dateIn($currentMonth, 1),
            'due_at' => $this->dateIn($currentMonth, 7),
        ]);
        $a2 = $taskService->create($augustPlan, [
            'title' => 'Introduce new surgical guide resin',
            'description' => 'Introduce the new surgical guide resin line to the clinical team.',
            'customer_id' => $bright?->getKey(),
            'starts_at' => $this->dateIn($currentMonth, 3),
            'due_at' => $this->dateIn($currentMonth, 12),
        ]);
        $a3 = $taskService->create($augustPlan, [
            'title' => 'Territory expansion proposal',
            'description' => 'Draft a proposal for expanding coverage into the northern territory.',
            'customer_id' => null,
            'starts_at' => $this->dateIn($currentMonth, 8),
            'due_at' => $this->dateIn($currentMonth, 20),
        ]);
        $taskService->create($augustPlan, [
            'title' => 'Send updated compliance documents',
            'description' => 'Circulate the updated compliance and certification documents.',
            'customer_id' => $bright?->getKey(),
            'starts_at' => $this->dateIn($currentMonth, 1),
            'due_at' => $this->dateIn($currentMonth, 5),
        ]);
        $planService->transition($augustPlan, SalesPlanStatus::Active);
        $this->completeTaskAt($taskService, $a1, $this->timeIn($currentMonth, 6, '17:00'));
        $taskService->transition($a2, PlanTaskStatus::InProgress);

        $qbrVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $a1->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($currentMonth, 6, '09:00'),
            'checked_in_at' => $this->timeIn($currentMonth, 6, '09:00'), 'checked_out_at' => $this->timeIn($currentMonth, 6, '09:55'),
            'outcome' => 'Reviewed Q2 performance with clinic management.', 'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($qbrVisit, [
            [24.49340, 54.36850, $this->timeIn($currentMonth, 6, '09:00')],
            [24.49352, 54.36864, $this->timeIn($currentMonth, 6, '09:25')],
            [24.49361, 54.36879, $this->timeIn($currentMonth, 6, '09:55')],
        ]);
        $this->attachDocument($qbrVisit, 'smile-dental-q2-business-review-deck.pdf');
        $this->reviewVisit($qbrVisit, 'QBR deck matches agreed renewal roadmap; no follow-up required.');
        $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $a2->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($currentMonth, 8, '10:00'),
            'checked_in_at' => $this->timeIn($currentMonth, 8, '10:00'), 'checked_out_at' => null,
            'outcome' => null, 'status' => VisitStatus::InProgress,
        ]);
        $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $a3->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($currentMonth, 15, '10:00'),
            'checked_in_at' => null, 'checked_out_at' => null,
            'outcome' => null, 'status' => VisitStatus::Planned,
        ]);
    }

    /**
     * Moderate performer: a completed prior month whose salary has been
     * calculated but is still waiting in the Payroll Officer's confirmation
     * queue, plus a current month with one overdue task.
     */
    private function seedOmar(EmployeeProfile $employee, Carbon $previousMonth, Carbon $currentMonth, ?CustomerProfile $smile, ?CustomerProfile $bright, User $manager, User $payroll): void
    {
        if ($employee->salesPlans()->exists()) {
            return;
        }

        $planService = app(SalesPlanService::class);
        $taskService = app(PlanTaskService::class);

        $plan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'July 2026 Sales Plan',
            'month' => $previousMonth->toDateString(),
            'task_weight' => 40, 'visit_weight' => 30, 'schedule_weight' => 20, 'work_time_weight' => 10,
            'required_visit_minutes' => null,
        ]);

        $t1 = $taskService->create($plan, [
            'title' => 'Restock main clinic samples', 'description' => 'Replenish the sample inventory at the main clinic.',
            'customer_id' => $smile?->getKey(), 'starts_at' => $this->dateIn($previousMonth, 2), 'due_at' => $this->dateIn($previousMonth, 9),
        ]);
        $t2 = $taskService->create($plan, [
            'title' => 'Cold-chain resin delivery', 'description' => 'Deliver a cold-chain resin restock to storage.',
            'customer_id' => null, 'starts_at' => $this->dateIn($previousMonth, 5), 'due_at' => $this->dateIn($previousMonth, 15),
        ]);
        $t3 = $taskService->create($plan, [
            'title' => 'Follow up unpaid invoice', 'description' => "Chase the outstanding invoice with the clinic's finance office.",
            'customer_id' => $bright?->getKey(), 'starts_at' => $this->dateIn($previousMonth, 10), 'due_at' => $this->dateIn($previousMonth, 20),
        ]);
        $t4 = $taskService->create($plan, [
            'title' => 'Monthly report submission', 'description' => 'Submit the monthly territory activity report.',
            'customer_id' => null, 'starts_at' => $this->dateIn($previousMonth, 20), 'due_at' => $this->dateIn($previousMonth, 30),
        ]);

        $planService->transition($plan, SalesPlanStatus::Active);
        $this->completeTaskAt($taskService, $t1, $this->timeIn($previousMonth, 9, '17:00'));
        $this->completeTaskAt($taskService, $t2, $this->timeIn($previousMonth, 18, '17:00'), 'Delivered late — supplier truck was delayed at customs.');
        $taskService->transition($t3, PlanTaskStatus::Cancelled, 'Customer settled the invoice directly with finance; no visit needed.');
        $this->completeTaskAt($taskService, $t4, $this->timeIn($previousMonth, 29, '17:00'));

        $restockVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t1->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 9, '09:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 9, '09:00'), 'checked_out_at' => $this->timeIn($previousMonth, 9, '09:25'),
            'outcome' => 'Restocked sample inventory.', 'status' => VisitStatus::Completed,
        ]);
        $this->attachDocument($restockVisit, 'smile-dental-sample-delivery-note.pdf');
        $this->reviewVisit($restockVisit, 'Delivery note matches the sample restock request.');

        $coldChainVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t2->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 18, '13:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 18, '13:00'), 'checked_out_at' => $this->timeIn($previousMonth, 18, '13:35'),
            'outcome' => 'Delivered cold-chain resin restock to storage.', 'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($coldChainVisit, [
            [24.48532, 54.35120, $this->timeIn($previousMonth, 18, '13:00')],
            [24.48549, 54.35138, $this->timeIn($previousMonth, 18, '13:18')],
            [24.48557, 54.35151, $this->timeIn($previousMonth, 18, '13:35')],
        ]);

        $reportVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t4->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 29, '15:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 29, '15:00'), 'checked_out_at' => $this->timeIn($previousMonth, 29, '15:20'),
            'outcome' => 'Submitted monthly report to management.', 'status' => VisitStatus::Completed,
        ]);
        $this->attachDocument($reportVisit, 'omar-nasser-july-2026-territory-report.pdf');
        $this->reviewVisit($reportVisit, 'Report received and filed; no discrepancies noted.');

        $planService->transition($plan, SalesPlanStatus::Completed);

        Auth::login($payroll);
        app(SalaryCalculationService::class)->calculate($plan);
        Auth::login($manager);

        $augustPlan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'August 2026 Sales Plan',
            'month' => $currentMonth->toDateString(),
            'task_weight' => 40, 'visit_weight' => 30, 'schedule_weight' => 20, 'work_time_weight' => 10,
            'required_visit_minutes' => null,
        ]);
        $a1 = $taskService->create($augustPlan, [
            'title' => 'Restock cold storage', 'description' => 'Replenish cold-chain storage ahead of the month.',
            'customer_id' => null, 'starts_at' => $this->dateIn($currentMonth, 1), 'due_at' => $this->dateIn($currentMonth, 6),
        ]);
        $taskService->create($augustPlan, [
            'title' => 'Visit Smile Dental for reorder', 'description' => 'Follow up on the reorder request from the clinic.',
            'customer_id' => $smile?->getKey(), 'starts_at' => $this->dateIn($currentMonth, 2), 'due_at' => $this->dateIn($currentMonth, 4),
        ]);
        $a3 = $taskService->create($augustPlan, [
            'title' => 'Prepare August invoices', 'description' => 'Prepare and send the August billing cycle invoices.',
            'customer_id' => null, 'starts_at' => $this->dateIn($currentMonth, 8), 'due_at' => $this->dateIn($currentMonth, 18),
        ]);
        $planService->transition($augustPlan, SalesPlanStatus::Active);
        $this->completeTaskAt($taskService, $a1, $this->timeIn($currentMonth, 5, '17:00'));
        $taskService->transition($a3, PlanTaskStatus::InProgress);

        $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $a1->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($currentMonth, 5, '09:00'),
            'checked_in_at' => $this->timeIn($currentMonth, 5, '09:00'), 'checked_out_at' => $this->timeIn($currentMonth, 5, '09:20'),
            'outcome' => 'Cold storage restocked ahead of schedule.', 'status' => VisitStatus::Completed,
        ]);
    }

    /**
     * Weak performer: a completed prior month with a cancelled task, a
     * missed visit, and no salary calculation yet at all — the "needs
     * attention first" state, distinct from Omar's "needs confirmation"
     * state. A bonus suggestion is raised and rejected.
     */
    private function seedKhaled(EmployeeProfile $employee, Carbon $previousMonth, Carbon $currentMonth, ?CustomerProfile $smile, ?CustomerProfile $bright, User $manager, User $payroll): void
    {
        if ($employee->salesPlans()->exists()) {
            return;
        }

        $planService = app(SalesPlanService::class);
        $taskService = app(PlanTaskService::class);

        $plan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'July 2026 Sales Plan',
            'month' => $previousMonth->toDateString(),
            'task_weight' => 30, 'visit_weight' => 30, 'schedule_weight' => 25, 'work_time_weight' => 15,
            'required_visit_minutes' => null,
        ]);

        $t1 = $taskService->create($plan, [
            'title' => 'Deliver samples — Bright Orthodontics', 'description' => 'Deliver the requested product samples.',
            'customer_id' => $bright?->getKey(), 'starts_at' => $this->dateIn($previousMonth, 1), 'due_at' => $this->dateIn($previousMonth, 10),
        ]);
        $t2 = $taskService->create($plan, [
            'title' => 'Collect signed purchase order', 'description' => 'Collect the signed purchase order from the clinic.',
            'customer_id' => $smile?->getKey(), 'starts_at' => $this->dateIn($previousMonth, 5), 'due_at' => $this->dateIn($previousMonth, 12),
        ]);
        $t3 = $taskService->create($plan, [
            'title' => 'Territory cold calls', 'description' => 'Cold-call prospective clinics across the assigned territory.',
            'customer_id' => null, 'starts_at' => $this->dateIn($previousMonth, 10), 'due_at' => $this->dateIn($previousMonth, 20),
        ]);
        $t4 = $taskService->create($plan, [
            'title' => 'Submit expense report', 'description' => 'Submit the monthly travel expense report.',
            'customer_id' => null, 'starts_at' => $this->dateIn($previousMonth, 20), 'due_at' => $this->dateIn($previousMonth, 28),
        ]);

        $planService->transition($plan, SalesPlanStatus::Active);
        $this->completeTaskAt($taskService, $t1, $this->timeIn($previousMonth, 15, '17:00'), 'Delivered five days late.');
        $taskService->transition($t2, PlanTaskStatus::Cancelled, 'Client backed out of the purchase before signing.');
        $this->completeTaskAt($taskService, $t3, $this->timeIn($previousMonth, 25, '17:00'), 'Completed five days late.');
        $taskService->transition($t4, PlanTaskStatus::Cancelled, 'Not completed before month close; cancelled during close-out.');

        $samplesVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t1->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 15, '10:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 15, '10:00'), 'checked_out_at' => $this->timeIn($previousMonth, 15, '10:15'),
            'outcome' => 'Delivered samples; short visit due to time constraints.', 'status' => VisitStatus::Completed,
        ]);
        $this->reviewVisit($samplesVisit, 'Duration below the required minimum — flagged for coaching.');
        $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t2->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 13, '09:00'),
            'checked_in_at' => null, 'checked_out_at' => null, 'outcome' => null, 'status' => VisitStatus::Missed,
        ]);
        $coldCallVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t3->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 25, '16:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 25, '16:00'), 'checked_out_at' => $this->timeIn($previousMonth, 25, '16:10'),
            'outcome' => 'Brief cold-call follow-up.', 'status' => VisitStatus::Completed,
        ]);
        $note = $this->attachVoiceNote($coldCallVisit, $employee, 'en', 28);
        $this->transcribe($note, [
            'transcript' => 'Prospect asked for pricing on the entry-level printer bundle, to follow up next month.',
            'confidence' => 74.10,
            'confidence_source' => TranscriptionConfidenceSource::DerivedFromLogProb,
            'detected_language' => 'en',
            'provider' => 'openai.whisper-1',
            'status' => TranscriptionStatus::Succeeded,
        ]);

        $planService->transition($plan, SalesPlanStatus::Completed);

        $bonus = $this->suggestBonus($employee, $plan, null, 100.00, 'Suggested attendance bonus for full month coverage.');
        Auth::login($payroll);
        app(BonusApprovalService::class)->reject($bonus, 'Rejected — overall performance below threshold and two tasks slipped past their due date.');
        Auth::login($manager);

        $augustPlan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'August 2026 Sales Plan',
            'month' => $currentMonth->toDateString(),
            'task_weight' => 30, 'visit_weight' => 30, 'schedule_weight' => 25, 'work_time_weight' => 15,
            'required_visit_minutes' => null,
        ]);
        $b1 = $taskService->create($augustPlan, [
            'title' => 'Reattempt purchase order collection', 'description' => 'Follow up again on the pending purchase order.',
            'customer_id' => $smile?->getKey(), 'starts_at' => $this->dateIn($currentMonth, 1), 'due_at' => $this->dateIn($currentMonth, 7),
        ]);
        $taskService->create($augustPlan, [
            'title' => 'Cold call new prospects', 'description' => 'Prospect new clinics in the northern district.',
            'customer_id' => null, 'starts_at' => $this->dateIn($currentMonth, 8), 'due_at' => $this->dateIn($currentMonth, 25),
        ]);
        $planService->transition($augustPlan, SalesPlanStatus::Active);
        $taskService->transition($b1, PlanTaskStatus::Cancelled, 'Customer declined again; escalated to account management.');

        $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $b1->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($currentMonth, 6, '09:00'),
            'checked_in_at' => null, 'checked_out_at' => null, 'outcome' => null, 'status' => VisitStatus::Missed,
        ]);
    }

    /**
     * Performance-only salary mode with the full voice-note-to-AI-opportunity
     * pipeline: a field-recorded visit produces two voice notes, one
     * approved opportunity and one still awaiting review, and a bonus
     * suggestion still in the Payroll Officer's queue.
     *
     * @param  array{form4b: AiKeywordRule, dentalStone: AiKeywordRule, discount: AiKeywordRule}  $rules
     */
    private function seedLayla(EmployeeProfile $employee, Carbon $currentMonth, ?CustomerProfile $smile, ?CustomerProfile $bright, array $rules, User $manager, User $admin): void
    {
        if ($employee->salesPlans()->exists()) {
            return;
        }

        $planService = app(SalesPlanService::class);
        $taskService = app(PlanTaskService::class);

        $plan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'August 2026 Clinical Accounts Plan',
            'month' => $currentMonth->toDateString(),
            'task_weight' => 30, 'visit_weight' => 40, 'schedule_weight' => 20, 'work_time_weight' => 10,
            'required_visit_minutes' => 40,
        ]);

        $t1 = $taskService->create($plan, [
            'title' => 'Clinical in-service — Bright Orthodontics', 'description' => 'Run a clinical in-service on the new resin line.',
            'customer_id' => $bright?->getKey(), 'starts_at' => $this->dateIn($currentMonth, 1), 'due_at' => $this->dateIn($currentMonth, 7),
        ]);
        $t2 = $taskService->create($plan, [
            'title' => 'Product demo — Smile Dental Clinic', 'description' => 'Demonstrate the Formlabs printer lineup.',
            'customer_id' => $smile?->getKey(), 'starts_at' => $this->dateIn($currentMonth, 4), 'due_at' => $this->dateIn($currentMonth, 11),
        ]);
        $taskService->create($plan, [
            'title' => 'Compile monthly clinical usage report', 'description' => 'Summarize clinical product usage across accounts.',
            'customer_id' => null, 'starts_at' => $this->dateIn($currentMonth, 8), 'due_at' => $this->dateIn($currentMonth, 22),
        ]);

        $planService->transition($plan, SalesPlanStatus::Active);
        $this->completeTaskAt($taskService, $t1, $this->timeIn($currentMonth, 6, '17:00'));
        $taskService->transition($t2, PlanTaskStatus::InProgress);

        $fieldVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t1->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($currentMonth, 6, '09:00'),
            'checked_in_at' => $this->timeIn($currentMonth, 6, '09:00'), 'checked_out_at' => $this->timeIn($currentMonth, 6, '10:00'),
            'outcome' => 'Delivered in-service training; discussed Form 4B upgrade interest and bulk dental stone pricing.',
            'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($fieldVisit, [
            [24.45388, 54.37725, $this->timeIn($currentMonth, 6, '09:00')],
            [24.45401, 54.37739, $this->timeIn($currentMonth, 6, '09:15')],
            [24.45417, 54.37752, $this->timeIn($currentMonth, 6, '09:30')],
            [24.45422, 54.37744, $this->timeIn($currentMonth, 6, '09:45')],
            [24.45409, 54.37731, $this->timeIn($currentMonth, 6, '10:00')],
        ]);
        $this->attachDocument($fieldVisit, 'bright-orthodontics-in-service-attendance-sheet.pdf');
        $this->reviewVisit($fieldVisit, 'Confirmed clinic interest — routed to AI opportunity review.');

        $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t2->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($currentMonth, 8, '11:00'),
            'checked_in_at' => $this->timeIn($currentMonth, 8, '11:00'), 'checked_out_at' => null,
            'outcome' => null, 'status' => VisitStatus::InProgress,
        ]);

        $note1 = $this->attachVoiceNote($fieldVisit, $employee, 'ar', 96);
        $transcription1 = $this->transcribe($note1, [
            'transcript' => 'العميل مهتم بترقية الطابعة إلى Form 4B مع خزان راتنج أكبر.',
            'confidence' => 92.50,
            'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
            'detected_language' => 'ar',
            'provider' => 'openai.whisper-1',
            'status' => TranscriptionStatus::Succeeded,
        ]);
        $opportunity1 = $this->draftOpportunity(
            $transcription1,
            $rules['form4b'],
            'Bright Orthodontics is interested in upgrading to the Form 4B printer with a larger resin tank.',
        );

        $note2 = $this->attachVoiceNote($fieldVisit, $employee, 'ar', 54);
        $transcription2 = $this->transcribe($note2, [
            'transcript' => 'كما سأل العميل عن أسعار حجر الأسنان بالجملة.',
            'confidence' => 78.40,
            'confidence_source' => TranscriptionConfidenceSource::DerivedFromLogProb,
            'detected_language' => 'ar',
            'provider' => 'openai.whisper-1',
            'status' => TranscriptionStatus::Succeeded,
        ]);
        $this->draftOpportunity(
            $transcription2,
            $rules['dentalStone'],
            'Bright Orthodontics asked about bulk pricing for dental stone.',
        );

        Auth::login($admin);
        app(OpportunityReviewService::class)->approve($opportunity1, 'Confirmed with clinic manager — routed to sales for follow-up.');
        Auth::login($manager);

        $this->suggestBonus($employee, $plan, $opportunity1, 250.00, 'AI-flagged upsell opportunity confirmed with Bright Orthodontics for a Form 4B upgrade.');
    }

    /**
     * Brand-new hire: a current-month plan still in Draft, with a single
     * onboarding task and no execution history at all.
     */
    private function seedNadia(EmployeeProfile $employee, Carbon $currentMonth): void
    {
        if ($employee->salesPlans()->exists()) {
            return;
        }

        $plan = app(SalesPlanService::class)->create([
            'employee_id' => $employee->getKey(),
            'name' => 'August 2026 Onboarding Plan',
            'month' => $currentMonth->toDateString(),
            'task_weight' => 40, 'visit_weight' => 30, 'schedule_weight' => 20, 'work_time_weight' => 10,
            'required_visit_minutes' => null,
        ]);

        app(PlanTaskService::class)->create($plan, [
            'title' => 'Complete onboarding & product certification',
            'description' => 'Finish the product certification course before taking on a territory.',
            'customer_id' => null,
            'starts_at' => $this->dateIn($currentMonth, 8),
            'due_at' => $this->dateIn($currentMonth, 15),
        ]);
    }

    /**
     * Departed employee whose access is disabled but whose profile and
     * history remain intact (not archived) — the "access disabled" state.
     */
    private function seedYousef(EmployeeProfile $employee, Carbon $previousMonth, ?CustomerProfile $smile, ?CustomerProfile $bright, User $manager, User $payroll): void
    {
        if ($employee->salesPlans()->exists()) {
            return;
        }

        $planService = app(SalesPlanService::class);
        $taskService = app(PlanTaskService::class);

        $plan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'July 2026 Sales Plan',
            'month' => $previousMonth->toDateString(),
            'task_weight' => 40, 'visit_weight' => 30, 'schedule_weight' => 20, 'work_time_weight' => 10,
            'required_visit_minutes' => null,
        ]);

        $t1 = $taskService->create($plan, [
            'title' => 'Deliver resin restock — Smile Dental', 'description' => 'Deliver the scheduled resin restock.',
            'customer_id' => $smile?->getKey(), 'starts_at' => $this->dateIn($previousMonth, 1), 'due_at' => $this->dateIn($previousMonth, 8),
        ]);
        $t2 = $taskService->create($plan, [
            'title' => 'Route optimization review', 'description' => 'Review the delivery route with logistics before handover.',
            'customer_id' => null, 'starts_at' => $this->dateIn($previousMonth, 8), 'due_at' => $this->dateIn($previousMonth, 18),
        ]);

        $planService->transition($plan, SalesPlanStatus::Active);
        $this->completeTaskAt($taskService, $t1, $this->timeIn($previousMonth, 7, '17:00'));
        $this->completeTaskAt($taskService, $t2, $this->timeIn($previousMonth, 16, '17:00'));

        $deliveryVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t1->getKey(), 'customer_id' => $smile?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 7, '09:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 7, '09:00'), 'checked_out_at' => $this->timeIn($previousMonth, 7, '09:35'),
            'outcome' => 'Delivered resin restock.', 'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($deliveryVisit, [
            [24.48532, 54.35120, $this->timeIn($previousMonth, 7, '09:00')],
            [24.48546, 54.35134, $this->timeIn($previousMonth, 7, '09:18')],
            [24.48555, 54.35149, $this->timeIn($previousMonth, 7, '09:35')],
        ]);
        $this->attachDocument($deliveryVisit, 'smile-dental-resin-delivery-note.pdf');
        $this->reviewVisit($deliveryVisit, 'Delivery note reconciles with the restock request.');

        $routeVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t2->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 12, '09:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 12, '09:00'), 'checked_out_at' => $this->timeIn($previousMonth, 12, '09:40'),
            'outcome' => 'Reviewed delivery route optimization with clinic logistics contact.', 'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($routeVisit, [
            [24.45390, 54.37730, $this->timeIn($previousMonth, 12, '09:00')],
            [24.45403, 54.37744, $this->timeIn($previousMonth, 12, '09:15')],
            [24.45416, 54.37758, $this->timeIn($previousMonth, 12, '09:28')],
            [24.45407, 54.37749, $this->timeIn($previousMonth, 12, '09:40')],
        ]);
        $this->attachDocument($routeVisit, 'bright-orthodontics-route-optimization-notes.pdf');
        $note = $this->attachVoiceNote($routeVisit, $employee, 'en', 61);
        $this->transcribe($note, [
            'transcript' => 'Logistics contact agreed to shift the weekly delivery window earlier to avoid clinic peak hours.',
            'confidence' => 90.30,
            'confidence_source' => TranscriptionConfidenceSource::ProviderReported,
            'detected_language' => 'en',
            'provider' => 'openai.whisper-1',
            'status' => TranscriptionStatus::Succeeded,
        ]);
        $this->reviewVisit($routeVisit, 'Route change confirmed with logistics; no further action needed.');

        $planService->transition($plan, SalesPlanStatus::Completed);

        Auth::login($payroll);
        $calculation = app(SalaryCalculationService::class)->calculate($plan);
        app(SalaryRecalculationService::class)->confirm($calculation);
        Auth::login($manager);

        app(EmployeeAccessService::class)->disable($employee);
    }

    /**
     * Archived employee (soft-deleted profile) whose plan/task/visit/salary
     * history remains intact and queryable, per FR-013.
     */
    private function seedHana(EmployeeProfile $employee, Carbon $previousMonth, ?CustomerProfile $bright, User $manager, User $payroll): void
    {
        if ($employee->salesPlans()->exists()) {
            return;
        }

        $planService = app(SalesPlanService::class);
        $taskService = app(PlanTaskService::class);

        $plan = $planService->create([
            'employee_id' => $employee->getKey(),
            'name' => 'July 2026 Handover Plan',
            'month' => $previousMonth->toDateString(),
            'task_weight' => 40, 'visit_weight' => 30, 'schedule_weight' => 20, 'work_time_weight' => 10,
            'required_visit_minutes' => null,
        ]);

        $t1 = $taskService->create($plan, [
            'title' => 'Final handover visit — Bright Orthodontics', 'description' => 'Introduce the successor account manager.',
            'customer_id' => $bright?->getKey(), 'starts_at' => $this->dateIn($previousMonth, 1), 'due_at' => $this->dateIn($previousMonth, 10),
        ]);
        $t2 = $taskService->create($plan, [
            'title' => 'Close out territory notes', 'description' => 'Document open items for the incoming account manager.',
            'customer_id' => null, 'starts_at' => $this->dateIn($previousMonth, 10), 'due_at' => $this->dateIn($previousMonth, 20),
        ]);

        $planService->transition($plan, SalesPlanStatus::Active);
        $this->completeTaskAt($taskService, $t1, $this->timeIn($previousMonth, 9, '17:00'));
        $this->completeTaskAt($taskService, $t2, $this->timeIn($previousMonth, 18, '17:00'));

        $handoverVisit = $this->visit([
            'employee_id' => $employee->getKey(), 'plan_task_id' => $t1->getKey(), 'customer_id' => $bright?->getKey(),
            'planned_at' => $this->timeIn($previousMonth, 9, '09:00'),
            'checked_in_at' => $this->timeIn($previousMonth, 9, '09:00'), 'checked_out_at' => $this->timeIn($previousMonth, 9, '09:30'),
            'outcome' => 'Final handover visit before territory transition.', 'status' => VisitStatus::Completed,
        ]);
        $this->logGpsTrail($handoverVisit, [
            [24.45372, 54.37698, $this->timeIn($previousMonth, 9, '09:00')],
            [24.45384, 54.37712, $this->timeIn($previousMonth, 9, '09:10')],
            [24.45391, 54.37705, $this->timeIn($previousMonth, 9, '09:20')],
            [24.45379, 54.37690, $this->timeIn($previousMonth, 9, '09:30')],
        ]);
        $this->attachDocument($handoverVisit, 'bright-orthodontics-handover-notes.pdf');
        $this->reviewVisit($handoverVisit, 'Handover notes confirmed complete; successor briefed on open items.');

        $planService->transition($plan, SalesPlanStatus::Completed);

        Auth::login($payroll);
        $calculation = app(SalaryCalculationService::class)->calculate($plan);
        app(SalaryRecalculationService::class)->confirm($calculation);
        Auth::login($manager);

        app(EmployeeAccessService::class)->archive($employee);
    }
}
