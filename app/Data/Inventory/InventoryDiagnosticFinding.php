<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\InventoryDiagnosticCategory;
use App\Enums\InventoryDiagnosticSeverity;

/**
 * One evidence-based observation on an Inventory record: what is wrong, why it matters,
 * the facts that prove it, and the recommended next step.
 */
final readonly class InventoryDiagnosticFinding
{
    /**
     * @param  string  $code  stable rule identifier (documented in VIEW_UX_DIAGNOSTICS.md)
     * @param  list<string>  $facts  the stored values that triggered the rule
     */
    public function __construct(
        public string $code,
        public InventoryDiagnosticCategory $category,
        public InventoryDiagnosticSeverity $severity,
        public string $title,
        public string $explanation,
        public array $facts = [],
        public ?InventoryDiagnosticAction $action = null,
    ) {}
}
