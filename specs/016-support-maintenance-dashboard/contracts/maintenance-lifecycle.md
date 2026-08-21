# Contract: Maintenance Request, Service Record, and Parts Consumption

Enforced inside `app/Services/Support/*`, self-checking authorization exactly
like [ticket-lifecycle.md](./ticket-lifecycle.md) (research.md §4).

## 1. Shared `MaintenanceStatus` state machine (FR-065, FR-073)

Both `MaintenanceRecord` ("Maintenance Request") and `MaintenanceTask`
("Service Record") use the identical four-state vocabulary and transition set:

```text
open ──► in_progress ──► closed      (terminal)
  │            │
  └──────┴────► cancelled  (terminal)
```

| From | Allowed To | Notes |
|---|---|---|
| `open` | `in_progress`, `cancelled` | |
| `in_progress` | `closed`, `cancelled` | |
| `closed`, `cancelled` | — | **terminal** |

`MaintenanceRecordService::transition()` and `ServiceRecordService::transition()`
both delegate the actual allowed/disallowed check to
`MaintenanceStatus::canTransitionTo()` (one enum, one rule, two call sites) —
never a duplicated `match` per service.

## 2. Maintenance request creation and equipment link (FR-060–064)

`MaintenanceRecordService::createFromTicket(Ticket $ticket, MaintenanceRequestData
$data, User $actor): MaintenanceRecord` and `::createStandalone(CustomerProfile
$customer, MaintenanceRequestData $data, User $actor): MaintenanceRecord`:

1. Self-checks `maintenance-request.manage`.
2. `createFromTicket` sets `ticket_id`, pre-fills `customer_id` and
   `description` from the ticket (FR-060); `createStandalone` requires
   `customer_id` and `description` directly and leaves `ticket_id` null
   (FR-061).
3. **Equipment link** (FR-062–063): given a `serial_number`, looks up
   `SerializedInventoryUnit::where('serial_number', $serial)->first()`. A
   match sets `serialized_inventory_unit_id` and lets the UI display that
   unit's `product_variant_id`. No match still saves the request with
   `serial_number` retained as free text and
   `serialized_inventory_unit_id = null` — this is **not** a validation
   failure (FR-063; Edge Cases: "a customer's untracked equipment is never a
   reason to refuse service").
4. **Warranty** (FR-064): `warranty_status` defaults to `unknown`; saving with
   `covered` requires a non-null `warranty_expiry_date`, rejected at the
   service layer (not only a nullable DB column) when absent.
5. A `serialized_inventory_units` row's own status changing later (disposal,
   stock adjustment) never touches `maintenance_records.serialized_inventory_unit_id`
   or `serial_number` — the FK's `nullOnDelete` only fires on hard-delete of
   the unit row itself, which Inventory never does for a unit with recorded
   service history in practice; the equipment link is otherwise permanent
   (FR-068).

## 3. Service record creation and cascading transition (FR-070–076)

`ServiceRecordService::create(MaintenanceRecord $record, ServiceRecordData
$data, User $actor): MaintenanceTask`:

1. Self-checks `service-record.manage`.
2. Requires `title`; `maintenance_record_id` is fixed at creation and never
   changed (FR-071 — no "move to a different request" action exists at any
   layer).
3. Rejects `due_at` earlier than `$record->created_at` (FR-072).

`ServiceRecordService::transition(MaintenanceTask $task, MaintenanceStatus $to,
User $actor): void`:

1. Self-checks `service-record.manage`, OR `service-record.execute` +
   `$task->employee_id === $actor->employeeProfile?->id` (FR-075).
2. Applies §1's transition rule.
3. **Cascade** (FR-074): if this is the first `MaintenanceTask` under
   `$record` to reach `in_progress` and `$record->status === Open`, transitions
   the parent `MaintenanceRecord` to `in_progress` in the same transaction.
4. Writes `support.service_record.status_changed` with actor, timestamp, and
   an optional note (FR-077).

