# Feature Specification: CRM Customers & Product Subscriptions

**Feature Branch**: `013-crm-customers-subscriptions`

**Created**: 2026-07-26

**Status**: Draft

**Input**: User description: "CRM module: customer management and product subscriptions. A subscription may cover one customer or multiple customers. A subscription carries product discounts as either a fixed amount or a percentage. A subscription is either public (displayed publicly) or restricted to assigned customers only. Subscription discounts must integrate with the existing customer price tier precedence and minimum price floor rules."

## Sourcing Note

The requester asked for these requirements to be sourced from the inventory SRS
(`IERP_Product_Inventory_Module_SRS_AR.docx`). That document contains **no**
customer-management or subscription sections — verified zero occurrences of
اشتراك (subscription) and خصم/تخفيض (discount) across all 285 content blocks —
and its §6 explicitly places the customer app out of range. The term
"subscription" likewise appears **nowhere** in the canonical documentation set
(`Docs/PRD.md`, `Docs/SDD.md`, `Docs/database/ERD.md`).

Accordingly:

- **Customer management** requirements are derived from the canonical docs:
  `Docs/PRD.md` §5 ("Customer Management": create and manage customer profiles
  used by sales, invoices, tickets, and CRM), PRD FR-022, and the
  `customer_profiles` entity already specified in `Docs/database/ERD.md`.
- **Pricing interaction** requirements are derived from inventory SRS §3.8
  (شرائح أسعار الزبائن) and its already-shipped implementation in
  `specs/007-pricing-controls-customer-tiers`.
- **Subscription** requirements are new, supplied directly by the product owner
  in the feature request. They are not yet reflected in PRD/SDD/ERD. Under
  Constitution Principle I (Specification-First Development) the canonical
  documentation set must be updated to include subscriptions before
  implementation begins. See Assumptions.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manage customer records (Priority: P1)

A System Admin creates and maintains customer records so that sales documents,
invoices, tickets, and CRM activity all reference one authoritative customer
profile instead of an ad-hoc user row.

**Why this priority**: Every other story in this feature depends on a customer
record existing and being addressable. Subscriptions cannot be assigned to
customers that cannot be managed. This is also the one part of the feature
already mandated by PRD FR-022 and the ERD, so it carries no scope risk.

**Independent Test**: Can be fully tested by creating, editing, searching,
deactivating, and restoring a customer record with no subscription present, and
confirming the record is selectable anywhere a customer is referenced.

**Acceptance Scenarios**:

1. **Given** an authenticated System Admin, **When** they create a customer with
   a company name, address, and unique customer code, **Then** the customer is
   saved, becomes searchable by code and company name, and is available for
   selection on sales, ticket, and subscription records.
2. **Given** an existing customer code, **When** an admin tries to save a second
   customer with that same code, **Then** the system rejects the save and reports
   which field collided.
3. **Given** a customer with historical sales documents, **When** an admin
   deactivates the customer, **Then** the customer stops appearing in
   new-document selection lists while all historical documents continue to
   resolve and display that customer.
4. **Given** a non-admin actor, **When** they attempt to create or edit a
   customer record, **Then** the action is refused and the attempt is recorded.

---

### User Story 2 - Create a subscription with product discounts (Priority: P2)

A System Admin defines a subscription that grants a discount on a chosen set of
products, expressing that discount as either a fixed monetary amount off or a
percentage off, so that recurring commercial agreements are configured once and
applied consistently.

**Why this priority**: This is the core new capability. It delivers standalone
value — an admin can model and review commercial agreements — even before
assignment or public display exist.

**Independent Test**: Can be fully tested by creating a subscription with a
percentage discount and a second with a fixed-amount discount, attaching
products to each, and verifying the resulting effective price is computed and
displayed correctly for each discount type without assigning any customer.

**Acceptance Scenarios**:

1. **Given** an admin creating a subscription, **When** they choose a percentage
   discount of 10% and attach a product with a base price of 120, **Then** the
   subscription's effective price for that product shows 108, alongside the base
   price and the discount applied.
2. **Given** an admin creating a subscription, **When** they choose a fixed
   discount of 15 and attach a product with a base price of 120, **Then** the
   subscription's effective price for that product shows 105.
3. **Given** an admin entering a percentage discount, **When** they enter a value
   below 0 or above 100, **Then** the system rejects the value before saving.
