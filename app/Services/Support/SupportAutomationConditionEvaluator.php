<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final class SupportAutomationConditionEvaluator
{
    /** @param array<mixed> $conditions persisted JSON, so each entry is validated at runtime */
    public function matches(Model $subject, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                throw new DomainException('Automation conditions must be structured objects.');
            }

            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? 'equals';

            if (! is_string($field) || ! is_string($operator)) {
                throw new DomainException('Automation condition field and operator are required.');
            }

            $actual = $this->value($subject, $field);
            $expected = $condition['value'] ?? null;

            if (! $this->compare($actual, $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function value(Model $subject, string $field): mixed
    {
        if ($subject instanceof Ticket) {
            return match ($field) {
                'type' => $subject->type->value,
                'priority' => $subject->priority->value,
                'status' => $subject->status->value,
                'customer_impact' => $subject->customer_impact?->value,
                'service_path' => $subject->service_path?->value,
                'support_team_id' => $subject->support_team_id,
                'customer_id' => $subject->customer_id,
                'product_variant_id' => $subject->serializedInventoryUnit?->product_variant_id,
                'warranty_status' => $subject->warranty_status?->value,
                'age_hours' => $subject->created_at?->diffInHours(now()),
                'waiting_customer_hours' => $subject->waiting_customer_since?->diffInHours(now()),
                'sla_state' => $this->ticketSlaState($subject),
                default => throw new DomainException('Unsupported support automation condition field: '.$field),
            };
        }

        if ($subject instanceof MaintenanceRecord) {
            return match ($field) {
                'status' => $subject->status->value,
                'customer_id' => $subject->customer_id,
                'warranty_status' => $subject->warranty_status->value,
                'age_hours' => $subject->created_at?->diffInHours(now()),
                default => throw new DomainException('Unsupported maintenance automation condition field: '.$field),
            };
        }

        throw new DomainException('Unsupported automation subject type: '.$subject::class);
    }

    private function ticketSlaState(Ticket $ticket): string
    {
        if ($ticket->isResponseBreached() || $ticket->isResolutionBreached()) {
            return 'breached';
        }

        $responseRisk = $ticket->first_response_at === null
            && $ticket->response_due_at instanceof CarbonInterface
            && $ticket->response_due_at->isBetween(now(), now()->addHour());

        $resolutionRisk = $ticket->resolved_at === null
            && $ticket->resolution_due_at instanceof CarbonInterface
            && $ticket->resolution_due_at->isBetween(now(), now()->addHours(4));

        return ($responseRisk || $resolutionRisk) ? 'at_risk' : 'ok';
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        // Rule values are typed by hand in the admin UI (e.g. "5" for an integer customer_id), so equality is
        // deliberately loose: `<=>` yields 0 exactly when PHP's `==` is true.
        return match ($operator) {
            'equals' => ($actual <=> $expected) === 0,
            'not_equals' => ($actual <=> $expected) !== 0,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'is_null' => $actual === null,
            'not_null' => $actual !== null,
            default => throw new DomainException('Unsupported support automation operator: '.$operator),
        };
    }
}