`MaintenanceRecordService::transition(..., MaintenanceStatus::Closed, ...)`
rejects (FR-066) while
`$record->serviceRecords()->whereNotIn('status', [Closed, Cancelled])->exists()`
— the mirror of `TicketLifecycleService`'s FR-026 guard.

## 4. Spare-parts consumption (FR-080–088, research.md §2 and §7)

`ServiceRecordPartService::consume(MaintenanceTask $task, int
$productVariantId, int $warehouseId, float $quantity, User $actor):
ServiceRecordPart`, wrapped in `DB::transaction()`:

1. Self-checks `parts.consume` (Manager unrestricted; Agent requires
   `$task->employee_id === $actor->employeeProfile?->id`).
2. Rejects if `$task->status` is `Closed` or `Cancelled` (FR-085).
3. Calls `InventoryBalanceService::transferOut($productVariantId,
   $warehouseId, $quantity)` — this locks the `inventory_stocks` row and
   throws `DomainException` (surfaced as a validation error naming the
   available quantity, FR-082) if insufficient, enforced under concurrency by
   the row lock itself (FR-084), not only by a prior read.
4. Creates exactly one `InventoryMovement` row: `movement_type =
   MovementType::ServiceConsumption`, `quantity` negative, `source_type =
   'service_record_part'`, `source_id` = the new `ServiceRecordPart`'s key —
   written *after* the part row exists, both inside the same transaction
   (chicken-and-egg resolved by creating the `ServiceRecordPart` row first
   with the movement FK filled in via a second update, or by creating the
   movement first and the part row referencing it; either order is
   acceptable as long as both rows and the stock decrement commit atomically
   or none do, FR-083).
5. Writes `support.service_record_part.consumed` (audit-log.md).
6. Every step is inside one transaction — a failure at any point leaves
   stock, the movement, and the part record all unwritten (FR-083).

`ServiceRecordPartService::reverse(ServiceRecordPart $part, User $actor): void`:

1. Self-checks `parts.reverse` (System Admin only, unconditionally — including
   when `$part->maintenanceTask->status` is still open, per FR-001's
   unconditional grant and permissions.md's role matrix).
2. Rejects if `$part->reversed_at !== null` (a part is reversed at most once —
   MUST NOT edit or delete the original, FR-086).
3. Calls `InventoryBalanceService::transferIn($part->product_variant_id,
   $part->warehouse_id, $part->quantity)` — always the **full** original
   quantity; there is no partial-reversal parameter (clarification,
   2026-08-13).
4. Creates one compensating `InventoryMovement` (`ServiceConsumption`,
   positive quantity, same `source_type`/`source_id`), sets
   `$part->reversed_at`, `reversed_by`, `reversal_movement_id` — the original
   `quantity`/`inventory_movement_id` columns are never edited.
5. Writes `support.service_record_part.reversed`.

## Test obligations

- One test per row of §1's transition table, for **both** `MaintenanceRecord`
  and `MaintenanceTask`, plus every disallowed pair.
- FR-063: a matching serial number links the unit; a non-matching one saves
  as free text, unlinked, not rejected.
- FR-064: `covered` without `warranty_expiry_date` is rejected at save.
- FR-066: closing a maintenance request with any non-terminal service record
  is rejected.
- FR-074: the first service record reaching `in_progress` cascades its parent
  from `open` to `in_progress`; a second one reaching `in_progress` does not
  re-trigger any additional cascade.
- FR-075: an agent executing another employee's service record is denied; a
  Support Manager can transition any service record.
- SC-008: every consumption and every reversal produces exactly one
  `InventoryMovement`; no consumption ever leaves stock changed without a
  movement, or a movement without a stock change — including the rejection
  (insufficient stock), transaction-failure, and reversal paths.
- SC-009: a consumption that would drive available stock negative is rejected
  under concurrent submission (parallel-request/race test against the same
  `product_variant_id` + `warehouse_id`), with no partial write, in 100% of
  attempts.
- A closed or cancelled service record rejects a new consumption (FR-085) even
  via a direct service call.
- A reversal after closure is rejected for any actor except System Admin.