4. **Given** an admin entering a fixed discount, **When** the amount is greater
   than or equal to the product's base price, **Then** the system rejects the
   value rather than producing a zero or negative price.
5. **Given** a subscription with a validity window, **When** the current date is
   outside that window, **Then** the subscription's discounts do not apply to
   price resolution.

---

### User Story 3 - Assign a subscription to one or many customers (Priority: P2)

A System Admin assigns a subscription to a single customer for a bespoke
agreement, or to many customers for a shared programme, so that the same
discount terms can be reused without duplicating configuration.

**Why this priority**: Equal in priority to Story 2 — a subscription that cannot
reach a customer produces no commercial effect. Split from Story 2 so that
discount definition and discount reach can be developed and tested
independently.

**Independent Test**: Can be fully tested by assigning one subscription to a
single customer, assigning a second subscription to several customers, and
verifying each customer resolves the discounts of exactly the subscriptions they
are assigned to.

**Acceptance Scenarios**:

1. **Given** a subscription and a customer, **When** the admin assigns the
   customer, **Then** that customer's price resolution for the subscription's
   products reflects the subscription discount.
2. **Given** a subscription assigned to several customers, **When** the admin
   removes one customer from it, **Then** only that customer stops receiving the
   discount and the others are unaffected.
3. **Given** a customer already assigned to a subscription, **When** the admin
   assigns the same customer to it again, **Then** the system prevents the
   duplicate assignment.
4. **Given** a customer assigned to a subscription, **When** that customer is
   deactivated, **Then** the assignment is retained for audit but produces no
   discount while the customer is inactive.

---

### User Story 4 - Control subscription visibility (Priority: P3)

A System Admin marks a subscription as public so it is displayed to customers
browsing available offers, or keeps it restricted so only assigned customers can
see it, so that promotional programmes and confidential agreements can coexist.

**Why this priority**: Visibility is a presentation concern layered on top of a
working subscription. The feature is commercially usable without it, so it ships
after discount definition and assignment.

**Independent Test**: Can be fully tested by creating one public and one
restricted subscription and confirming what each of an assigned customer, an
unassigned customer, and an admin can see.

**Acceptance Scenarios**:

1. **Given** a public subscription, **When** any authenticated customer views
   available subscriptions, **Then** the subscription and its terms are listed
   regardless of assignment.
2. **Given** a restricted subscription, **When** an unassigned authenticated
   customer views available subscriptions, **Then** the subscription is absent
   from the list and is not retrievable by direct reference.
3. **Given** a restricted subscription, **When** an assigned customer views
   available subscriptions, **Then** the subscription is listed.
4. **Given** a public subscription with no assigned customers, **When** an
   unassigned customer views it, **Then** its terms are visible but its discounts
   do not apply to that customer's price resolution.

---

### Edge Cases

- What happens when a subscription discount drives a product's price below that
  product's or variant's configured minimum price floor? The system must block
  the sale at that price and require explicit System Admin approval, recording
  who approved the floor breach and when — identical to the existing tier
  discount floor rule from SRS §3.8.
- What happens when a customer is assigned to two subscriptions that both
  discount the same product? Resolution is defined in FR-020.
- What happens when a subscription's discount and a customer price tier discount
  both apply to the same product? Resolution is defined in FR-019.
- What happens when a product attached to a subscription is deleted or archived?
  The subscription must remain valid and simply stop offering that product.
- What happens when a subscription's validity window is edited so it ends in the
  past? Existing already-priced documents must not be retroactively repriced.
- How does the system handle a percentage discount on a product with no base
  price set? Price resolution must fall back to the product base price rule and
  surface that the discount could not be applied.
- What happens when two admins edit the same subscription's discount
  concurrently? The later save must not silently discard the earlier one.
- How does the system handle a public subscription whose products are all
  inactive? It must not appear as an empty offer to customers.

## Requirements *(mandatory)*

### Functional Requirements

#### Customer management

- **FR-001**: System MUST allow a System Admin to create, view, edit, and
  soft-delete customer records.
- **FR-002**: System MUST enforce a unique customer code on every customer
  record.
- **FR-003**: System MUST capture at minimum a customer code, company name,
  primary address, and default payment term reference per customer.
