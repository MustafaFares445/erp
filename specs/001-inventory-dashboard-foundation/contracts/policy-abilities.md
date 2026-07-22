# Contract: Inventory Policy Ability Mapping

**Provider**: `App\Policies\Concerns\ChecksInventoryPermissions` (trait). Consumed by FI-1+ resource policies; **no resource policy is created in FI-0** (no models yet). This contract fixes the mapping so later phases are consistent.

**Mechanism**: the trait itself does not derive permission names — it exposes `authorizeInventoryAbility(User $user, string $ability): bool`, which looks up `$ability` in an `inventoryPermissionMap(): array<string,string>` that each consuming policy declares explicitly, then checks `$user->can($permission)`. An ability with no entry in the map is denied by default (see research.md R5 for why an explicit per-policy map was chosen over deriving names from a resource key — the catalogue is not uniform). The table below is the mapping each FI-1+ resource policy is expected to declare, per resource.

## Ability → permission map (per-resource `inventoryPermissionMap()` contents)

| Policy ability | Required permission | Notes |
|---|---|---|
| `viewAny`, `view` | `inventory.<resource>.view` | read access |
| `create` | `inventory.<resource>.create` (or `.manage` for warehouses) | draft creation |
| `update` | `.manage` (warehouses) / `.create` while draft (documents) | documents immutable once confirmed (FR-011, plan §2.4) |
| `delete` | none — **denied for ledgers**; documents soft-delete while draft only | ledgers expose no delete (FR-011); simply omit `delete` from the map |
| `confirm` (custom) | `inventory.<resource>.confirm` | adjustments/transfers; segregation-of-duties lever |
| `release` (custom) | `inventory.reservation.release` | reservations |
| `export` (custom) | `inventory.export` | queued exports |

## Guarantees

- Authorization is resolved via `$user->can('<permission>')` (Spatie) — **no forked/dashboard-specific ACL** (Principle IV, plan §2.3).
- The same permission names back both the dashboard and the API surface.
- An administrator lacking a `view` permission MUST NOT see the resource in navigation and MUST receive 403 on direct URL access (realized per-resource in FI-1+).

## Verification

- FI-0: unit-test the trait's ability→permission resolution against a user with/without a given permission.
- FI-1+: per-resource feature tests assert navigation hiding + 403 (deferred with the resources).
