# Feature Specification: CRM Customers and Product Subscriptions

**Feature Directory**: `013-crm-customers-subscriptions`

**Created**: 2026-07-26

**Updated**: 2026-07-29

**Status**: Ready for planning

**Input**: `IERP_CRM_Customers_Product_Subscriptions_Dashboard_SRS.pdf`, the
current IERP dashboard behavior, and product-owner clarifications.

## Scope

This feature extends the existing dashboard customer and pricing capabilities
with product discount subscriptions. It does not replace existing customer
profiles, pricing tiers, price history, price-floor approvals, audit history,
or reporting.

Included:

- Maintain customer profiles linked to customer user accounts.
- Define percentage or fixed-amount product discount subscriptions.
- Link subscriptions to products and assign them to one or many customers.
- Resolve one deterministic price using the established pricing hierarchy.
- Enforce the existing minimum-price floor and approval rules.
- Search, filter, preview, report, restore, and audit dashboard activity.
- Apply fixed dashboard roles and permissions.
- Support Arabic right-to-left dashboard operation.

Excluded:

- Customer, employee, mobile, website, or anonymous public interfaces.
- Recurring fees, billing cycles, renewals, subscription invoices, or payment
  collection.
- Replacing or duplicating pricing tiers, price history, floor-approval,
  auditing, reporting, product catalog, user, or customer-profile capabilities.
- Repricing confirmed commercial or financial documents.

## User Scenarios and Testing

### User Story 1 - Maintain customer profiles (Priority: P1)

An authorized dashboard user maintains the commercial details and lifecycle of
a customer while preserving the customer's existing user identity and history.

**Why this priority**: Customer identity and activity state determine who may be
assigned to and benefit from a subscription.

**Independent Test**: Create, search, update, deactivate, soft-delete, and
restore a customer profile without creating a subscription.

**Acceptance Scenarios**:

1. **Given** a customer user without a profile, **When** an authorized user
   creates a profile with a unique customer code, **Then** one profile is linked
   to that customer user and it is searchable by code, company, name, and email.
2. **Given** a customer code or user already linked to a profile, **When** a
   duplicate is submitted, **Then** the save is rejected with a clear field
   error.
3. **Given** a customer with historical records, **When** the customer is
   deactivated or soft-deleted, **Then** history remains available but the
   customer receives no subscription entitlement and cannot be selected for a
   new active assignment.
4. **Given** a soft-deleted customer profile, **When** a System Admin restores
   it, **Then** the profile returns without losing its user link or history and
   the restore is audited.
5. **Given** an organization-wide payment term exists, **When** a customer
   profile is edited, **Then** it may reference that shared payment term as its
   default without creating a customer-specific duplicate term.

---

### User Story 2 - Define a product subscription (Priority: P1)

An authorized dashboard user defines a named discount agreement, chooses a
percentage or fixed-amount discount, links products, and controls its active
period and visibility classification.

**Why this priority**: This is the new commercial capability at the center of
the feature.

**Independent Test**: Create percentage and fixed subscriptions, link products,
change dates and activity, soft-delete and restore them, and preview their
effective prices without assigning customers.

**Acceptance Scenarios**:

1. **Given** a product whose variant has a base price of 120, **When** a 10%
   subscription is previewed, **Then** the calculated subscription candidate is
   108 before floor validation.
2. **Given** a product whose variant has a base price of 120, **When** a fixed
   discount of 15 is previewed, **Then** the calculated subscription candidate
   is 105 before floor validation.
3. **Given** a percentage below 0 or above 100, or a fixed discount that would
   produce a zero or negative price, **When** the subscription is saved or
   previewed, **Then** the operation is rejected with a clear error.
4. **Given** a subscription with no linked product, **When** activation is
   attempted, **Then** activation is refused.
5. **Given** a restricted subscription with no active assigned customer,
   **When** activation is attempted, **Then** activation is refused.
6. **Given** a public subscription, **When** it is activated, **Then** it is
   classified as public for dashboard filtering and preview only; the
   classification does not itself grant a discount to any customer.
7. **Given** a restored subscription, **When** restoration completes, **Then**
   it is inactive until an authorized user explicitly reactivates it.

---

### User Story 3 - Link products and customers (Priority: P1)

A CRM Manager or System Admin attaches products and assigns one or many active
customers to a subscription without duplicating products, customers, or
assignments.