- **FR-004**: System MUST allow a customer record to be deactivated without
  breaking any historical document that references it.
- **FR-005**: System MUST allow admins to search and filter customers by
  customer code and company name.
- **FR-006**: System MUST restrict all customer create, edit, and delete actions
  to System Admin actors.
- **FR-007**: System MUST record an audit entry for every customer create, edit,
  deactivate, and delete action, capturing the acting user and timestamp.

#### Subscription definition

- **FR-008**: System MUST allow a System Admin to create, view, edit, and
  soft-delete subscriptions.
- **FR-009**: Each subscription MUST carry a name, a discount type, a discount
  value, a visibility setting, an active flag, and an optional validity window
  with a start and end date.
- **FR-010**: System MUST support exactly two discount types per subscription: a
  fixed monetary amount off, or a percentage off.
- **FR-011**: System MUST reject a percentage discount value outside the range 0
  to 100 inclusive.
- **FR-012**: System MUST reject a fixed discount amount that is negative, and
  MUST reject one that is greater than or equal to the base price of any product
  the subscription is attached to.
- **FR-013**: Users MUST be able to attach products to a subscription and detach
  them, and the subscription MUST apply its discount only to attached products.
- **FR-014**: System MUST treat a subscription as producing no discount when it
  is inactive, soft-deleted, or the current date falls outside its validity
  window.

#### Customer assignment

- **FR-015**: Users MUST be able to assign a subscription to one customer or to
  many customers, and a customer MUST be assignable to more than one
  subscription.
- **FR-016**: System MUST prevent the same customer being assigned to the same
  subscription more than once.
- **FR-017**: System MUST retain a subscription assignment for audit when the
  assigned customer is deactivated, while suppressing the discount for that
  customer.
- **FR-018**: System MUST record an audit entry when a customer is assigned to
  or removed from a subscription.

#### Price resolution and integrity

- **FR-019**: System MUST place subscription discounts in a single, documented
  precedence order together with the existing customer price tier discounts,
  such that price resolution for any customer and product is deterministic and
  the applied source is reportable. [NEEDS CLARIFICATION: where does a
  subscription discount sit relative to the existing order — customer-specific
  tier, then general tier, then base price — and does it stack with a tier
  discount or replace it?]
- **FR-020**: When a customer is assigned to multiple subscriptions that discount
  the same product, System MUST resolve to exactly one applied discount by a
  deterministic rule rather than summing them, and MUST make the chosen
  subscription identifiable.
- **FR-021**: System MUST NOT allow a subscription discount to produce a final
  price below the product's or variant's configured minimum price floor; the
  system MUST block the transaction and require explicit System Admin approval,
  recording the approving user and approval time, consistent with the existing
  price floor override behaviour.
- **FR-022**: System MUST expose, for any resolved price, the base price, the
  discount source, the discount type and value, and the resulting effective
  price.
- **FR-023**: System MUST NOT retroactively reprice documents that were already
  priced when a subscription's terms, assignments, or validity window later
  change.

#### Visibility

- **FR-024**: Each subscription MUST be either public or restricted to assigned
  customers only.
- **FR-025**: System MUST list a public subscription to every authenticated
  customer regardless of assignment.
- **FR-026**: System MUST NOT disclose a restricted subscription — by listing or
  by direct reference — to any customer not assigned to it.
- **FR-027**: System MUST apply a subscription's discounts only to customers
  assigned to it, including for public subscriptions, so that public visibility
  grants sight of the terms but not entitlement to them.

#### Scope of discounted products

- **FR-028**: System MUST define how the set of discounted products is selected
  for a subscription. [NEEDS CLARIFICATION: are products attached individually,
  by product category, or may a subscription apply to all products with
  exclusions?]

### Key Entities *(include if feature involves data)*

- **Customer**: A commercial party the company sells to. Holds a unique customer
  code, company name, primary address, and default payment term. Linked
  one-to-one with the user account that represents that customer, consistent
  with the actor separation already in place. Referenced by sales documents,
  invoices, tickets, CRM activity, and subscription assignments.
- **Subscription**: A named commercial agreement or programme granting product
  discounts. Holds a discount type (fixed or percentage), a discount value, a
  visibility setting (public or restricted), an active flag, and an optional
  validity window. Relates to many products and to many customers.
