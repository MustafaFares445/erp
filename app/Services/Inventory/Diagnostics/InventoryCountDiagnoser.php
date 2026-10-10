<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryCountStatus;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\InventoryPermission;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\User;
use App\Services\Inventory\InventoryCountService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Explains what stands between a physical count and its confirmation, mirroring the guards of
 * {@see InventoryCountService}: uncounted lines block submission unless the count is submitted
 * as partial, flagged lines block confirmation, and the user recorded as the counter may never
 * confirm (maker-checker, which the policy alone does not enforce).
 */
final readonly class InventoryCountDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const int QuotedLines = 8;

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryCount;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryCount);

        $record->loadMissing(['lines.productVariant', 'counter', 'confirmedBy', 'adjustment']);
        $lines = $record->lines;

        $findings = match ($record->status) {
            InventoryCountStatus::Draft, InventoryCountStatus::Counting => $this->countingFindings($record, $lines),
            InventoryCountStatus::PendingReview => $this->reviewFindings($record, $lines),
            InventoryCountStatus::Confirmed => $this->confirmedFindings($record, $lines),
            InventoryCountStatus::Cancelled => [$this->finding('count.cancelled', Category::Count, Severity::Notice,
                ['date' => $record->closed_at?->toDateTimeString() ?? '—'])],
        };

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('count_progress'),
                $this->check('count_recounts'),
                $this->check('count_materiality'),
                $this->check('count_maker_checker'),
                $this->check('count_valuation'),
                $this->check('count_adjustment'),
            ],
            limits: [
                $this->limit('count_physical'),
                $this->limit('count_valuation'),
            ],
        );
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    public function keyFigures(InventoryCount $count): array
    {
        $count->loadMissing('lines');
        $lines = $count->lines;
        $total = $lines->count();
        $counted = $lines->filter(static fn (InventoryCountLine $line): bool => $line->isCounted())->count();
        $variance = $this->varianceLines($lines);
        $recount = $lines->filter(static fn (InventoryCountLine $line): bool => (bool) $line->recount_requested)->count();
        $unvalued = $variance->filter(static fn (InventoryCountLine $line): bool => $line->variance_value_minor === null)->count();

        return [
            ['label' => $this->ui('metric_lines'), 'value' => (string) $total],
            [
                'label' => $this->ui('metric_counted'),
                'value' => $total > 0 ? (string) round($counted / $total * 100).'%' : '—',
                'helper' => $this->ui('metric_counted_helper', ['counted' => $counted, 'total' => $total]),
                'tone' => $counted < $total && ! $count->status->isTerminal() ? 'warning' : null,
            ],
            ['label' => $this->ui('metric_variance_lines'), 'value' => (string) $variance->count(), 'tone' => $variance->isNotEmpty() ? 'primary' : null],
            ['label' => $this->ui('metric_recount_lines'), 'value' => (string) $recount, 'tone' => $recount > 0 ? 'danger' : null],
            [
                'label' => $this->ui('metric_variance_value'),
                'value' => $this->money($this->varianceValue($variance)),
                'helper' => $unvalued > 0 ? $this->ui('metric_unvalued_helper', ['count' => $unvalued]) : null,
                'tone' => $unvalued > 0 ? 'warning' : null,
            ],
        ];
    }

    /**
     * @param  Collection<int, InventoryCountLine>  $lines
     * @return list<InventoryDiagnosticFinding>
     */
    private function countingFindings(InventoryCount $count, Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [$this->finding('count.no_lines', Category::Count, Severity::Warning,
                action: $this->buttonStep('admin.inventory.count_ui.actions.cancel', $this->can(InventoryPermission::CountConfirm)))];
        }

        $findings = [];
        $uncounted = $lines->reject(static fn (InventoryCountLine $line): bool => $line->isCounted());
        $flagged = $lines->filter(static fn (InventoryCountLine $line): bool => (bool) $line->recount_requested);
        $mayRecord = $this->can(InventoryPermission::CountRecord);

        if ($flagged->isNotEmpty()) {
            $findings[] = $this->finding('count.recount_requested', Category::Count, Severity::Warning,
                ['count' => $flagged->count()],
                facts: $this->lineFacts($flagged),
                action: $this->buttonStep('admin.inventory.count_ui.actions.record', $mayRecord),
            );
        }

        $threshold = is_numeric($count->materiality_threshold_minor) ? (int) $count->materiality_threshold_minor : null;

        if ($threshold !== null) {
            $material = $lines->filter(static fn (InventoryCountLine $line): bool => ! $line->recount_requested
                && $line->variance_value_minor !== null
                && abs($line->variance_value_minor) > $threshold);

            if ($material->isNotEmpty()) {
                $findings[] = $this->finding('count.material_variances', Category::Count, Severity::Notice,
                    ['count' => $material->count(), 'threshold' => $this->money($threshold)],
                    facts: $this->lineFacts($material),
                );
            }
        }

        if ($uncounted->isNotEmpty()) {
            $findings[] = $this->finding('count.uncounted', Category::Count, Severity::Notice,
                ['count' => $uncounted->count(), 'total' => $lines->count()],
                facts: $this->lineFacts($uncounted),
                action: $this->buttonStep('admin.inventory.count_ui.actions.record', $mayRecord),
            );
        } elseif ($flagged->isEmpty()) {
            $findings[] = $this->finding('count.ready_for_review', Category::Count, Severity::Notice,
                ['total' => $lines->count()],
                action: $this->buttonStep('admin.inventory.count_ui.actions.submit_review', $mayRecord),
            );
        }

        return $findings;
    }

    /**
     * @param  Collection<int, InventoryCountLine>  $lines
     * @return list<InventoryDiagnosticFinding>
     */
    private function reviewFindings(InventoryCount $count, Collection $lines): array
    {
        $findings = [];
        $flagged = $lines->filter(static fn (InventoryCountLine $line): bool => (bool) $line->recount_requested);
        $mayConfirm = $this->can(InventoryPermission::CountConfirm);

        if ($flagged->isNotEmpty()) {
            $findings[] = $this->finding('count.recount_blocks_confirm', Category::Count, Severity::Blocker,
                ['count' => $flagged->count()],
                facts: $this->lineFacts($flagged),
                action: $this->buttonStep('admin.inventory.count_ui.actions.accept_variance', $mayConfirm, 'count_use_line_button'),
            );
        }

        $uncounted = $lines->reject(static fn (InventoryCountLine $line): bool => $line->isCounted());

        if ((bool) $count->is_partial && $uncounted->isNotEmpty()) {
            $findings[] = $this->finding('count.partial', Category::Count, Severity::Warning,
                ['count' => $uncounted->count(), 'total' => $lines->count()],
                facts: $this->lineFacts($uncounted),
            );
        }

        $variance = $this->varianceLines($lines);
        $unvalued = $variance->filter(static fn (InventoryCountLine $line): bool => $line->variance_value_minor === null);

        if ($unvalued->isNotEmpty()) {
            $findings[] = $this->finding('count.unvalued_variances', Category::Count, Severity::Warning,
                ['count' => $unvalued->count()],
                facts: $this->lineFacts($unvalued),
            );
        }

        $counterId = is_numeric($count->counted_by) ? (int) $count->counted_by : null;
        $actor = auth()->user();
        $counterName = $count->counter instanceof User ? $count->counter->name : '—';
        $varianceFacts = [
            $this->fact('count_counted_by', ['name' => $counterName]),
            $this->fact('count_variance_summary', [
                'count' => $variance->count(),
                'quantity' => $this->quantity($variance->sum(static fn (InventoryCountLine $line): float => abs((float) $line->variance_base_quantity))),
                'value' => $this->money($this->varianceValue($variance)),
            ]),
        ];

        if ($actor instanceof User && $counterId !== null && $counterId === $actor->getKey()) {
            $findings[] = $this->finding('count.self_confirmation', Category::Approval, Severity::Blocker,
                facts: $varianceFacts,
                action: new InventoryDiagnosticAction(
                    $this->actionLabel('count_ask_other_user'),
                    null,
                    false,
                    $this->actionLabel('count_maker_checker_reason'),
                ),
            );
        } else {
            $findings[] = $this->finding('count.awaiting_confirmation', Category::Approval, Severity::Notice,
                ['name' => $counterName],
                facts: $varianceFacts,
                action: $this->buttonStep('admin.inventory.count_ui.actions.confirm', $mayConfirm),
            );
        }

        return $findings;
    }

    /**
     * @param  Collection<int, InventoryCountLine>  $lines
     * @return list<InventoryDiagnosticFinding>
     */
    private function confirmedFindings(InventoryCount $count, Collection $lines): array
    {
        $variance = $this->varianceLines($lines);
        $adjustment = $count->adjustment;

        if ($adjustment instanceof InventoryAdjustment) {
            return [$this->finding('count.confirmed', Category::Count, Severity::Notice,
                ['number' => (string) ($adjustment->adjustment_number ?? '#'.$adjustment->id), 'count' => $variance->count()],
                facts: [$this->fact('count_confirmed_by', [
                    'name' => $count->confirmedBy instanceof User ? $count->confirmedBy->name : '—',
                    'date' => $count->confirmed_at?->toDateTimeString() ?? '—',
                ])],
                action: $this->viewLink(AdjustmentResource::class, $adjustment, 'count_open_adjustment'),
            )];
        }

        if ($variance->isNotEmpty()) {
            return [$this->finding('count.confirmed_without_adjustment', Category::DataQuality, Severity::Blocker,
                ['count' => $variance->count()],
                facts: $this->lineFacts($variance),
                action: $this->guidance('escalate_inconsistency'),
            )];
        }

        return [$this->finding('count.confirmed_no_variance', Category::Count, Severity::Notice,
            ['date' => $count->confirmed_at?->toDateTimeString() ?? '—'])];
    }

    /**
     * Counted lines whose counted quantity differs from the system quantity.
     *
     * @param  Collection<int, InventoryCountLine>  $lines
     * @return Collection<int, InventoryCountLine>
     */
    private function varianceLines(Collection $lines): Collection
    {
        return $lines->filter(static fn (InventoryCountLine $line): bool => $line->isCounted()
            && $line->variance_base_quantity !== null
            && abs((float) $line->variance_base_quantity) > 0.0000005);
    }

    /**
     * @param  Collection<int, InventoryCountLine>  $lines
     */
    private function varianceValue(Collection $lines): int
    {
        return (int) $lines->sum(static fn (InventoryCountLine $line): int => (int) $line->variance_value_minor);
    }

    /**
     * @param  Collection<int, InventoryCountLine>  $lines
     * @return list<string>
     */
    private function lineFacts(Collection $lines): array
    {
        $facts = [];

        foreach ($lines->take(self::QuotedLines) as $line) {
            $facts[] = $this->fact('count_line', [
                'sku' => $line->productVariant->sku,
                'condition' => $line->stock_condition->label(),
                'system' => $this->quantity($line->system_base_quantity),
                'counted' => $line->counted_base_quantity === null ? '—' : $this->quantity($line->counted_base_quantity),
                'variance' => $line->variance_base_quantity === null ? '—' : $this->quantity($line->variance_base_quantity),
                'value' => $line->variance_value_minor === null ? '—' : $this->money($line->variance_value_minor),
            ]);
        }

        if ($lines->count() > self::QuotedLines) {
            $facts[] = $this->fact('count_more_lines', ['count' => $lines->count() - self::QuotedLines]);
        }

        return $facts;
    }

    private function buttonStep(string $buttonKey, bool $allowed, string $labelKey = 'count_use_button'): InventoryDiagnosticAction
    {
        $label = $this->actionLabel($labelKey, ['button' => __($buttonKey)]);

        return $allowed
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    private function can(InventoryPermission $permission): bool
    {
        return auth()->user()?->can($permission->value) ?? false;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function ui(string $key, array $replace = []): string
    {
        $text = __('inventory_view_ux.ui.'.$key, $replace);

        return is_string($text) ? $text : $key;
    }

    private function money(int $minor): string
    {
        return number_format($minor / 100, 2);
    }
}
