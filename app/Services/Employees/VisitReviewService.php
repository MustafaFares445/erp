<?php

declare(strict_types=1);

namespace App\Services\Employees;

use App\Models\CustomerVisit;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a {@see CustomerVisit} row: the single review note
 * (D7/FR-045), always available, and the admin field-edit escape hatch
 * (FR-044), gated to `employees.visit.field-edit` at the policy layer.
 */
final readonly class VisitReviewService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function updateReviewNote(CustomerVisit $visit, string $note): CustomerVisit
    {
        return DB::transaction(function () use ($visit, $note): CustomerVisit {
            $oldValues = $visit->only(['review_note', 'reviewed_by', 'reviewed_at']);

            $visit->update([
                'review_note' => $note,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            $this->auditLogger->log(
                action: 'visit.reviewed',
                entity: $visit,
                oldValues: $oldValues,
                newValues: $visit->only(['review_note', 'reviewed_by', 'reviewed_at']),
            );

            return $visit;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateFieldRecordedVisit(CustomerVisit $visit, array $data): CustomerVisit
    {
        return DB::transaction(function () use ($visit, $data): CustomerVisit {
            $oldValues = $visit->getAttributes();

            $visit->update($data);

            $this->auditLogger->log(
                action: 'visit.field_edited',
                entity: $visit,
                oldValues: $oldValues,
                newValues: $visit->getAttributes(),
            );

            return $visit;
        });
    }
}
