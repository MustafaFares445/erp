<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\SalesOpportunityReviewStatus;
use App\Enums\SalesOpportunityStatus;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class OpportunityReviewService
{
    public function approve(SalesOpportunity $opportunity, ?string $notes = null): SalesOpportunity
    {
        return $this->decide($opportunity, SalesOpportunityReviewStatus::Approved, $notes);
    }

    public function reject(SalesOpportunity $opportunity, ?string $notes = null): SalesOpportunity
    {
        return $this->decide($opportunity, SalesOpportunityReviewStatus::Rejected, $notes);
    }

    private function decide(SalesOpportunity $opportunity, SalesOpportunityReviewStatus $to, ?string $notes): SalesOpportunity
    {
        return DB::transaction(function () use ($opportunity, $to, $notes): SalesOpportunity {
            $from = $opportunity->review_status;
            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            $actor = auth()->user();
            if (! $actor instanceof User) {
                throw new LogicException('An authenticated opportunity reviewer is required.');
            }

            $changes = [
                'review_status' => $to,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ];

            if ($to === SalesOpportunityReviewStatus::Rejected) {
                $changes['status'] = SalesOpportunityStatus::ClosedLost;
                $changes['close_reason'] = is_string($notes) && mb_trim($notes) !== '' ? mb_trim($notes) : 'AI review rejected';
                $changes['closed_at'] = now();
            }

            $opportunity->update($changes);

            activity()
                ->performedOn($opportunity)
                ->causedBy($actor)
                ->withProperties(['from' => $from->value, 'to' => $to->value])
                ->log($to === SalesOpportunityReviewStatus::Approved ? 'opportunity.approved' : 'opportunity.rejected');

            return $opportunity->refresh();
        });
    }
}
