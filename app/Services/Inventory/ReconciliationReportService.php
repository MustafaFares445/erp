<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ReconciliationScope;
use App\Models\ReconciliationRun;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReconciliationReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ReconciliationRun>
     */
    public function query(array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($filters);
        $query = ReconciliationRun::query()->with('triggeredBy');

        if (isset($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }

        if (isset($filters['passed'])) {
            $query->where('passed', $filters['passed']);
        }

        if (isset($filters['trigger_source'])) {
            $query->where('trigger_source', $filters['trigger_source']);
        }

        if (isset($filters['from'])) {
            $query->whereDate('started_at', '>=', $filters['from']);
        }

        if (isset($filters['until'])) {
            $query->whereDate('started_at', '<=', $filters['until']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ReconciliationRun>
     */
    public function divergences(array $filters = []): Builder
    {
        return $this->query($filters)->where('passed', false);
    }

    public function hasPersistedRuns(): bool
    {
        return ReconciliationRun::query()->exists();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, bool|string>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        if (isset($filters['scope']) && is_string($filters['scope'])) {
            $scope = ReconciliationScope::tryFrom($filters['scope']);

            if ($scope instanceof ReconciliationScope) {
                $normalized['scope'] = $scope->value;
            }
        }

        if (array_key_exists('passed', $filters)) {
            $passed = filter_var($filters['passed'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            if (is_bool($passed)) {
                $normalized['passed'] = $passed;
            }
        }

        if (isset($filters['trigger_source']) && is_string($filters['trigger_source'])) {
            $triggerSource = mb_trim($filters['trigger_source']);

            if (in_array($triggerSource, ['schedule', 'manual', 'period_close'], true)) {
                $normalized['trigger_source'] = $triggerSource;
            }
        }

        foreach (['from', 'until'] as $key) {
            if (! isset($filters[$key]) || ! is_string($filters[$key])) {
                continue;
            }

            $value = mb_trim($filters[$key]);

            if (! $this->isDate($value)) {
                throw new DomainException('Reconciliation report dates must use YYYY-MM-DD.');
            }

            $normalized[$key] = $value;
        }

        if (isset($normalized['from'], $normalized['until']) && $normalized['from'] > $normalized['until']) {
            throw new DomainException('The reconciliation report start date must be before the end date.');
        }

        return $normalized;
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
