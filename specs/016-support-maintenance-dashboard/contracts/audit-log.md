# Contract: audit logging for the Support module (via `spatie/laravel-activitylog`)

This module writes to the same shared trail every other module uses — there is
no Support-specific audit mechanism. See the canonical contract at
`specs/003-stock-adjustments/contracts/audit-log.md` for the full
`activity()` API and the ADR 0005 history; this document adds only the
Support-specific action-string catalogue and one worked example per FR-028/
FR-048/FR-077/FR-094/FR-105 (corrected per `/speckit-clarify`, spec.md
FR-104 — the trail is `spatie/laravel-activitylog`, not the deleted
`App\Services\Audit\AuditLogger`).

## Action-string catalogue

Lowercase, dot-namespaced `support.{entity}.{past-tense-verb}`, matching the
existing `inventory.{entity}.{verb}` convention exactly:

```text
support.ticket.created                 support.ticket.updated
support.ticket.assigned                support.ticket.status_changed
support.ticket.priority_changed        support.ticket.message_posted
support.payment_link.settled           support.payment_link.settlement_rejected
support.maintenance_record.created     support.maintenance_record.status_changed
support.service_record.created         support.service_record.status_changed
support.service_record_part.consumed   support.service_record_part.reversed
```

## Worked example — settlement (`TicketPaymentService::settle()`)

```php
DB::transaction(function () use ($ticket, $link, $actor, $methodReference) {
    $link->update([
        'status' => PaymentLinkStatus::Settled,
        'settled_by' => $actor->getKey(),
        'settled_at' => now(),
        'payment_method_reference' => $methodReference,
    ]);
    $ticket->update(['status' => TicketStatus::Live, 'pending_reason' => null]);

    activity()
        ->performedOn($ticket)
        ->causedBy($actor)
        ->withChanges([
            'old' => ['ticket_status' => 'pending_payment', 'payment_link_status' => 'pending'],
            'attributes' => ['ticket_status' => 'live', 'payment_link_status' => 'settled', 'payment_method_reference' => $methodReference],
        ])
        ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
        ->log('support.payment_link.settled');
});
```

An already-settled link (FR-044) is rejected *before* this block runs — a
rejection also gets its own audit row (`support.payment_link.settlement_rejected`,
FR-048's "every settlement **and every settlement rejection**") logged outside
the (never-opened) mutating transaction.

## Invariants

- Exactly one `activity_log` row per successful state-changing action; zero if
  the enclosing transaction rolls back — same guarantee as
  `inventory.adjustment.confirmed` (SC-003 for settlement; SC-008 for parts
  consumption/reversal).
- Every entry in FR-028 (ticket status), FR-048 (settlement + rejection),
  FR-056 (priority change), FR-077 (service-record status), and FR-105
  (payment settlement, consumption reversal, closure, cancellation) is backed
  by exactly one call site in the corresponding `app/Services/Support/*`
  service — never from the Filament layer directly, so the guarantee holds
  for a direct service call too.
- `causedBy($actor)` is always explicit — no Support service calls `auth()->user()`
  internally, matching every existing Inventory/Employees service.

## Test obligations

- Per FR-028/048/056/077/105 action: after the triggering call, exactly one
  `AuditLog` (`Activity`) row exists with the correct `description`,
  `causer_id`, `subject_type`/`subject_id`, and `attribute_changes` containing
  old/new values.
- After a rolled-back mutation (forced domain error mid-transaction): no audit
  row (count-unchanged assertion, mirroring
  `specs/003-stock-adjustments`'s own test obligation).
- SC-012: every sensitive action listed there produces a retrievable audit
  entry — one feature test enumerating all nine and asserting each is found.
