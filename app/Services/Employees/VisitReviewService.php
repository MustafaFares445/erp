<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\CustomerVisit;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a {@see CustomerVisit} row: the single review note
 * (D7/FR-045), always available to a `employees.visit.review` holder.
 */
final readonly class VisitReviewService
{
    public function updateReviewNote(CustomerVisit $visit, string $note): CustomerVisit
    {
        return DB::transaction(function () use ($visit, $note): CustomerVisit {
            $oldValues = $visit->only(['review_note', 'reviewed_by', 'reviewed_at']);

            $visit->update([
                'review_note' => $note,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            activity()
                ->performedOn($visit)
                ->withChanges([
                    'old' => $oldValues,
                    'attributes' => $visit->only(['review_note', 'reviewed_by', 'reviewed_at']),
                ])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('visit.reviewed');

            return $visit;
        });
    }
}
