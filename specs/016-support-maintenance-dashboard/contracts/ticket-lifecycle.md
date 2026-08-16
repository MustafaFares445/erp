# Contract: Ticket Lifecycle, Payment, and SLA

All rules in this document are enforced inside `app/Services/Support/*`
services, never only by a Filament UI control being hidden or disabled
(FR-006/FR-008). Every service method self-checks authorization
(research.md §4) in addition to the Filament layer's own `->authorize()`.

## 1. `TicketStatus` state machine (FR-020–022)

```text
pending ────────┬──────────────────────► live ──► assigned ──► in_progress ──┬──► waiting_customer
pending_payment ┘ (settlement, FR-043)   │            ▲              │        │        │
                                          │            └──────────────┘        │        │
                                          │         (reassign / unassign)      │        │
                                          │                                    ▼        ▼
                                          │                                 resolved ◄───┘
                                          │                                    │
                                          └────────────────────────────────────┘
                                                        │
                                                        ▼
                                              closed  (terminal)
any non-terminal status ──────────────────────────────────────────────────► cancelled (terminal)
resolved ──► in_progress (reopen — clears resolved_at, resumes original resolution clock, FR-025/058)
```

| From | Allowed To | Actor / permission | Notes |
|---|---|---|---|
| `pending` | `live`, `cancelled` | `ticket.manage` | "triage" — the non-chargeable path |
| `pending_payment` | `live`, `cancelled` | `live`: system, via `TicketPaymentService::settle()` only (FR-043). `cancelled`: `ticket.manage` (FR-045, cancels the pending link too) |
| `live` | `assigned`, `cancelled` | `assign`→`ticket.assign`; `cancelled`→`ticket.manage` |
| `assigned` | `in_progress`, `live`, `cancelled` | `in_progress`→`ticket.work` (assignee only); `live` (unassign)→`ticket.assign`; `cancelled`→`ticket.manage` |
| `in_progress` | `waiting_customer`, `resolved`, `assigned`, `cancelled` | `waiting_customer`/`resolved`→`ticket.work` (assignee only); `assigned` (reassign)→`ticket.assign`; `cancelled`→`ticket.manage` |
| `waiting_customer` | `in_progress`, `resolved`, `cancelled` | `in_progress`/`resolved`→`ticket.work` (assignee only); `cancelled`→`ticket.manage` |
| `resolved` | `closed`, `in_progress` | `closed`→`ticket.manage` OR `ticket.work` (assignee); `in_progress` (reopen)→ same, either actor |
| `closed`, `cancelled` | — | **terminal** — any further transition attempt is rejected (FR-022), including a direct service call |

`TicketLifecycleService::transition(Ticket $ticket, TicketStatus $to, User
$actor, ?string $note = null): void` is the single entry point for every row
above. It:

1. Self-checks authorization (`ticket.manage`, or `ticket.assign` for the
   assign/reassign/unassign edges, or `ticket.work` + `$ticket->assigned_employee_id
   === $actor->employeeProfile?->id` for the assignee-scoped edges).
2. Calls `TicketStatus::from($ticket->status)->canTransitionTo($to)`; throws
   `InvalidStatusTransition` naming the current and attempted status if false
   (FR-009 in the enum test sense — mirrors `SalesPlanStatus`).
3. Rejects if `$ticket->maintenanceRecords()->whereNotIn('status', [Closed,
   Cancelled])->exists()` and `$to === Closed` (FR-026).
4. Sets `resolved_at` entering `resolved`; clears it leaving `resolved` back to
   `in_progress` (FR-025) — the original `resolution_due_at` is **not**
   recomputed on reopen (FR-058); only `resolved_at` changes.
5. Writes the row inside `DB::transaction()` together with the
   `activity()->log('support.ticket.status_changed')` call
   ([audit-log.md](./audit-log.md)).

## 2. Ticket creation and numbering (FR-010–012, research.md §1)

`TicketIntakeService::create(TicketData $data, User $actor): Ticket`, wrapped
in `DB::transaction(attempts: 5)`:

1. Validates `customer_id`, `type`, `priority`, `title`, `description` present
   (FR-010).
2. `$number = $this->nextTicketNumber();` — `Ticket::query()->whereNotNull('ticket_number')
   ->lockForUpdate()->max('ticket_number')`, parse suffix + 1, `sprintf('TCK-%06d', ...)`
   (research.md §1); the `tickets.ticket_number` unique index is the
   concurrency backstop.
3. `status` is set from `is_chargeable`, never passed in directly: `false` →
   `pending`; `true` → `pending_payment` with `pending_reason` set and a
   `TicketPaymentLink` created in the same transaction (FR-021, FR-041).
4. `type === TicketType::MaintenanceRequest` sets no extra column — it is
   simply a value the UI reads to offer "raise a maintenance request from
   this ticket" pre-filled (FR-014, US2 scenario 4); it does not itself create
   a `MaintenanceRecord`.
5. Attachments (if any) are handed to `TicketAttachmentSynchronizer` after the
   ticket exists, inside the same transaction.

## 3. Assignment (FR-023–024)

`TicketLifecycleService::assign(Ticket $ticket, EmployeeProfile $employee,
User $actor): void`:

1. Self-checks `ticket.assign`.
2. Rejects if `$ticket->status` is not in `{live, assigned, in_progress}`
   (assignment/reassignment only makes sense once workable, and is itself one
   of the FR-022 edges above).
