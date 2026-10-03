<?php

declare(strict_types=1);

namespace App\Services\UiPreferences;

use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\SavedTableView;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Converts the plain filters that list tables used to have into rules of the
 * "Add rule" query builder, so saved views keep their filtering after the
 * table moved to {@see TableQueryBuilder}.
 *
 * Each page key maps the removed filter key to the constraint that replaced
 * it. Keys that are not mapped (domain quick filters, trashed) are untouched.
 */
final class SavedTableViewFilterMigrator
{
    private const string SELECT = 'select';

    private const string RELATION = 'relation';

    private const string BOOLEAN = 'boolean';

    private const string TEXT = 'text';

    private const string DATE_RANGE = 'date_range';

    /** @var array<string, array<string, array{0: string, 1: string}>> */
    private const array FILTERS = [
        'purchasing.purchase-orders' => [
            'supplier_id' => [self::RELATION, 'supplier'],
            'status' => [self::SELECT, 'status'],
            'currency_code' => [self::TEXT, 'currency_code'],
            'ordered_between' => [self::DATE_RANGE, 'ordered_at'],
        ],
        'purchasing.purchase-rfqs' => [
            'status' => [self::SELECT, 'status'],
        ],
        'sales.invoices' => [
            'status' => [self::SELECT, 'status'],
            'customer_id' => [self::RELATION, 'customer'],
            'issue_date_between' => [self::DATE_RANGE, 'invoice_date'],
            'due_date_between' => [self::DATE_RANGE, 'due_date'],
        ],
        'support.maintenance-requests' => [
            'status' => [self::SELECT, 'status'],
            'warranty_status' => [self::SELECT, 'warranty_status'],
            'coverage_decision' => [self::SELECT, 'coverage_decision'],
            'billing_type' => [self::SELECT, 'billing_type'],
        ],
        'crm.customers' => [
            'is_active' => [self::BOOLEAN, 'is_active'],
            'approval_status' => [self::SELECT, 'approval_status'],
        ],
        'support.tickets' => [
            'status' => [self::SELECT, 'status'],
            'type' => [self::SELECT, 'type'],
            'priority' => [self::SELECT, 'priority'],
            'assigned_employee_id' => [self::RELATION, 'assignedEmployee'],
        ],
        'employees.visits' => [
            'status' => [self::SELECT, 'status'],
        ],
        'inventory.operations' => [
            'operation_type' => [self::SELECT, 'operation_type'],
            'stage' => [self::SELECT, 'stage'],
        ],
        'inventory.stock-levels' => [
            'warehouse_id' => [self::RELATION, 'warehouse'],
            'product_type' => [self::SELECT, 'product_type'],
        ],
    ];

    /** @return int the number of saved views that were rewritten */
    public function migrate(): int
    {
        $migrated = 0;

        foreach (SavedTableView::query()->cursor() as $view) {
            $state = $this->convertState($view->page_key, $view->state);

            if ($state === null) {
                continue;
            }

            $view->update(['state' => $state]);
            $migrated++;
        }

        return $migrated;
    }

    /**
     * @param  array<array-key, mixed>  $state
     * @return array<array-key, mixed>|null null when nothing needed converting
     */
    public function convertState(string $pageKey, array $state): ?array
    {
        $map = $this->filterMap($pageKey);
        $filters = $state['tableFilters'] ?? null;

        if ($map === [] || ! is_array($filters)) {
            return null;
        }

        $rules = [];
        $converted = false;

        foreach ($map as $key => [$kind, $constraint]) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $converted = true;
            $rules += $this->rulesFor($key, $kind, $constraint, $filters[$key]);
            unset($filters[$key]);
        }

        if (! $converted) {
            return null;
        }

        if ($rules !== []) {
            $existing = is_array($filters['queryBuilder'] ?? null) ? $filters['queryBuilder'] : [];
            $existingRules = is_array($existing['rules'] ?? null) ? $existing['rules'] : [];
            $filters['queryBuilder'] = [...$existing, 'rules' => [...$existingRules, ...$rules]];
        }

        $state['tableFilters'] = $filters;

        return $state;
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function filterMap(string $pageKey): array
    {
        if (array_key_exists($pageKey, self::FILTERS)) {
            return self::FILTERS[$pageKey];
        }

        // Typed operation lists share the operations table: inventory.operations.<type>.
        if (str_starts_with($pageKey, 'inventory.operations.')) {
            return self::FILTERS['inventory.operations'];
        }

        return [];
    }

    /** @return array<string, array<string, mixed>> */
    private function rulesFor(string $key, string $kind, string $constraint, mixed $state): array
    {
        return match ($kind) {
            self::SELECT => $this->selectRules($key, $constraint, $state),
            self::RELATION => $this->relationRules($key, $constraint, $state),
            self::BOOLEAN => $this->booleanRules($key, $constraint, $state),
            self::TEXT => $this->textRules($key, $constraint, $state),
            default => $this->dateRangeRules($key, $constraint, $state),
        };
    }

    /** @return array<string, array<string, mixed>> */
    private function selectRules(string $key, string $constraint, mixed $state): array
    {
        $values = $this->scalarValues($state);

        return $values === [] ? [] : [
            "legacy-{$key}" => $this->rule($constraint, 'is', ['values' => $values]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function relationRules(string $key, string $constraint, mixed $state): array
    {
        $values = $this->scalarValues($state);

        return $values === [] ? [] : [
            "legacy-{$key}" => $this->rule($constraint, 'isRelatedTo', ['value' => $values]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function booleanRules(string $key, string $constraint, mixed $state): array
    {
        $value = is_array($state) ? ($state['value'] ?? null) : null;
        $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($value === null || $value === '' || $flag === null) {
            return [];
        }

        return [
            "legacy-{$key}" => $this->rule($constraint, $flag ? 'isTrue' : 'isTrue.inverse', []),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function textRules(string $key, string $constraint, mixed $state): array
    {
        $values = $this->scalarValues($state);

        return $values === [] ? [] : [
            "legacy-{$key}" => $this->rule($constraint, 'equals', ['text' => (string) $values[0]]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function dateRangeRules(string $key, string $constraint, mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $rules = [];

        foreach (['from' => 'isAfter', 'until' => 'isBefore'] as $bound => $operator) {
            $date = $this->date($state[$bound] ?? null);

            if ($date !== null) {
                $rules["legacy-{$key}-{$bound}"] = $this->rule($constraint, $operator, ['mode' => 'absolute', 'date' => $date]);
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function rule(string $constraint, string $operator, array $settings): array
    {
        return ['type' => $constraint, 'data' => ['operator' => $operator, 'settings' => $settings]];
    }

    /**
     * Old select filters stored `['value' => x]`, multiple ones `['values' => [...]]`.
     *
     * @return list<int|string>
     */
    private function scalarValues(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $raw = $state['values'] ?? $state['value'] ?? [];

        return array_values(array_filter(
            is_array($raw) ? $raw : [$raw],
            static fn (mixed $value): bool => (is_string($value) && $value !== '') || is_int($value),
        ));
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
