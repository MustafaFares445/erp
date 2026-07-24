# Internal Contract: Import State Machine

## Queue

`queueStoredFile(path, actor)` creates a `Queued` run and dispatches parsing.

## Parse

`parse(run)`:

1. Locks an eligible queued run and marks it `Parsing`.
2. Deletes only non-applied preview items for that run.
3. Streams and validates the workbook without catalog or inventory writes.
4. Stores one item outcome for each nonblank data row.
5. Finishes as:
   - `Ready` when every row is valid and at least one exists.
   - `ReadyWithErrors` when valid and invalid rows coexist.
   - `Invalid` when no row is valid.
   - `Failed` on a job-level exception.

## Confirm

`queueConfirmation(run, actor)`:

1. Authorizes at the Filament boundary.
2. Locks a `Ready` or `ReadyWithErrors` run.
3. Marks it `Applying`, records the actor/time, and dispatches apply after commit.

## Apply

`apply(run)`:

- Skips applied or rejected rows.
- Applies catalog-only rows independently.
- Applies each warehouse/supplier group independently through a draft receipt and `InventoryReceivingService`.
- Marks runtime-failed rows `Rejected` with an explicit message.
- Recalculates counters from item outcomes.
- Finishes `Confirmed` when no rejected rows exist, otherwise `ConfirmedWithErrors`.
- Uses `Failed` only for a job-level failure that prevents controlled row/group outcome handling.