3. Inserts a new `TicketAssignment` row (never updates one) and sets
   `tickets.assigned_employee_id` to the new employee — both writes in one
   transaction, so "current assignee" and "newest assignment row" can never
   disagree (FR-024).
4. Transitions `live → assigned` (first assignment) or leaves the status
   unchanged (reassignment while already `assigned`/`in_progress`).

## 4. Ticket messages and first response (FR-030–035)

`TicketMessageService::post(Ticket $ticket, User $sender, string $body, bool
$isInternalNote, User $actor): TicketMessage`:

1. Self-checks `ticket.message` (Manager unrestricted; Agent requires
   `$ticket->assigned_employee_id === $actor->employeeProfile?->id`).
2. Inserts the append-only row — no update/delete method exists on this
   service or `TicketMessage`'s policy.
3. If `! $isInternalNote && $ticket->first_response_at === null`, sets
   `first_response_at = now()` in the same write (FR-033/034) — an internal
   note never sets it, and a second customer-visible message never overwrites
   an already-set value.

## 5. Chargeable tickets and settlement (FR-040–048, System-Admin-only)

`TicketPaymentService::settle(TicketPaymentLink $link, string
$methodReference, User $actor): void` — the **only** write path to
`PaymentLinkStatus::Settled`:

1. Self-checks `ticket.settle-payment` (System Admin only — permissions.md).
2. Rejects if `$link->status !== PaymentLinkStatus::Pending` (FR-044,
   idempotent) or if `$link->ticket->status !== TicketStatus::PendingPayment`
   (Edge Case: cancelled between page-load and submit).
3. In one transaction: updates the link (`settled`, `settled_by`,
   `settled_at`, `payment_method_reference`), transitions the ticket
   `pending_payment → live`, clears `pending_reason`, and writes the audit row
   ([audit-log.md](./audit-log.md) worked example).
4. `TicketLifecycleService::cancel()` cancels the pending link in the same
   transaction as the ticket's `→ cancelled` transition when
   `status === pending_payment` (FR-045).

No step here — or anywhere else in this module — creates a journal entry, tax
entry, or revenue posting (FR-046, SC-004): the settlement transaction touches
only `ticket_payment_links` and `tickets`.

## 6. SLA computation (FR-050–058, research.md §6–7)

`SlaService`, all pure timestamp arithmetic — no business-hours calendar:

- **Clock start** (`onTicketLive(Ticket $ticket)`, called from
  `TicketLifecycleService` the instant a transition lands on `live`): copies
  `sla_policies.{response,resolution}_target_minutes` for the ticket's current
  priority onto `tickets.sla_{response,resolution}_target_minutes` (the
  snapshot, FR-053), sets `live_at = now()`, and computes
  `response_due_at = live_at + response_target`,
  `resolution_due_at = live_at + resolution_target`. A `pending_payment`
  ticket never reaches this method until settlement, so its held time never
  counts (FR-052 clarified: continuous calendar time from `live_at`, zero
  business-hours logic).
- **Pause** (`onWaitingCustomer(Ticket $ticket)`): sets
  `waiting_customer_since = now()`.
- **Resume** (`onResumeFromWaiting(Ticket $ticket)`): computes
  `elapsed = now() - waiting_customer_since`, adds it to both
  `waiting_customer_accumulated_seconds` and `resolution_due_at` (the due time
  moves out by exactly the paused duration — FR-055), clears
  `waiting_customer_since`.
- **Priority change** (`onPriorityChanged(Ticket $ticket, TicketPriority
  $newPriority, User $actor)`): re-snapshots the new priority's targets,
  recomputes both due timestamps from the **original** `live_at` (not from
  now), and — if the recomputed `resolution_due_at`/`response_due_at` is
  already in the past — sets the corresponding `*_breached` flag immediately
  (FR-056). Audited as `support.ticket.priority_changed`.
- **Breach flags**: a scheduled/on-read check (whichever the report/list query
  needs) sets `response_breached = true` once `now() > response_due_at` and
  `first_response_at === null`; `resolution_breached = true` once `now() >
  resolution_due_at` and `resolved_at === null`. Both flags are **sticky**
  (FR-057) — nothing in this module ever sets either back to `false`,
  including a priority change, reopen, or SLA policy edit (SC-006/007).
- **Policy edit isolation**: `sla_policies` rows are read only at clock-start
  and at a priority change — never joined live from a report or list query —
  so editing `SlaPolicy` never alters an already-started ticket's due times
  (SC-006).

## Test obligations

- One test per row of the transition table in §1, plus every **disallowed**
  pair, asserting rejection with a message naming the current and attempted
  status (FR-009/SC-005) — including a call that bypasses the UI entirely.
- SC-001: concurrent ticket creation (parallel transactions / race in test)
  never produces a duplicate `ticket_number`.
- SC-002/SC-003: a `pending_payment` ticket rejects every working transition
  in 100% of attempts; concurrent settlement of the same link yields exactly
  one `settled` row.
- SC-004: settling a payment produces zero rows in any accounting-adjacent
  table (there are none yet in this codebase — the assertion is "no such
  table exists to have gained a row," matching the Assumptions section).
- SC-006/SC-007: an SLA policy edit after clock-start never changes a
  ticket's due times; a `waiting_customer` pause never consumes resolution
  time; a reopened ticket's resolution window is not reset.
