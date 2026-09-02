# Warehouse / Inventory Release Acceptance

## Purpose

This document is the Phase 12 evidence record for the canonical warehouse architecture described in
`WAREHOUSE_IMPLEMENTATION_PHASES.md`. It does not replace automated tests or owner sign-off. It records the
release gates that must all pass before the warehouse remediation is treated as launch-ready.

## Automated release gates

The `Deploy dev` GitHub Actions workflow is the authoritative `dev` release gate. A newer `dev` push cancels
an obsolete run so stale commits cannot deploy after a newer warehouse change.

Deployment is blocked until **both** jobs succeed:

### 1. Full quality gate

Command:

```bash
composer test
```

This includes the repository's configured Pint/Rector lint checks, PHPStan type analysis, Pest type coverage,
and full Pest coverage gate.

### 2. Warehouse release acceptance

The workflow provisions dedicated MySQL databases and runs:

```bash
php artisan migrate:fresh --seed --force
php artisan inventory:lots:reconcile
php artisan migrate:fresh --database=warehouse_concurrency --force
php artisan test tests/Feature/Inventory/InventoryReleaseAcceptanceTest.php --compact
php artisan test tests/Feature/Inventory/InventoryBalanceConcurrencyTest.php --compact
php artisan test tests/Feature/Inventory/InventoryOperationConcurrencyTest.php --compact
```

The acceptance database name and concurrency database name are test-only names. The workflow must never point
these destructive reset commands at production or a shared development database.

## Automated acceptance criteria

All items below must be green in CI:

- Fresh migration and full seed complete successfully on MySQL.
- `inventory:lots:reconcile` reports zero invariant violations and performs no repair writes.
- The release lifecycle test remains traceable through:
  - canonical receipt posting,
  - reservation,
  - delivery,
  - customer return,
  - receipt correction,
  - movement reporting,
  - final reconciliation.
- The low-level balance race proves row-lock protection on MySQL.
- The canonical reservation race allows only one competing delivery to reserve scarce stock.
- The canonical delivery race allows only one completion of the same Ready delivery.
- Legacy receipt, transfer, and aggregate-reservation runtime classes remain deleted.
- Legacy persistence tables/provenance columns remain absent from the active schema.
- Shipment remains logistics-only and does not post inventory.
- Cross-module roles do not inherit inventory mutation permissions.
- Sales may view delivery notes through the canonical delivery model without gaining receipt or transfer access.
- Demo Sales and Purchasing lines contain canonical transaction-UOM/base-quantity snapshots.

## Manual Filament smoke acceptance

Automated tests do not replace a short dashboard smoke pass. Before release sign-off, verify these flows against
the seeded acceptance/dev environment:

1. **Receipt**
   - Create a receipt with a configured purchase UOM.
   - Move it to Ready and Complete it.
   - Verify stock, lot/serial custody, movement source line, transaction UOM, base quantity, and cost snapshot.

2. **Delivery**
   - Create a delivery using available saleable stock.
   - Verify Ready creates a reservation without changing on-hand.
   - Complete it and verify reservation consumption, on-hand reduction, movement trace, and shipment remains non-posting.

3. **Internal transfer**
   - Create and ready a transfer.
   - Dispatch it and verify source custody leaves only at dispatch.
   - Receive it, including a partial/shortage case when applicable, and verify destination custody and discrepancy history.

4. **Return**
   - Create a customer return from a completed delivery.
   - Inspect a line into Saleable, Quarantine, or Damaged.
   - Post it and verify the compensating movement links to the original sale movement.

5. **Correction**
   - Create a receipt correction from a completed receipt.
   - Post a valid quantity.
   - Verify the original receipt remains immutable and the correction movement links to the receipt movement.

6. **Reports and reconciliation**
   - Open stock and movement reports and confirm transaction UOM/base quantities are unambiguous.
   - Run `php artisan inventory:lots:reconcile` and confirm PASS with zero violations.

7. **Authorization**
   - Sales role: delivery notes visible; receipt/transfer inventory lists forbidden.
   - Purchasing/Support/Sales scoped roles: no direct inventory mutation permissions.
   - Inventory approver/preparer separation remains enforced.

## Sign-off record

Automated evidence is supplied by the successful `Deploy dev` workflow for the exact release commit.

Owner/manual acceptance:

- [ ] Receipt smoke flow passed.
- [ ] Delivery smoke flow passed.
- [ ] Internal-transfer smoke flow passed.
- [ ] Return smoke flow passed.
- [ ] Correction smoke flow passed.
- [ ] Reports/reconciliation smoke flow passed.
- [ ] Authorization smoke flow passed.
- [ ] Owner approves this commit for warehouse release acceptance.

Do not mark Phase 12 complete if either automated job is red or any manual sign-off item remains unresolved for
the intended release.