**Why this priority**: A subscription has no pricing effect until both its
product scope and customer entitlement are defined.

**Independent Test**: Link multiple products and customers, prevent duplicate
links, remove one link, and verify other links remain unchanged.

**Acceptance Scenarios**:

1. **Given** an active product, **When** it is linked to a subscription, **Then**
   all of that product's active variants are eligible for that subscription's
   discount calculation.
2. **Given** an existing product or customer link, **When** the same link is
   submitted again, **Then** the duplicate is prevented.
3. **Given** an inactive or deleted customer, **When** a new assignment is
   attempted, **Then** the assignment is rejected.
4. **Given** an assigned customer that later becomes inactive, **When** price
   eligibility is evaluated, **Then** the assignment remains visible for
   history but grants no discount.
5. **Given** several assigned customers, **When** one is removed, **Then** only
   that customer's entitlement ends.

---

### User Story 4 - Resolve the effective customer price (Priority: P1)

The dashboard resolves one auditable price for a customer and product variant
without stacking discounts or changing the behavior of existing pricing tiers.

**Why this priority**: Incorrect precedence or stacking can create direct
commercial loss.

**Independent Test**: Evaluate all combinations of customer-specific tier,
eligible subscriptions, general tier, base price, validity, customer activity,
and floor approval.

**Acceptance Scenarios**:

1. **Given** an active customer-specific pricing tier, **When** a price is
   resolved, **Then** it wins over subscriptions, the general tier, and base
   price.
2. **Given** no customer-specific tier and one eligible subscription, **When**
   a price is resolved, **Then** the subscription wins over the general tier and
   base price.
3. **Given** several eligible subscriptions for the same customer and product,
   **When** a price is resolved, **Then** the candidate with the lowest final
   price wins; if candidates are equal, the subscription created first by
   identifier wins.
4. **Given** no customer-specific tier or eligible subscription, **When** a
   price is resolved, **Then** the active general customer tier is used, or the
   base price if none applies.
5. **Given** overlapping sources, **When** a price is resolved, **Then** exactly
   one source is applied and discounts are never added or compounded.
6. **Given** an inactive, deleted, not-yet-valid, expired, unassigned, or
   product-unlinked subscription, **When** a price is resolved, **Then** that
   subscription is ignored.
7. **Given** a winning candidate below the variant's minimum price, **When** it
   is used without approval, **Then** the operation is blocked.
8. **Given** a winning candidate below the minimum price, **When** a System
   Admin approves it with a reason, **Then** the existing floor-approval history
   records the customer, variant, attempted price, floor, approver, reason, time,
   and subscription source.
9. **Given** a resolved price, **When** it is previewed or inspected, **Then**
   the base price, winning source, source identifier, discount type, discount
   value, discount amount, final price, and floor result are visible.
10. **Given** a confirmed document, **When** tiers, subscriptions, assignments,
    dates, or base prices later change, **Then** the confirmed document retains
    its stored price.

---

### User Story 5 - Govern and review the feature (Priority: P2)

Authorized dashboard roles can perform only their assigned actions and can
review subscription status, pricing outcomes, changes, and expiry risk.

**Why this priority**: Commercial discounts require controlled administration
and complete traceability.

**Independent Test**: Exercise each role against record and bulk actions, review
audit entries, and filter reports by customer, product, state, and date.

**Acceptance Scenarios**:

1. **Given** a System Admin, **When** using this feature, **Then** all customer,
   subscription, assignment, restore, role-assignment, reporting, audit, and
   floor-approval actions are available.
2. **Given** a CRM Manager, **When** using this feature, **Then** customer and
   subscription lifecycle, links, validity, discount editing, reporting, and
   audit review are allowed, but role assignment and floor approval are denied.
3. **Given** a Pricing Manager, **When** using this feature, **Then** customers
   and subscriptions may be viewed, subscription discounts may be edited,
   prices may be previewed, and pricing reports may be reviewed, but customer
   lifecycle and subscription link management are denied.
4. **Given** a Reviewer, **When** using this feature, **Then** customer,
   subscription, report, and audit data are read-only.
5. **Given** any role, **When** a record or bulk action is attempted, **Then**
   the same permission boundaries are enforced.
6. **Given** a subscription list or report, **When** filters are applied, **Then**
   records can be found by name, visibility, activity, current validity,
   near-expiry period, customer, and product with paginated results.
