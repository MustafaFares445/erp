<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\CustomerReturnRequestStatus;
use App\Enums\InventoryReturnStatus;
use App\Enums\NotificationEventKey;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Events\SupportQualityMilestone;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequestLine;
use App\Models\InventoryLot;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturnLine;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Attaches the delivered product lines a quality complaint is about. Every
 * context is built from verified delivery history: the customer chooses a
 * delivery line they actually received and a quantity, and the product, lot
 * and unit are read from that line, never from customer-supplied ids.
 * Complaints stay out of Equipment 360 (serialized lines are refused).
 */
final readonly class TicketProductContextService
{
    public function __construct(private LotQualitySignalService $signals) {}

    public static function enabled(): bool
    {
        return (bool) config('support.product_quality_enabled', false);
    }

    /**
     * Non-serialized lines of the customer's completed deliveries that still
     * have a quantity left to complain about.
     *
     * @return Collection<int, InventoryOperationLine>
     */
    public function eligibleLines(CustomerProfile $customer): Collection
    {
        return InventoryOperationLine::query()
            ->whereNull('serialized_inventory_unit_id')
            ->whereNotNull('product_variant_id')
            ->whereHas('operation', static fn (Builder $operation): Builder => $operation
                ->where('operation_type', OperationType::Delivery->value)
                ->where('stage', OperationStage::Done->value)
                ->where('customer_id', $customer->getKey()))
            ->with(['operation', 'productVariant'])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (InventoryOperationLine $line): bool => bccomp($this->remainingQuantity($line), '0', 6) === 1)
            ->values();
    }

    /**
     * Picker options for the eligible lines: delivery, product, lot and what is left to complain about.
     *
     * @return array<int, string>
     */
    public function lineOptions(CustomerProfile $customer): array
    {
        return $this->eligibleLines($customer)
            ->mapWithKeys(function (InventoryOperationLine $line): array {
                $productVariant = $line->productVariant;

                return [$line->id => collect([
                    $line->operation?->operation_number,
                    $productVariant instanceof ProductVariant ? $productVariant->name : '—',
                    $line->lot_number !== null ? __('Lot :lot', ['lot' => $line->lot_number]) : null,
                    __(':quantity left', ['quantity' => mb_rtrim(mb_rtrim($this->remainingQuantity($line), '0'), '.')]),
                ])->filter()->implode(' · ')];
            })
            ->all();
    }

    /**
     * Delivered quantity minus returns already posted or approved for that delivery line.
     *
     * @return numeric-string
     */
    public function remainingQuantity(InventoryOperationLine $line): string
    {
        $delivered = $this->decimal($line->transaction_quantity ?? $line->quantity);

        $posted = InventoryReturnLine::query()
            ->where('original_inventory_operation_line_id', $line->getKey())
            ->whereHas('inventoryReturn', static fn (Builder $return): Builder => $return->where('status', InventoryReturnStatus::Posted->value))
            ->sum('transaction_quantity');

        $approved = CustomerReturnRequestLine::query()
            ->where('original_inventory_operation_line_id', $line->getKey())
            ->whereHas('customerReturnRequest', static fn (Builder $request): Builder => $request->where('status', CustomerReturnRequestStatus::Approved->value))
            ->sum('requested_quantity');

        $remaining = bcsub(bcsub($delivered, $this->decimal($posted), 6), $this->decimal($approved), 6);

        return bccomp($remaining, '0', 6) === 1 ? $remaining : '0.000000';
    }

    /**
     * @param  list<array{original_inventory_operation_line_id?: mixed, quantity?: mixed, notes?: mixed}>  $lines
     * @param  User|null  $actor  staff creating on behalf of the customer; null when the customer files it themselves
     * @return Collection<int, TicketProductContext>
     */
    public function attach(Ticket $ticket, array $lines, ?User $actor = null): Collection
    {
        if ($actor instanceof User) {
            Gate::forUser($actor)->authorize('create', TicketProductContext::class);
        }

        if (! self::enabled()) {
            throw ValidationException::withMessages(['type' => 'Product quality complaints are not enabled.']);
        }

        if ($ticket->type !== TicketType::ProductQualityIssue) {
            throw ValidationException::withMessages(['type' => 'Only a product quality complaint can carry product context.']);
        }

        if (in_array($ticket->status, [TicketStatus::Closed, TicketStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['ticket' => 'A closed or cancelled ticket cannot take new product context.']);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['product_contexts' => 'Choose at least one delivered product line the complaint is about.']);
        }

        return DB::transaction(function () use ($ticket, $lines, $actor): Collection {
            $first = ! $ticket->productContexts()->exists();
            $created = new Collection;
            $seen = $ticket->productContexts()->pluck('original_inventory_operation_line_id')->filter()->all();

            foreach ($lines as $row) {
                $lineId = is_numeric($row['original_inventory_operation_line_id'] ?? null) ? (int) $row['original_inventory_operation_line_id'] : 0;

                if (in_array($lineId, $seen, true)) {
                    throw ValidationException::withMessages(['product_contexts' => 'Each delivered line can be listed only once per complaint.']);
                }

                $seen[] = $lineId;
                $created->push($this->createContext($ticket, $lineId, $row));
            }

            foreach ($created->pluck('inventory_lot_id')->filter()->unique() as $lotId) {
                if (! is_numeric($lotId)) {
                    continue;
                }

                $this->signals->evaluate(InventoryLot::query()->whereKey((int) $lotId)->firstOrFail());
            }

            if ($first) {
                $activity = activity()
                    ->performedOn($ticket)
                    ->withProperties([
                        'source_channel' => $actor instanceof User ? 'dashboard' : 'customer_app',
                        'product_context_count' => $created->count(),
                    ]);

                $activityActor = User::query()->find($ticket->created_by);

                if ($activityActor instanceof User) {
                    $activity->causedBy($activityActor);
                }

                $activity->log('support.quality_complaint.created');
                $this->dispatch(NotificationEventKey::QualityComplaintCreated, $ticket);
            }

            return $created;
        });
    }

    /** The supplier responsible for a lot, from its purchase receipt origin; null when it has no supplier origin. */
    public function supplierForLot(InventoryLot $lot): ?Supplier
    {
        $lot = $this->canonical($lot);

        if ($lot->origin_source_type !== 'inventory_operation' || $lot->origin_source_id === null) {
            return null;
        }

        $supplierId = DB::table('inventory_operations')->where('id', $lot->origin_source_id)->value('supplier_id');

        return is_numeric($supplierId) ? Supplier::query()->find((int) $supplierId) : null;
    }

    public function canonical(InventoryLot $lot): InventoryLot
    {
        return $lot->canonical_inventory_lot_id === null
            ? $lot
            : InventoryLot::query()->findOrFail($lot->canonical_inventory_lot_id);
    }

    /** @param array{quantity?: mixed, notes?: mixed} $row */
    private function createContext(Ticket $ticket, int $lineId, array $row): TicketProductContext
    {
        $line = InventoryOperationLine::query()->with('operation')->find($lineId);
        $operation = $line?->operation;

        if (
            ! $line instanceof InventoryOperationLine
            || $operation === null
            || $operation->operation_type !== OperationType::Delivery
            || $operation->stage !== OperationStage::Done
            || (int) $operation->customer_id !== (int) $ticket->customer_id
        ) {
            throw ValidationException::withMessages(['product_contexts' => 'The product line was not delivered to this customer.']);
        }

        if ($line->serialized_inventory_unit_id !== null) {
            throw ValidationException::withMessages(['product_contexts' => 'Serialized equipment is handled through equipment support, not a product quality complaint.']);
        }

        $quantity = $row['quantity'] ?? null;

        if (! is_numeric($quantity) || bccomp($this->decimal($quantity), '0', 6) !== 1) {
            throw ValidationException::withMessages(['product_contexts' => 'Enter the affected quantity.']);
        }

        if (bccomp($this->decimal($quantity), $this->remainingQuantity($line), 6) === 1) {
            throw ValidationException::withMessages(['product_contexts' => 'The affected quantity exceeds what was delivered and not yet returned.']);
        }

        $notes = $row['notes'] ?? null;

        return TicketProductContext::query()->create([
            'ticket_id' => $ticket->getKey(),
            'product_variant_id' => $line->product_variant_id,
            'inventory_lot_id' => $line->inventory_lot_id,
            'original_inventory_operation_line_id' => $line->getKey(),
            'quantity' => $this->decimal($quantity),
            'unit_id' => $line->transaction_unit_id ?? $line->unit_id,
            'notes' => is_string($notes) && mb_trim($notes) !== '' ? mb_trim($notes) : null,
        ]);
    }

    private function dispatch(NotificationEventKey $key, Ticket $ticket): void
    {
        DB::afterCommit(static fn () => SupportQualityMilestone::dispatch($key, $ticket));
    }

    /** @return numeric-string */
    private function decimal(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 6, '.', '') : '0.000000';
    }
}
