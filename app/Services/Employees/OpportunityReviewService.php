<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalesOpportunityStatus;
use App\Models\SalesOpportunity;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a sales opportunity with a recorded decision
 * (FR-054). Both outcomes are terminal — a superseded decision means
 * creating a new opportunity, never rewriting a decided one.
 */
final readonly class OpportunityReviewService
{
    public function approve(SalesOpportunity $opportunity, ?string $notes = null): SalesOpportunity
    {
        return $this->decide($opportunity, SalesOpportunityStatus::Approved, $notes);
    }

    public function reject(SalesOpportunity $opportunity, ?string $notes = null): SalesOpportunity
    {
        return $this->decide($opportunity, SalesOpportunityStatus::Rejected, $notes);
    }

    private function decide(SalesOpportunity $opportunity, SalesOpportunityStatus $to, ?string $notes): SalesOpportunity
    {
        return DB::transaction(function () use ($opportunity, $to, $notes): SalesOpportunity {
            $from = $opportunity->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            $opportunity->update([
                'status' => $to,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            activity()
                ->performedOn($opportunity)
                ->withChanges([
                    'attributes' => $opportunity->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($to === SalesOpportunityStatus::Approved ? 'opportunity.approved' : 'opportunity.rejected');

            return $opportunity;
        });
    }
}