7. **Given** a create, update, activation, deactivation, link, unlink, delete,
   restore, discount, or validity change, **When** it succeeds, **Then** the
   actor, action, affected entity, before/after values, and time are retrievable
   in the existing audit history.
8. **Given** the dashboard is used in Arabic, **When** this feature is opened,
   **Then** labels, validation messages, navigation, tables, forms, and actions
   are understandable and rendered right-to-left.

## Edge Cases

- `valid_from` without `valid_until` means effective from that date onward;
  `valid_until` without `valid_from` means effective until that date.
- A validity end date cannot precede its start date.
- A public subscription still requires a customer assignment to grant a
  discount.
- Product inactivity, deletion, or variant inactivity removes that item from
  new price eligibility without deleting historical links.
- Customer or subscription soft deletion never removes audit, assignment, or
  pricing history.
- A fixed discount is evaluated independently for each active product variant
  because variants may have different base and minimum prices.
- Concurrent duplicate customer or product assignments result in one link, not
  duplicate entitlement.
- Unauthorized direct access and unauthorized bulk actions are denied even when
  a navigation item or button is hidden.

## Functional Requirements

### Customer Profiles

- **FR-001**: The system MUST maintain one customer profile per customer user.
- **FR-002**: Customer code MUST be required and unique, including against
  soft-deleted profiles.
- **FR-003**: The profile MUST support company name, address, a reference to the
  shared default payment term, active state, creator, updater, timestamps, and
  soft deletion.
- **FR-004**: Users MUST be able to search customers by code, company, user name,
  and email and filter by active and deletion state.
- **FR-005**: An inactive or deleted customer MUST retain history but MUST NOT
  receive subscription entitlement.
- **FR-006**: Only a System Admin MAY restore a deleted customer profile.

### Product Subscriptions

- **FR-007**: A subscription MUST have a unique name, discount type, discount
  value, visibility, active state, optional validity start and end, creator,
  updater, timestamps, and soft deletion.
- **FR-008**: Discount type MUST be percentage or fixed amount.
- **FR-009**: Percentage discounts MUST be between 0 and 100; fixed discounts
  MUST be greater than zero and MUST NOT produce a zero or negative candidate.
- **FR-010**: A subscription MUST link to one or more individual products before
  activation, and its product link MUST cover that product's active variants.
- **FR-011**: A subscription MAY be assigned to one or many active customer
  profiles, and each subscription/customer pair MUST be unique.
- **FR-012**: Each subscription/product pair MUST be unique.
- **FR-013**: A restricted subscription MUST have at least one active customer
  assignment before activation.
- **FR-014**: A restored subscription MUST remain inactive until explicitly
  reactivated.
- **FR-015**: A subscription MUST be ignored for pricing when inactive,
  soft-deleted, outside its validity period, not assigned to the active customer,
  or not linked to the product.

### Pricing and Floor Control

- **FR-016**: Price resolution MUST use this order: active customer-specific
  pricing tier, eligible product subscription, active general customer pricing
  tier, then variant base price.
- **FR-017**: Price resolution MUST apply exactly one source and MUST NOT stack
  discounts.
- **FR-018**: If multiple subscriptions are eligible, the lowest final price
  MUST win; an equal result MUST be resolved by the earliest subscription
  identifier.
- **FR-019**: Subscription percentage and fixed discounts MUST use the existing
  variant base price as their calculation base.
- **FR-020**: The existing variant minimum-price floor MUST apply to every
  subscription result.
- **FR-021**: Only a System Admin MAY approve a price below the floor, and an
  approval MUST include a non-empty reason and complete provenance.
- **FR-022**: A resolved price MUST identify the base price, applied source and
  identifier, discount type and value, discount amount, final price, and floor
  result.
- **FR-023**: Confirmed documents MUST retain stored pricing after later pricing
  configuration changes.

### Visibility, Review, and Audit

- **FR-024**: Visibility MUST be classified as public or restricted for
  dashboard search, filtering, reporting, and preview.
- **FR-025**: Visibility MUST NOT create a customer-facing or anonymous
  interface and MUST NOT grant entitlement without assignment.
- **FR-026**: Authorized users MUST be able to preview a subscription for a
  selected active customer, product, and variant without modifying a document.
- **FR-027**: Lists and reports MUST support search, filters, pagination, and
  expiry-focused views.
