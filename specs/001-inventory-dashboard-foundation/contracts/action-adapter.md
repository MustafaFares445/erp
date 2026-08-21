# Contract: Action → Domain-Service Adapter

**Provider**: `App\Filament\Concerns\InteractsWithInventoryServices` (trait for Filament pages/actions).

## Purpose

Force every stock-changing Filament action to be a thin adapter over a domain service (plan §2.1), and translate outcomes into user notifications with no partial writes (FR-007).

## Interface (shape, not final signature)

```
runInventoryOperation(callable $operation, string $successMessageKey): void
  - invokes $operation() (which calls a domain service)
  - on success:                      send a success Notification (title = __($successMessageKey))
  - on DomainException $e:           send a danger Notification (body = $e->getMessage()); do NOT rethrow to a fatal error
  - on ValidationException $e:       surface field/summary errors as a danger Notification
  - the adapter itself performs NO database writes and computes NO stock
```

## Guarantees

- **Delegation**: the adapter never writes stock or movement records; it only invokes the passed operation and notifies.
- **No partial writes**: because the operation is expected to wrap its own DB transaction (services do), a thrown exception leaves no partial state; the adapter does not swallow the failure silently — it always notifies.
- **Consistency**: all future inventory actions use this one path, so success/error UX is uniform.

## Verification (FI-0, with a fake operation — no real service needed)

- Success: pass a no-op closure → assert a success notification was sent.
- Domain error: pass a closure throwing `DomainException('inactive warehouse')` → assert a danger notification with that message and assert no model writes occurred.
- Validation error: pass a closure throwing `ValidationException` → assert a danger notification conveying the messages.
