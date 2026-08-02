# Feature Specification: CRM Customers and Product-Scoped Pricing Tiers

**Feature Directory**: `013-crm-customers-subscriptions` (retained as the historical Spec-Kit identifier)

**Created**: 2026-07-29

**Revised**: 2026-08-02

**Status**: Implemented - replaces the standalone Product Subscription design

**Input**: Maintain CRM customers and product-specific discount agreements through the existing Pricing Tiers screen, remove the standalone Product Subscription feature, keep the delivery in English, and exclude customer payment terms from this CRM module.

## Scope

This feature extends the existing admin dashboard for:

- customer profile maintenance;
- general, customer-specific, and product-scoped pricing tiers;
- product and customer eligibility for product-scoped tiers;
- deterministic price preview and minimum-price-floor control;
- fixed-role authorization, reporting, and audit review.

The feature does not add a Product Subscriptions page, model, API, customer-facing interface, recurring billing, customer payment terms, a second pricing engine, or duplicate audit/report storage.

## User Scenarios and Testing

### User Story 1 - Maintain Customer Profiles (Priority: P1)

An authorized dashboard user maintains a customer's commercial profile and lifecycle while preserving the existing customer identity and history.

**Why this priority**: Customer identity and activity determine whether customer-scoped pricing may be applied.

**Independent Test**: Create, search, update, deactivate, soft-delete, and restore a customer profile without configuring a pricing tier.

**Acceptance Scenarios**:

1. **Given** a customer user without a profile, **when** an authorized user creates a profile with a unique customer code, **then** exactly one profile is linked to that customer and it is searchable by code, company, name, and email.
2. **Given** a customer code or user already linked to a profile, **when** a duplicate is submitted, **then** the save is rejected with a clear field error.
3. **Given** a customer with historical records, **when** the customer is deactivated or soft-deleted, **then** history remains available and customer-derived pricing is no longer eligible.
4. **Given** a soft-deleted profile, **when** a System Admin restores it, **then** its user link and history are retained and the restore is audited.

---

### User Story 2 - Manage Pricing Tiers in One Place (Priority: P1)

An authorized dashboard user manages general, customer-specific, and product-scoped pricing tiers from the existing Pricing Tiers screen.

**Why this priority**: Pricing Tiers are the single commercial-discount configuration surface; a parallel Product Subscription feature would duplicate rules and navigation.

**Independent Test**: Create each tier type, edit its permitted fields, link product/customer eligibility for a product-scoped tier, activate/deactivate it, preview it, soft-delete it, and restore it without visiting another resource.

**Acceptance Scenarios**:

1. **Given** an existing percentage-based general or customer-specific tier, **when** the redesign is delivered, **then** its pricing behavior and assignment remain unchanged.
2. **Given** a product-scoped tier, **when** it is configured, **then** it supports a percentage or fixed discount, product links, customer assignments, optional inclusive validity dates, visibility classification, and active state.
3. **Given** a product whose variant base price is 120, **when** a 10% product-scoped tier is previewed, **then** its candidate is 108 before floor validation.
4. **Given** the same product, **when** a fixed discount of 15 is previewed, **then** its candidate is 105 before floor validation.
5. **Given** an invalid percentage, fixed discount, or date range, **when** the tier is saved or previewed, **then** the operation is rejected clearly.
6. **Given** a product-scoped tier with no active linked product, **when** activation is attempted, **then** activation is refused.
7. **Given** a restricted product-scoped tier with no active assigned customer, **when** activation is attempted, **then** activation is refused.
8. **Given** a public product-scoped tier, **when** it is activated, **then** public remains a dashboard classification and does not grant a customer price without an explicit assignment.
9. **Given** a deleted product-scoped tier, **when** it is restored, **then** it remains inactive until explicitly reactivated.
10. **Given** the admin dashboard, **when** routes and navigation are inspected, **then** Pricing Tiers is present and no Product Subscriptions route or navigation item exists.

---

### User Story 3 - Resolve the Effective Customer Price (Priority: P1)

The dashboard resolves one auditable price for a customer and product variant without stacking discounts.

**Why this priority**: Incorrect precedence or stacking can create direct commercial loss.

**Independent Test**: Evaluate customer-specific, product-scoped, general, and base candidates across customer/product activity, dates, ties, and floor control.

**Acceptance Scenarios**:

