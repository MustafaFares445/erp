<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\TicketStatus;
use App\Models\SupportQueue;
use App\Models\Ticket;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final class SupportQueueQueryService
{
    /** @return Builder<Ticket> */
    public function query(SupportQueue $queue): Builder
    {
        $query = Ticket::query();
        $criteria = $queue->criteria;

        if ($queue->support_team_id !== null) {
            $query->where('support_team_id', $queue->support_team_id);
        }

        foreach ($criteria as $key => $value) {
            match ($key) {
                'statuses' => $query->whereIn('status', $this->strings($value)),
                'priorities' => $query->whereIn('priority', $this->strings($value)),
                'service_paths' => $query->whereIn('service_path', $this->strings($value)),
                'unassigned' => (bool) $value ? $query->whereNull('assigned_employee_id') : null,
                'waiting_customer_hours' => is_numeric($value)
                    ? $query->where('status', TicketStatus::WaitingCustomer->value)
                        ->where('waiting_customer_since', '<=', now()->subHours((int) $value))
                    : null,
                'older_than_hours' => is_numeric($value)
                    ? $query->where('created_at', '<=', now()->subHours((int) $value))
                    : null,
                'sla_risk' => (bool) $value ? $this->slaRisk($query) : null,
                default => throw new DomainException('Unsupported support queue criterion: '.$key),
            };
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /** @param Builder<Ticket> $query
     * @return Builder<Ticket>
     */
    private function slaRisk(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where(fn (Builder $query): Builder => $query->responseBreached())
                ->orWhere(fn (Builder $query): Builder => $query->resolutionBreached())
                ->orWhere(function (Builder $query): void {
                    $query->whereNull('first_response_at')
                        ->whereNotNull('response_due_at')
                        ->whereBetween('response_due_at', [now(), now()->addHour()]);
                })
                ->orWhere(function (Builder $query): void {
                    $query->whereNull('resolved_at')
                        ->whereNotNull('resolution_due_at')
                        ->whereBetween('resolution_due_at', [now(), now()->addHours(4)]);
                });
        });
    }
}