- **FR-028**: Customer and subscription lifecycle, relationship, discount, and
  validity changes MUST use the existing audit history.
- **FR-029**: Existing product price history MUST continue to represent variant
  price-setting changes; subscription configuration changes MUST be represented
  in audit history instead of being inserted as product price-history records.

### Roles and Permissions

- **FR-030**: The system MUST use four fixed dashboard roles: System Admin, CRM
  Manager, Pricing Manager, and Reviewer.
- **FR-031**: A System Admin MUST be able to assign these fixed roles to
  dashboard users; the feature MUST NOT introduce a general permission editor.
- **FR-032**: A CRM Manager and Pricing Manager MAY edit subscription discount
  terms.
- **FR-033**: Only a System Admin MAY manage role assignments, restore deleted
  records, or approve a price-floor exception.
- **FR-034**: A Reviewer MUST have read-only access.
- **FR-035**: Permissions MUST be applied consistently to navigation, pages,
  record actions, relationship actions, and bulk actions.

### Usability and Reliability

- **FR-036**: All forms MUST reject invalid dates, discounts, duplicate links,
  inactive assignment targets, and invalid state transitions with clear
  messages.
- **FR-037**: Multi-record changes MUST complete atomically so partial links,
  assignments, audit entries, or approvals are not left behind.
- **FR-038**: Dashboard labels and messages MUST support Arabic right-to-left
  operation.

## Key Entities

- **Customer Profile**: Existing commercial profile linked one-to-one to a
  customer user. Activity controls subscription entitlement.
- **Product Subscription**: New discount agreement containing one discount rule,
  visibility classification, lifecycle state, and optional validity window.
- **Subscription Product Link**: Unique link between a subscription and an
  existing product; eligibility is evaluated for the product's active variants.
- **Customer Subscription Assignment**: Unique link between a subscription and
  an existing customer profile.
- **Resolved Price**: Existing pricing outcome extended to identify the winning
  tier, subscription, or base-price source and its calculation details.
- **Price Floor Approval**: Existing approval history extended, when necessary,
  to identify the subscription that produced the below-floor candidate.
- **Audit Entry**: Existing immutable activity record used for customer and
  subscription changes.

## Assumptions and Dependencies

- The supplied dashboard SRS is the feature's business source. Where the older
  draft conflicts with it, the supplied SRS and the product-owner decisions in
  this specification prevail.
- Customer profiles, customer-specific and general pricing tiers, price
  history, floor approvals, audit history, product catalog, and reporting
  already exist and will be extended rather than recreated.
- Payment terms are organization-wide reference data. If their shared catalog
  is not yet available, adding the customer selector and enforcing its
  reference is a prerequisite owned by that shared feature; this CRM feature
  will not create a second payment-term catalog.
- Product subscription links are product-level. Variant-level links, category
  rules, all-products rules, and exclusion lists are outside this feature.
- Public/restricted is a dashboard classification only. No external interface is
  implied.
- Subscription is a discount agreement, not a billed recurring plan.
- Existing sales and accounting flows are responsible for persisting the
  resolved unit price on a document before confirmation.
- The existing dashboard is the approved implementation surface for this
  feature; project governance must record the CRM dashboard exception before
  production code is implemented.

## Success Criteria

- **SC-001**: An authorized user can create or update a customer profile in
  under two minutes without creating duplicate customer identity.
- **SC-002**: An authorized user can define a subscription, link products, and
  assign customers in under five minutes.
- **SC-003**: Every tested combination of tier and subscription overlap produces
  exactly one deterministic price source.
- **SC-004**: 100% of unapproved below-floor prices are blocked and 100% of
  approved exceptions include actor, reason, time, customer, variant, prices,
  and source.
- **SC-005**: Existing customer and pricing behavior remains unchanged when no
  eligible subscription is present.
- **SC-006**: 100% of duplicate product and customer links are prevented,
  including concurrent attempts.
- **SC-007**: 100% of inactive, deleted, not-yet-valid, expired, or unassigned
  subscriptions are excluded from price resolution.
- **SC-008**: Every required customer and subscription change produces a
  retrievable audit entry.
- **SC-009**: Each fixed role passes its permitted-path tests and is denied on
  every prohibited record and bulk action.
- **SC-010**: Confirmed document prices remain unchanged after any later
  subscription or tier configuration change.
