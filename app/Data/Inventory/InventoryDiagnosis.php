<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\InventoryDiagnosticSeverity;

/**
 * The read-only verdict of the Operational health panel on an Inventory View page.
 *
 * `checks` names what was actually evaluated and `limits` what the checks cannot know,
 * so an empty finding list is presented as "nothing found by these checks" and never
 * as proof that the physical stock is right.
 */
final readonly class InventoryDiagnosis
{
    /** @var list<InventoryDiagnosticFinding> */
    public array $findings;

    /**
     * @param  list<InventoryDiagnosticFinding>  $findings
     * @param  list<string>  $checks
     * @param  list<string>  $limits
     */
    public function __construct(
        array $findings,
        public array $checks = [],
        public array $limits = [],
    ) {
        usort(
            $findings,
            static fn (InventoryDiagnosticFinding $a, InventoryDiagnosticFinding $b): int => $b->severity->rank() <=> $a->severity->rank(),
        );

        $this->findings = $findings;
    }

    public function severity(): InventoryDiagnosticSeverity
    {
        return $this->findings[0]->severity ?? InventoryDiagnosticSeverity::Clear;
    }

    public function summary(): string
    {
        $blockers = $this->countOf(InventoryDiagnosticSeverity::Blocker);
        $warnings = $this->countOf(InventoryDiagnosticSeverity::Warning);

        return match (true) {
            $blockers > 0 => trans_choice('inventory_view_ux.summary.blockers', $blockers, ['count' => $blockers, 'warnings' => $warnings]),
            $warnings > 0 => trans_choice('inventory_view_ux.summary.warnings', $warnings, ['count' => $warnings]),
            $this->findings !== [] => __('inventory_view_ux.summary.guidance'),
            default => __('inventory_view_ux.summary.clear', ['checks' => count($this->checks)]),
        };
    }

    public function has(string $code): bool
    {
        return $this->find($code) instanceof InventoryDiagnosticFinding;
    }

    public function find(string $code): ?InventoryDiagnosticFinding
    {
        foreach ($this->findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (InventoryDiagnosticFinding $finding): string => $finding->code, $this->findings);
    }

    private function countOf(InventoryDiagnosticSeverity $severity): int
    {
        return count(array_filter(
            $this->findings,
            static fn (InventoryDiagnosticFinding $finding): bool => $finding->severity === $severity,
        ));
    }
}
