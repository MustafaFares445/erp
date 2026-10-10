<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory;
use App\Enums\InventoryDiagnosticSeverity;
use App\Support\QuantityFormatter;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared vocabulary for diagnosers: rule texts live in `lang/{locale}/inventory_view_ux.php`
 * under `rules.<code>.title|explanation`, so every rule is bilingual by construction.
 */
trait BuildsInventoryFindings
{
    /**
     * @param  array<string, string|int|float>  $replace
     * @param  list<string>  $facts
     */
    protected function finding(
        string $code,
        InventoryDiagnosticCategory $category,
        InventoryDiagnosticSeverity $severity,
        array $replace = [],
        array $facts = [],
        ?InventoryDiagnosticAction $action = null,
    ): InventoryDiagnosticFinding {
        return new InventoryDiagnosticFinding(
            code: $code,
            category: $category,
            severity: $severity,
            title: __('inventory_view_ux.rules.'.$code.'.title', $replace),
            explanation: __('inventory_view_ux.rules.'.$code.'.explanation', $replace),
            facts: $facts,
            action: $action,
        );
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    protected function fact(string $key, array $replace = []): string
    {
        return __('inventory_view_ux.facts.'.$key, $replace);
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    protected function actionLabel(string $key, array $replace = []): string
    {
        return __('inventory_view_ux.actions.'.$key, $replace);
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    protected function check(string $key, array $replace = []): string
    {
        return __('inventory_view_ux.checks.'.$key, $replace);
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    protected function limit(string $key, array $replace = []): string
    {
        return __('inventory_view_ux.limits.'.$key, $replace);
    }

    protected function quantity(float|int|string|null $quantity, ?string $unit = null): string
    {
        $formatted = QuantityFormatter::display($quantity);

        return $unit === null || $unit === '' ? $formatted : $formatted.' '.$unit;
    }

    protected function link(string $labelKey, ?string $url, bool $allowed = true): InventoryDiagnosticAction
    {
        return InventoryDiagnosticAction::link($this->actionLabel($labelKey), $url, $allowed);
    }

    protected function guidance(string $labelKey): InventoryDiagnosticAction
    {
        return InventoryDiagnosticAction::guidance($this->actionLabel($labelKey));
    }

    /**
     * A link to a record's View page, offered only when the current user may view it.
     *
     * @param  class-string<Resource>  $resource
     * @param  array<string, string|int|float>  $replace
     */
    protected function viewLink(string $resource, Model $record, string $labelKey, array $replace = []): InventoryDiagnosticAction
    {
        return InventoryDiagnosticAction::link(
            $this->actionLabel($labelKey, $replace),
            $resource::getUrl('view', ['record' => $record]),
            $resource::canView($record),
        );
    }

    /**
     * A link to a resource's list page (optionally pre-filtered), offered only when the user may list it.
     *
     * @param  class-string<Resource>  $resource
     * @param  array<string, mixed>  $parameters
     */
    protected function indexLink(string $resource, string $labelKey, array $parameters = []): InventoryDiagnosticAction
    {
        return InventoryDiagnosticAction::link(
            $this->actionLabel($labelKey),
            $resource::getUrl('index', $parameters),
            $resource::canViewAny(),
        );
    }

    /**
     * A link to a resource's create page with prefilled values, offered only when the user may create.
     *
     * @param  class-string<Resource>  $resource
     * @param  array<string, mixed>  $parameters
     */
    protected function createLink(string $resource, string $labelKey, array $parameters = []): InventoryDiagnosticAction
    {
        return InventoryDiagnosticAction::link(
            $this->actionLabel($labelKey),
            $resource::getUrl('create', $parameters),
            $resource::canCreate(),
        );
    }

    /**
     * Query parameters that open a resource list with table filters applied.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function filtered(array $filters): array
    {
        return ['tableFilters' => array_map(static fn (mixed $value): array => ['value' => $value], $filters)];
    }
}