1. **Given** an active customer-specific tier, **when** a price is resolved, **then** it wins over product-scoped tiers, the assigned general tier, and base price.
2. **Given** no customer-specific tier and one eligible product-scoped tier, **when** a price is resolved, **then** the product-scoped tier wins over the general tier and base price.
3. **Given** several eligible product-scoped tiers, **when** a price is resolved, **then** the lowest final amount wins; equal amounts are resolved by the earliest tier identifier.
4. **Given** no customer-specific or eligible product-scoped tier, **when** a price is resolved, **then** the active assigned general tier is used, or base price when none applies.
5. **Given** overlapping sources, **when** a price is resolved, **then** exactly one source is applied and discounts are never added or compounded.
6. **Given** an inactive/deleted customer, inactive/deleted product or variant, inactive/deleted tier, invalid date, missing assignment, or missing product link, **when** pricing is resolved, **then** the ineligible tier is ignored.
7. **Given** a winning amount below the variant minimum, **when** it is used without approval, **then** the operation is blocked.
8. **Given** a below-floor amount, **when** a System Admin approves it with a reason, **then** the approval records the customer, variant, tier, attempted amount, floor, approver, reason, and time.
9. **Given** a resolved price, **when** it is previewed, **then** base price, source, tier, discount configuration, discount amount, final amount, and floor result are visible.
10. **Given** a confirmed commercial document, **when** pricing configuration later changes, **then** the document retains its stored price.

---

### User Story 4 - Govern and Review Pricing (Priority: P2)

Fixed dashboard roles can perform only their assigned actions and can review tier status, pricing outcomes, changes, and expiry risk.

**Why this priority**: Commercial discounts require controlled administration and complete traceability.

**Independent Test**: Exercise each role through direct pages, record/bulk actions, link management, preview, reporting, audit, restoration, and floor approval.

**Acceptance Scenarios**:

1. **Given** a System Admin, **when** using the feature, **then** all customer, tier, assignment, restoration, role-assignment, reporting, audit, and floor-approval actions are available.
2. **Given** a CRM Manager, **when** using the feature, **then** customer and tier lifecycle, product/customer links, validity, discount editing, reporting, and audit review are allowed; role assignment, restoration, and floor approval are denied.
3. **Given** a Pricing Manager, **when** using the feature, **then** customers and tiers may be viewed, tier discounts may be edited, prices may be previewed, and pricing reports/audit may be reviewed; customer lifecycle and tier link management are denied.
4. **Given** a Reviewer, **when** using the feature, **then** customer, tier, report, preview, and audit data are read-only.
5. **Given** any role, **when** a direct URL, record action, relationship action, or bulk action is attempted, **then** the same permission boundary is enforced.
6. **Given** a pricing-tier list or report, **when** filters are applied, **then** records can be found by name, type, visibility, activity, validity, near-expiry period, customer, and product with paginated results.
7. **Given** a successful lifecycle, link, assignment, discount, or validity change, **when** audit history is reviewed, **then** the actor, action, affected tier, before/after values, and time are available.

## Edge Cases

- Duplicate tier names remain invalid even when the earlier row is soft-deleted.
- Duplicate tier/product and tier/customer links are rejected, including concurrent attempts.
- Removing one product or customer does not modify other links.
- General and product-scoped assignments coexist; assigning a general tier deactivates only another general assignment.
- A customer may hold multiple product-scoped assignments, but only eligible tiers for the selected product participate.
- A fixed discount that produces a zero or negative candidate is rejected for that variant and cannot be used.
- A validity end date earlier than the start date is rejected.
- Deleted records and audit history are not physically removed by dashboard actions.
- Existing pricing behavior is unchanged when no product-scoped tier is eligible.

## Functional Requirements

### Customer Profiles

- **FR-001**: The system MUST maintain one customer profile per customer user.
- **FR-002**: Customer code MUST be required and unique, including against soft-deleted profiles.
- **FR-003**: A profile MUST support company name, address, active state, creator, updater, timestamps, and soft deletion; customer payment terms MUST NOT be configured by this CRM feature.
- **FR-004**: Users MUST be able to search customers by code, company, user name, and email and filter by active/deletion state.
- **FR-005**: Inactive or deleted customers MUST retain history and MUST NOT receive customer-derived pricing.
- **FR-006**: Only a System Admin MAY restore a deleted customer profile.

### Pricing Tiers

- **FR-007**: Pricing tiers MUST be classified as general, customer-specific, or product-scoped.
- **FR-008**: A tier MUST have a unique name, discount configuration, active state, creator, updater, timestamps, and soft deletion.
- **FR-009**: General and customer-specific tiers MUST preserve their existing percentage-based behavior.
- **FR-010**: A customer-specific tier MUST identify one customer; only one active customer-specific tier MAY exist for that customer.
- **FR-011**: A general tier MAY be assigned through the existing customer-tier assignment and only one general assignment MAY be active per customer.
- **FR-012**: A product-scoped tier MAY use a percentage or fixed discount and MUST support visibility plus optional inclusive validity dates.
- **FR-013**: Percentage discounts MUST be between 0 and 100; fixed discounts MUST be greater than zero and MUST NOT yield a zero/negative candidate.
- **FR-014**: A product-scoped tier MUST link at least one active product before activation; the link covers that product's active variants.
- **FR-015**: A product-scoped tier MAY be assigned to multiple active customers through the existing customer-tier assignment store.
- **FR-016**: Each tier/product and tier/customer pair MUST be unique.
- **FR-017**: A restricted product-scoped tier MUST have at least one active customer assignment before activation.
- **FR-018**: Product-scoped tiers restored from deletion MUST remain inactive until explicitly reactivated.

