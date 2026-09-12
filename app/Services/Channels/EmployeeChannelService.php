<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Data\Sales\OpportunityData;
use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Enums\LeadSource;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVoiceNote;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Crm\InteractionService;
use App\Services\Crm\LeadService;
use App\Services\Employees\EmployeeVanSaleService;
use App\Services\Employees\EmployeeVisitFieldService;
use App\Services\Employees\VoiceNoteIntakeService;
use App\Services\Sales\OpportunityService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

final readonly class EmployeeChannelService
{
    public function __construct(
        private EmployeeVisitFieldService $fieldVisits,
        private VoiceNoteIntakeService $voiceNotes,
        private LeadService $leads,
        private InteractionService $interactions,
        private OpportunityService $opportunities,
        private EmployeeVanSaleService $vanSales,
    ) {}

    /** @return list<array<string, mixed>> */
    public function tasks(User $actor): array
    {
        $profile = $this->profile($actor);

        return PlanTask::query()
            ->whereHas('salesPlan', fn ($query) => $query->where('employee_id', $profile->getKey()))
            ->with('customer:id,company_name,phone,address,city')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (PlanTask $task): array => [
                'id' => (int) $task->getKey(),
                'sales_plan_id' => (int) $task->sales_plan_id,
                'customer_id' => $task->customer_id,
                'customer' => $task->customer?->company_name,
                'title' => (string) $task->title,
                'description' => $task->description,
                'status' => $task->status->value,
                'starts_at' => $task->starts_at?->toIso8601String(),
                'due_at' => $task->due_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function visits(User $actor): array
    {
        $profile = $this->profile($actor);

        return CustomerVisit::query()
            ->where('employee_id', $profile->getKey())
            ->with(['customer:id,company_name,phone,address,city', 'gpsLogs'])
            ->orderByDesc('planned_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerVisit $visit): array => $this->visitData($visit))
            ->all();
    }

    /** @return array<string, mixed> */
    public function checkIn(User $actor, int $visitId, float $latitude, float $longitude): array
    {
        return $this->visitData($this->fieldVisits->checkIn($actor, $visitId, $latitude, $longitude)->load('gpsLogs'));
    }

    /** @return array<string, mixed> */
    public function checkOut(User $actor, int $visitId, float $latitude, float $longitude, ?string $outcome): array
    {
        return $this->visitData($this->fieldVisits->checkOut($actor, $visitId, $latitude, $longitude, $outcome)->load('gpsLogs'));
    }

    /** @return array<string, mixed> */
    public function voiceNote(
        User $actor,
        int $visitId,
        UploadedFile $audio,
        ?string $language,
        ?int $durationSeconds,
    ): array {
        $visit = $this->ownedVisit($actor, $visitId);
        $path = $audio->store('employee-voice-notes', 'local');

        if (! is_string($path)) {
            throw new DomainException('The voice note could not be stored.');
        }

        $note = $this->voiceNotes->intake(
            $visit,
            $path,
            $audio->getClientOriginalName(),
            $language,
            $durationSeconds,
        );

        return $this->voiceNoteData($note);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createLead(User $actor, array $data): array
    {
        $this->profile($actor);
        $lead = $this->leads->create(new LeadData(
            source: LeadSource::from((string) $data['source']),
            sourceDetail: is_string($data['source_detail'] ?? null) ? $data['source_detail'] : null,
            campaignId: is_numeric($data['campaign_id'] ?? null) ? (int) $data['campaign_id'] : null,
            firstName: is_string($data['first_name'] ?? null) ? $data['first_name'] : null,
            lastName: is_string($data['last_name'] ?? null) ? $data['last_name'] : null,
            companyName: is_string($data['company_name'] ?? null) ? $data['company_name'] : null,
            jobTitle: is_string($data['job_title'] ?? null) ? $data['job_title'] : null,
            email: is_string($data['email'] ?? null) ? $data['email'] : null,
            phone: is_string($data['phone'] ?? null) ? $data['phone'] : null,
            preferredLanguage: is_string($data['preferred_language'] ?? null) ? $data['preferred_language'] : 'en',
            assignedTo: (int) $actor->getKey(),
        ), $actor);

        activity()->performedOn($lead)->causedBy($actor)
            ->withProperties(['source_channel' => 'employee_api'])
            ->log('crm.lead.employee_captured');

        return $this->leadData($lead);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createInteraction(User $actor, array $data): array
    {
        $profile = $this->profile($actor);
        $subject = match ((string) $data['subject_type']) {
            'customer' => CustomerProfile::query()->whereKey((int) $data['subject_id'])->where('is_active', true)->firstOrFail(),
            'lead' => Lead::query()->whereKey((int) $data['subject_id'])->firstOrFail(),
            default => throw new DomainException('Unsupported interaction subject type.'),
        };

        $visitId = is_numeric($data['customer_visit_id'] ?? null) ? (int) $data['customer_visit_id'] : null;
        if ($visitId !== null && ! CustomerVisit::query()->whereKey($visitId)->where('employee_id', $profile->getKey())->exists()) {
            throw new DomainException('The interaction visit is not assigned to this employee.');
        }

        $interaction = $this->interactions->log(new InteractionData(
            subject: $subject,
            type: InteractionType::from((string) $data['type']),
            direction: InteractionDirection::from((string) $data['direction']),
            occurredAt: isset($data['occurred_at']) ? Carbon::parse((string) $data['occurred_at']) : now(),
            summary: (string) $data['summary'],
            outcome: is_string($data['outcome'] ?? null) ? InteractionOutcome::from($data['outcome']) : null,
            notes: is_string($data['notes'] ?? null) ? $data['notes'] : null,
            customerVisitId: $visitId,
            ticketId: is_numeric($data['ticket_id'] ?? null) ? (int) $data['ticket_id'] : null,
        ), $actor);

        activity()->performedOn($interaction)->causedBy($actor)
            ->withProperties(['source_channel' => 'employee_api'])
            ->log('crm.interaction.employee_captured');

        return $this->interactionData($interaction);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createOpportunity(User $actor, array $data): array
    {
        $this->profile($actor);
        $opportunity = $this->opportunities->create(new OpportunityData(
            summary: (string) $data['summary'],
            customerId: is_numeric($data['customer_id'] ?? null) ? (int) $data['customer_id'] : null,
            leadId: is_numeric($data['lead_id'] ?? null) ? (int) $data['lead_id'] : null,
            title: is_string($data['title'] ?? null) ? $data['title'] : null,
            estimatedValueMinor: is_numeric($data['estimated_value_minor'] ?? null) ? (int) $data['estimated_value_minor'] : null,
            currency: is_string($data['currency'] ?? null) ? mb_strtoupper($data['currency']) : 'AED',
            expectedCloseDate: is_string($data['expected_close_date'] ?? null) ? $data['expected_close_date'] : null,
            probabilityPercent: is_numeric($data['probability_percent'] ?? null) ? (int) $data['probability_percent'] : null,
            ownerId: (int) $actor->getKey(),
        ), $actor);

        activity()->performedOn($opportunity)->causedBy($actor)
            ->withProperties(['source_channel' => 'employee_api'])
            ->log('sales.opportunity.employee_created');

        return $this->opportunityData($opportunity);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function createVanSale(User $actor, array $data): array
    {
        $result = $this->vanSales->create($actor, $data);
        $order = $result['order'];
        $invoice = $result['invoice'];

        return [
            'order' => [
                'id' => (int) $order->getKey(),
                'number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'grand_total' => $order->grand_total !== null ? (string) $order->grand_total : null,
            ],
            'invoice' => [
                'id' => (int) $invoice->getKey(),
                'number' => (string) $invoice->invoice_number,
                'status' => $invoice->status->value,
                'total_amount' => (string) $invoice->total_amount,
            ],
        ];
    }

    private function profile(User $actor): EmployeeProfile
    {
        $profile = $actor->employeeProfile;

        if (! $actor->isEmployee() || ! $profile instanceof EmployeeProfile || ! $profile->is_active) {
            throw new DomainException('An active employee profile is required.');
        }

        return $profile;
    }

    private function ownedVisit(User $actor, int $visitId): CustomerVisit
    {
        $profile = $this->profile($actor);

        return CustomerVisit::query()
            ->whereKey($visitId)
            ->where('employee_id', $profile->getKey())
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function visitData(CustomerVisit $visit): array
    {
        return [
            'id' => (int) $visit->getKey(),
            'customer_id' => (int) $visit->customer_id,
            'customer' => $visit->customer?->company_name,
            'status' => $visit->status->value,
            'planned_at' => $visit->planned_at?->toIso8601String(),
            'checked_in_at' => $visit->checked_in_at?->toIso8601String(),
            'checked_out_at' => $visit->checked_out_at?->toIso8601String(),
            'duration_minutes' => $visit->durationMinutes(),
            'outcome' => $visit->outcome,
            'gps_points' => $visit->relationLoaded('gpsLogs') ? $visit->gpsLogs->map(fn ($point): array => [
                'latitude' => (string) $point->latitude,
                'longitude' => (string) $point->longitude,
                'recorded_at' => $point->recorded_at?->toIso8601String(),
            ])->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function voiceNoteData(EmployeeVoiceNote $note): array
    {
        return [
            'id' => (int) $note->getKey(),
            'customer_visit_id' => (int) $note->customer_visit_id,
            'language' => $note->language,
            'duration_seconds' => $note->duration_seconds,
            'transcription_status' => $note->transcription?->status?->value,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function leadData(Lead $lead): array
    {
        return [
            'id' => (int) $lead->getKey(),
            'number' => (string) $lead->lead_number,
            'status' => $lead->status->value,
            'source' => $lead->source->value,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'company_name' => $lead->company_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
        ];
    }

    /** @return array<string, mixed> */
    private function interactionData(Interaction $interaction): array
    {
        return [
            'id' => (int) $interaction->getKey(),
            'subject_type' => (string) $interaction->subject_type,
            'subject_id' => (int) $interaction->subject_id,
            'type' => $interaction->type->value,
            'direction' => $interaction->direction->value,
            'outcome' => $interaction->outcome?->value,
            'occurred_at' => $interaction->occurred_at->toIso8601String(),
            'summary' => (string) $interaction->summary,
        ];
    }

    /** @return array<string, mixed> */
    private function opportunityData(SalesOpportunity $opportunity): array
    {
        return [
            'id' => (int) $opportunity->getKey(),
            'status' => $opportunity->status->value,
            'stage' => $opportunity->stage->value,
            'origin' => $opportunity->origin->value,
            'customer_id' => $opportunity->customer_id,
            'lead_id' => $opportunity->lead_id,
            'title' => $opportunity->title,
            'summary' => (string) $opportunity->summary,
            'estimated_value_minor' => $opportunity->estimated_value_minor,
            'currency' => (string) $opportunity->currency,
            'probability_percent' => $opportunity->probability_percent,
        ];
    }
}
