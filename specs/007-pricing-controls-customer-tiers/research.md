# Research: Pricing Controls and Customer Tiers

## Decision 1: Separate reads from writes

**Decision**: Keep `PriceResolver` limited to resolving a price and checking a floor. Put all variant-price, tier, assignment, and override writes in `ProductPricingService`.

**Rationale**: The current resolver mixes queries and transactional writes. A single mutation service makes history, audit, locking, and no-op behavior enforceable.

**Alternatives considered**: Model observers were rejected because they obscure actor and source context. Separate services per pricing table were rejected because the workflows share authorization, locking, and audit rules.

## Decision 2: Customize modal action persistence

**Decision**: Use Filament 5 `CreateAction::using()` and `EditAction::using()` callbacks. Split pricing keys from catalog keys and call the pricing service inside a transaction. Make base price unsaved and disabled.

**Rationale**: The current resources use modal actions rather than separate create/edit pages. Version-specific Filament documentation supports replacing action persistence with `using()` while preserving resource relationships.

**Alternatives considered**: Converting every resource to standalone pages would increase UI churn. Model observers would not guarantee that pricing records and audit rows share the explicit action transaction.

## Decision 3: Serialize assignment changes with row locks

**Decision**: Lock a customer's current assignments or specific tiers before deactivating earlier active rows and activating the requested row.

**Rationale**: MySQL has no portable partial unique constraint for “one active row” on the current schema. Transactional locks enforce the invariant without a destructive schema rewrite.

**Alternatives considered**: Deleting earlier rows loses history. A generated-column unique index adds database-specific complexity and does not cover existing soft-deleted tier semantics cleanly.

## Decision 4: Reuse existing permission catalogue

**Decision**: Require `inventory.pricing.view` for sensitive lists and `inventory.pricing.manage` for pricing mutations.

**Rationale**: Both permissions already exist and are seeded. No new access system or public contract is necessary.

## Decision 5: Enforce override immutability in the model and UI

**Decision**: Reject update and delete operations on persisted floor overrides and expose only list/view actions.

**Rationale**: UI-only immutability can be bypassed by internal code. A model guard preserves the audit record while factories and service creation still work.