### Pricing and Floor Control

- **FR-019**: Resolution order MUST be active customer-specific tier, eligible product-scoped tier, active assigned general tier, then variant base price.
- **FR-020**: Resolution MUST apply exactly one source and MUST NOT stack discounts.
- **FR-021**: Multiple eligible product-scoped tiers MUST be sorted by final amount then tier identifier.
- **FR-022**: Tier discounts MUST calculate from the variant base price.
- **FR-023**: The existing minimum-price floor MUST apply to every resolved result.
- **FR-024**: Only a System Admin MAY approve a below-floor amount, and approval MUST include a non-empty reason and pricing-tier provenance.
- **FR-025**: A resolved price MUST expose base price, source, tier identifier, discount type/value/amount, final price, minimum price, and floor result.
- **FR-026**: Confirmed documents MUST retain stored pricing after later configuration changes.

### Review, Audit, and Authorization

- **FR-027**: Pricing-tier lists and reports MUST support search, filters, pagination, and expiry-focused views.
- **FR-028**: Tier lifecycle, product/customer relationships, discount, and validity changes MUST use the existing audit history.
- **FR-029**: Tier configuration changes MUST NOT be written as product price-history entries.
- **FR-030**: The system MUST use the four fixed roles System Admin, CRM Manager, Pricing Manager, and Reviewer.
- **FR-031**: Only a System Admin MAY assign fixed dashboard roles; no general permission editor is introduced.
- **FR-032**: Permissions MUST be consistent across navigation, direct pages, record actions, relationship actions, and bulk actions.
- **FR-033**: Multi-record mutations MUST be atomic and reject partial relationships or audit entries.
- **FR-034**: All feature-specific dashboard labels, validation messages, reports, and tests MUST be delivered in English only for this phase.
- **FR-035**: The system MUST expose no standalone Product Subscriptions navigation item, route, resource, model, report type, or runtime pricing source.

## Key Entities

- **Customer Profile**: Existing commercial profile linked one-to-one to a customer user.
- **Pricing Tier**: Existing pricing rule extended with a tier type and, for product-scoped tiers, validity, visibility, and product targeting.
- **Tier Product Link**: Unique relationship between a product-scoped tier and an existing product.
- **Customer Tier Assignment**: Existing customer-to-tier assignment reused for general and product-scoped eligibility.
- **Resolved Price**: Existing pricing result identifying the winning tier or base source and its calculation/floor details.
- **Price Floor Approval**: Existing approval history extended with optional winning pricing-tier provenance.
- **Audit Entry**: Existing immutable activity record used for customer and pricing-tier changes.

## Assumptions and Dependencies

- The existing customer, pricing-tier, customer-tier-assignment, product, price-history, floor-approval, audit, report, and permission capabilities remain canonical.
- Product-scoped links are product-level and cover active variants; variant/category/all-products rules are out of scope.
- Public/restricted is a dashboard classification only and does not create an anonymous or customer-facing offer.
- A product-scoped tier is a discount agreement, not a recurring billed plan.
- Customer payment terms remain a shared sales/accounting concern and are outside this CRM feature.
- English-only applies to this feature revision; unrelated global Arabic resources are not removed.
- Existing sales/accounting flows remain responsible for persisting a chosen unit price before document confirmation.

## Success Criteria

- **SC-001**: An authorized user can create or update a customer profile in under two minutes without duplicating customer identity.
- **SC-002**: An authorized user can configure a product-scoped tier, link products, and assign customers from Pricing Tiers in under five minutes.
- **SC-003**: Every tested overlap produces exactly one deterministic price source.
- **SC-004**: Every unapproved below-floor result is blocked and every approved exception includes complete actor, reason, time, customer, variant, price, and tier provenance.
- **SC-005**: Existing customer-specific/general pricing remains unchanged when no product-scoped tier is eligible.
- **SC-006**: Duplicate tier/product and tier/customer links are prevented, including concurrent attempts.
- **SC-007**: Inactive, deleted, out-of-date, unassigned, or product-unlinked tiers never affect a resolved price.
- **SC-008**: Every required customer and pricing-tier change produces a retrievable audit entry without creating a price-history row.
- **SC-009**: Every fixed role passes permitted-path tests and is denied on every prohibited direct, record, relationship, and bulk action.
- **SC-010**: `/admin/pricing-tiers` is the only pricing-tier configuration surface and `/admin/product-subscriptions` does not resolve.
- **SC-011**: The CI-equivalent `composer test` command completes successfully without weakening its 100% type-coverage or code-coverage thresholds.
