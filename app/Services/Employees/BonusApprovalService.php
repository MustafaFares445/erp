<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Enums\BonusSuggestionStatus;
use App\Models\BonusSuggestion;
use App\Services\Employees\Exceptions\InvalidStatusTransition;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a bonus suggestion with a recorded decision (FR-064).
 * Both outcomes are terminal.
 */
final readonly class BonusApprovalService
{
    public function approve(BonusSuggestion $suggestion, ?string $notes = null): BonusSuggestion
    {
        return $this->decide($suggestion, BonusSuggestionStatus::Approved, $notes);
    }

    public function reject(BonusSuggestion $suggestion, ?string $notes = null): BonusSuggestion
    {
        return $this->decide($suggestion, BonusSuggestionStatus::Rejected, $notes);
    }

    private function decide(BonusSuggestion $suggestion, BonusSuggestionStatus $to, ?string $notes): BonusSuggestion
    {
        return DB::transaction(function () use ($suggestion, $to, $notes): BonusSuggestion {
            $from = $suggestion->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::fromTo($from->value, $to->value);
            }

            $suggestion->update([
                'status' => $to,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'decision_notes' => $notes,
            ]);

            activity()
                ->performedOn($suggestion)
                ->withChanges([
                    'attributes' => $suggestion->getAttributes(),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log($to === BonusSuggestionStatus::Approved ? 'bonus.approved' : 'bonus.rejected');

            return $suggestion;
        });
    }
}
