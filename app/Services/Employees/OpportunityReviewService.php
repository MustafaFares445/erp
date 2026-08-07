<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\OpportunityDraftStatus;
use App\Models\SalesOpportunityDraft;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a draft with a recorded decision (FR-054). Both
 * outcomes are terminal — a superseded decision means creating a new draft,
 * never rewriting a decided one.
 */
final readonly class OpportunityReviewService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function approve(SalesOpportunityDraft $draft, ?string $notes = null): SalesOpportunityDraft
    {
        return $this->decide($draft, OpportunityDraftStatus::Approved, $notes);
    }

    public function reject(SalesOpportunityDraft $draft, ?string $notes = null): SalesOpportunityDraft
    {
        return $this->decide($draft, OpportunityDraftStatus::Rejected, $notes);
    }

    private function decide(SalesOpportunityDraft $draft, OpportunityDraftStatus $to, ?string $notes): SalesOpportunityDraft
    {
        return DB::transaction(function () use ($draft, $to, $notes): SalesOpportunityDraft {
            $from = $draft->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            $draft->update([
                'status' => $to,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            $this->auditLogger->log(
                action: $to === OpportunityDraftStatus::Approved ? 'opportunity.approved' : 'opportunity.rejected',
                entity: $draft,
                newValues: $draft->getAttributes(),
            );

            return $draft;
        });
    }
}