- **Subscription Product**: The link between a subscription and a product that
  the subscription discounts. Determines which products the subscription's
  discount reaches.
- **Subscription Customer Assignment**: The link between a subscription and a
  customer entitled to its discounts. Unique per subscription-and-customer pair.
  Retained for audit after a customer is deactivated.
- **Resolved Price**: The outcome of applying precedence rules for a given
  customer and product. Reports the base price, the winning discount source, the
  discount type and value, and the effective price — and whether a minimum price
  floor approval was required.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An admin can create a customer record and have it selectable on a
  sales document in under 2 minutes.
- **SC-002**: An admin can define a subscription, attach products, and assign
  customers in under 5 minutes without consulting documentation.
- **SC-003**: For any customer and product pair, the system reports one and only
  one applied discount source, verified across every combination of price tier
  and subscription overlap.
- **SC-004**: 100% of attempts to price below a product's minimum price floor are
  blocked, and every approved floor breach has a recorded approver and approval
  time with no gaps.
- **SC-005**: No restricted subscription is disclosed to an unassigned customer
  in any listing or direct-reference attempt, verified by negative-path tests for
  both access routes.
- **SC-006**: Both discount types produce a correct effective price for every
  product attached to a subscription, verified against worked examples including
  the SRS §3.8 case of base price 120 at 10% resolving to 108.
- **SC-007**: Changing a subscription's terms leaves all previously priced
  documents unchanged.
- **SC-008**: Every customer and subscription create, edit, assign, and delete
  action produces a retrievable audit entry naming the actor and time.

## Assumptions

- **Customer identity reuses the documented model.** A customer is a user
  account of customer type with an associated customer profile, as already
  specified in `Docs/database/ERD.md` (`users.user_type` enum, `customer_profiles`
  linked one-to-one to `users`). No separate parallel customer identity is
  introduced. This keeps the existing `customer_user_id` references in
  `specs/007-pricing-controls-customer-tiers` valid and requires no migration of
  those references.
- **"Public" means visible to authenticated customers, not anonymous
  visitors.** The constitution places website implementation explicitly out of
  scope, so public visibility is interpreted as "listed to every authenticated
  customer in the customer app", not "published on a public website". If the
  product owner intends anonymous public display, that is a scope change
  requiring an approved exception.
- **Subscriptions are discount agreements, not billed recurring plans.** This
  spec treats a subscription as a named discount programme with a validity
  window. It does **not** assume recurring fees, renewal cycles, subscription
  invoicing, or tax recognition. If subscriptions are meant to be *paid*
  recurring plans, that pulls in the sales and payment lifecycle governed by
  Constitution Principle III (Financial & Inventory Integrity) and materially
  expands scope beyond this spec.
- **Canonical documentation must be updated first.** Subscriptions appear in no
  canonical document. Constitution Principle I requires implementation to derive
  from the approved documentation set, and requires database design to be
  finalized before implementation of anything touching persisted data.
  `Docs/PRD.md`, `Docs/SDD.md`, and `Docs/database/ERD.md` therefore need a
  subscriptions entry approved by the project owner before this feature is
  built.
- **The admin surface for this module is not Filament.** The constitution places
  a Filament dashboard dependency out of scope for every module except Inventory,
  which holds a written exception (ADR 0001). A Filament admin panel for CRM
  subscriptions requires a separate ADR with project-owner approval. Absent that,
  the admin surface here follows the project's API-plus-dashboard pattern, and
  this spec deliberately describes screens, flows, and states rather than a
  specific framework.
- **CRM module scope is being extended.** The documented CRM module covers leads,
  interactions, campaigns, recipients, and responses. Customer management is its
  own documented feature area, and subscriptions are new. Placing subscriptions
  under CRM is the product owner's stated intent and is recorded here as a
  deliberate extension of that module's documented boundary.
- **Existing pricing machinery is reused, not replaced.** The minimum price floor
  rule, the floor override approval record, and the customer price tier
  precedence chain already exist from `specs/007-pricing-controls-customer-tiers`.
  Subscription pricing extends that chain rather than introducing a second,
  parallel price resolution path.
- **Fixed-amount discounts are a new capability.** Existing tier discounts are
  percentage-only. Supporting a fixed monetary discount is additive and must not
  change the behaviour of existing percentage tiers.
