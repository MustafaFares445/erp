<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\CreditNoteStatus;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\StockCondition;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Unit;
use App\Services\Inventory\InventoryReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Explains the next step of an inventory return and what blocks it, mirroring the guards of
 * {@see InventoryReturnService::markReady()} and the cheap, certain part of
 * {@see InventoryReturnService::post()} (lines present, inspection, source condition and the
 * remaining delivered quantity). Lot/serial custody and provenance are re-validated on posting.
 */
final readonly class InventoryReturnDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const int FactLimit = 5;

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryReturn;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryReturn);

        $record->loadMissing([
            'lines.productVariant.unit',
            'lines.lot:id,lot_number',
            'creditNotes',
        ]);

        $isCustomer = $record->return_type === InventoryReturnType::Customer;

        $findings = match ($record->status) {
            InventoryReturnStatus::Draft => $this->draftFindings($record, $isCustomer),
            InventoryReturnStatus::Ready => $this->readyFindings($record, $isCustomer),
            InventoryReturnStatus::Posted => $this->postedFindings($record, $isCustomer),
            InventoryReturnStatus::Cancelled => [$this->finding('return.cancelled', Category::Workflow, Severity::Notice,
                facts: [$this->fact('return_cancellation_reason', ['reason' => $record->cancellation_reason ?: __('—')])],
            )],
        };

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('return_status'),
                $this->check('return_lines'),
                $this->check($isCustomer ? 'return_inspection' : 'return_source_condition'),
                ...($isCustomer ? [$this->check('return_returnable'), $this->check('return_credit_note')] : []),
            ],
            limits: [
                $this->limit('return_posting_validation'),
                $this->limit('return_physical_condition'),
            ],
        );
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function draftFindings(InventoryReturn $return, bool $isCustomer): array
    {
        $lines = $return->lines;
        $user = auth()->user();
        $findings = [];

        if ($lines->isEmpty()) {
            $findings[] = $this->finding('return.empty', Category::Workflow, Severity::Blocker,
                action: $this->buttonStep('return_add_lines', __($isCustomer
                    ? 'admin.inventory.return.actions.add_customer_line'
                    : 'admin.inventory.return.actions.add_supplier_line'), $user?->can('update', $return) ?? false),
            );
        }

        if ($isCustomer) {
            $uninspected = $lines->filter(static fn (InventoryReturnLine $line): bool => $line->disposition === null || $line->inspected_at === null)->values();

            if ($uninspected->isNotEmpty()) {
                $findings[] = $this->finding('return.uninspected', Category::Workflow, Severity::Blocker,
                    ['count' => $uninspected->count(), 'total' => $lines->count()],
                    $this->lineFacts($uninspected),
                    $this->buttonStep('return_inspect_lines', __('admin.inventory.return.actions.inspect'), $user?->can('inspect', $return) ?? false),
                );
            }

            $findings = [...$findings, ...$this->returnableFindings($return)];
        } else {
            $missing = $lines->filter(static fn (InventoryReturnLine $line): bool => ! $line->source_condition instanceof StockCondition)->values();

            if ($missing->isNotEmpty()) {
                $findings[] = $this->finding('return.source_condition_missing', Category::DataQuality, Severity::Blocker,
                    ['count' => $missing->count()],
                    $this->lineFacts($missing),
                    $this->buttonStep('return_readd_line', __('admin.inventory.return.actions.remove_line'), $user?->can('update', $return) ?? false),
                );
            }
        }

        if ($findings === []) {
            $findings[] = $this->finding('return.ready_to_mark', Category::Workflow, Severity::Notice,
                ['count' => $lines->count()],
                action: $this->buttonStep('return_press_button', __('admin.inventory.return.actions.mark_ready'), $user?->can('markReady', $return) ?? false),
            );
        }

        return $findings;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function readyFindings(InventoryReturn $return, bool $isCustomer): array
    {
        $findings = $isCustomer ? $this->returnableFindings($return) : [];

        if ($return->lines->isEmpty()) {
            $findings[] = $this->finding('return.empty', Category::Workflow, Severity::Blocker);
        }

        if ($findings === []) {
            $findings[] = $this->finding('return.ready_to_post', Category::Workflow, Severity::Notice,
                ['count' => $return->lines->count()],
                action: $this->buttonStep('return_press_button', __('admin.inventory.return.actions.post'),
                    auth()->user()?->can('post', $return) ?? false),
            );
        }

        return $findings;
    }

    /**
     * Customer lines may not return more than was delivered minus what other posted customer
     * returns already took back for the same delivery line (the post-time guard of the service).
     *
     * @return list<InventoryDiagnosticFinding>
     */
    private function returnableFindings(InventoryReturn $return): array
    {
        $lines = $return->lines;

        if ($lines->isEmpty()) {
            return [];
        }

        $withoutProvenance = $lines->filter(static fn (InventoryReturnLine $line): bool => $line->original_inventory_operation_line_id === null)->values();
        $findings = [];

        if ($withoutProvenance->isNotEmpty()) {
            $findings[] = $this->finding('return.provenance_missing', Category::DataQuality, Severity::Blocker,
                ['count' => $withoutProvenance->count()],
                $this->lineFacts($withoutProvenance),
            );
        }

        $originalLineIds = $lines->pluck('original_inventory_operation_line_id')->filter()->unique()->values()->all();

        if ($originalLineIds === []) {
            return $findings;
        }

        $originalLines = InventoryOperationLine::query()->whereKey($originalLineIds)->get(['id', 'base_quantity'])->keyBy('id');
        $posted = InventoryReturnLine::query()
            ->whereIn('original_inventory_operation_line_id', $originalLineIds)
            ->where('inventory_return_id', '!=', $return->getKey())
            ->whereHas('inventoryReturn', static fn (Builder $query): Builder => $query
                ->where('return_type', InventoryReturnType::Customer->value)
                ->where('status', InventoryReturnStatus::Posted->value))
            ->selectRaw('original_inventory_operation_line_id, SUM(posted_base_quantity) as posted_total')
            ->groupBy('original_inventory_operation_line_id')
            ->pluck('posted_total', 'original_inventory_operation_line_id');

        $facts = [];

        foreach ($lines->groupBy('original_inventory_operation_line_id') as $originalLineId => $group) {
            $original = $originalLines->get($originalLineId);

            if (! $original instanceof InventoryOperationLine) {
                continue;
            }

            $requested = (float) $group->sum(static fn (InventoryReturnLine $line): float => (float) $line->base_quantity);
            $returnable = (float) $original->base_quantity - (float) ($posted[$originalLineId] ?? 0);

            if ($requested > $returnable + 0.0000005) {
                $first = $group->first();
                assert($first instanceof InventoryReturnLine);
                $facts[] = $this->fact('return_line_exceeds', [
                    'sku' => $this->sku($first),
                    'requested' => $this->quantity($requested, $this->unit($first)),
                    'returnable' => $this->quantity(max(0.0, $returnable), $this->unit($first)),
                ]);
            }
        }

        if ($facts !== []) {
            $findings[] = $this->finding('return.exceeds_returnable', Category::Delivery, Severity::Blocker,
                ['count' => count($facts)],
                $facts,
            );
        }

        return $findings;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function postedFindings(InventoryReturn $return, bool $isCustomer): array
    {
        if (! $isCustomer || ! $return->credit_note_required) {
            return [];
        }

        $notes = $return->creditNotes;

        if ($notes->contains(static fn (CreditNote $note): bool => $note->status === CreditNoteStatus::Confirmed)) {
            return [];
        }

        $drafts = $notes->filter(static fn (CreditNote $note): bool => $note->status === CreditNoteStatus::Draft)->values();

        if ($drafts->isNotEmpty()) {
            $draft = $drafts->first();
            assert($draft instanceof CreditNote);

            return [$this->finding('return.credit_note_draft', Category::Workflow, Severity::Notice,
                facts: $drafts->map(fn (CreditNote $note): string => $this->fact('return_credit_note', [
                    'number' => (string) ($note->credit_note_number ?? '#'.$note->getKey()),
                    'status' => $note->status->label(),
                ]))->all(),
                action: $this->viewLink(CreditNoteResource::class, $draft, 'return_open_credit_note'),
            )];
        }

        $action = $this->sourceInvoiceExists($return)
            ? $this->buttonStep('return_press_button', __('admin.inventory.return.actions.create_credit_note'), CreditNoteResource::canCreate())
            : $this->guidance('return_credit_note_no_invoice');

        return [$this->finding('return.credit_note_missing', Category::Workflow, Severity::Warning,
            facts: $notes->map(fn (CreditNote $note): string => $this->fact('return_credit_note', [
                'number' => (string) ($note->credit_note_number ?? '#'.$note->getKey()),
                'status' => $note->status->label(),
            ]))->all(),
            action: $action,
        )];
    }

    /**
     * Same lookup as the "Create Credit Note" header action: an issued invoice for the original
     * delivery, or for the order that delivery fulfilled.
     */
    private function sourceInvoiceExists(InventoryReturn $return): bool
    {
        $operationId = $return->original_inventory_operation_id;

        if (! is_int($operationId)) {
            return false;
        }

        $invoices = static fn (): Builder => Invoice::query()
            ->where('customer_id', $return->customer_id)
            ->whereNotNull('issued_at');

        if ($invoices()->where('inventory_operation_id', $operationId)->exists()) {
            return true;
        }

        $operation = $return->originalOperation;

        if (
            ! $operation instanceof InventoryOperation
            || $operation->source_document_type !== Order::class
            || ! is_int($operation->source_document_id)
        ) {
            return false;
        }

        return $invoices()->where('order_id', $operation->source_document_id)->exists();
    }

    /**
     * @param  Collection<int, InventoryReturnLine>  $lines
     * @return list<string>
     */
    private function lineFacts(Collection $lines): array
    {
        $facts = $lines->take(self::FactLimit)
            ->map(fn (InventoryReturnLine $line): string => $this->fact('return_line', [
                'sku' => $this->sku($line),
                'quantity' => $this->quantity($line->base_quantity, $this->unit($line)),
            ]))
            ->values()
            ->all();

        if ($lines->count() > self::FactLimit) {
            $facts[] = $this->fact('return_more_lines', ['count' => $lines->count() - self::FactLimit]);
        }

        return $facts;
    }

    private function buttonStep(string $key, string $button, bool $allowed): InventoryDiagnosticAction
    {
        $label = $this->actionLabel($key, ['button' => $button]);

        return $allowed
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    private function sku(InventoryReturnLine $line): string
    {
        $sku = (string) ($line->productVariant?->sku ?? '#'.$line->product_variant_id);

        return $line->lot !== null ? $sku.' / '.$line->lot->lot_number : $sku;
    }

    private function unit(InventoryReturnLine $line): ?string
    {
        $unit = $line->productVariant?->unit;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }
}
