# Contract: Pricing Tiers Dashboard and Authorization

This feature extends the existing /admin dashboard. It adds no second dashboard or customer-facing interface.

## Canonical Screen

/admin/pricing-tiers is the only pricing-tier configuration surface.

The existing manage page keeps table-based create/edit/delete/restore behavior and adds row/modal actions for product-scoped configuration. No /admin/product-subscriptions route, resource, direct URL, navigation item, or relation manager remains.

## Pricing Tier Form

Common fields:

- name;
- tier type;
- discount value;
- active state.

Conditional fields:

- customer-specific: one customer; percentage discount only;
- general: percentage discount only;
- product-scoped: percentage/fixed discount, public/restricted visibility, optional inclusive start/end dates.

Validation and field visibility follow tier type. Switching type clears incompatible values and is rejected when existing links/assignments would be orphaned.

## Pricing Tier Table

Columns:

- name;
- tier type;
- discount summary;
- customer for customer-specific tiers;
- visibility/status/validity for product-scoped tiers;
- product and active-customer counts;
- active state and updated time.

Search/filter support:

- name;
- tier type;
- customer;
- product;
- visibility;
- active/inactive;
- scheduled/current/expired;
- near-expiry period;
- trashed state.

## Row and Header Actions

- create/edit pricing tier;
- activate/deactivate;
- manage products for product-scoped tiers;
- manage customer assignments for product-scoped tiers;
- assign a general tier to a customer;
- read-only price preview;
- soft-delete;
- restore.

Product/customer management uses searchable multi-select modal actions on the Pricing Tiers page and calls the transactional tier service. No failed bulk action leaves partial links.

## Price Preview

Inputs:

- active customer;
- product linked to the selected product-scoped tier when applicable;
- active variant;
- business date defaulting to today.

Output:

- base price;
- customer-specific candidate;
- eligible product-scoped candidates;
- product-scoped tie-break explanation;
- general candidate;
- winning source/tier;
- discount type/value/amount;
- final/minimum price and floor warning.

Preview creates no commercial document, price-history row, or floor approval.

## Fixed Role Matrix

| Ability | System Admin | CRM Manager | Pricing Manager | Reviewer |
|---|:---:|:---:|:---:|:---:|
| View customers and tiers | Yes | Yes | Yes | Yes |
| Manage customer lifecycle | Yes | Yes | No | No |
| Restore customer/tier | Yes | No | No | No |
| Create/manage tier lifecycle | Yes | Yes | No | No |
| Edit tier discount | Yes | Yes | Yes | No |
| Manage product/customer links | Yes | Yes | No | No |
| Preview pricing | Yes | Yes | Yes | Yes |
| View pricing reports/audit | Yes | Yes | Yes | Yes |
| Approve below-floor amount | Yes | No | No | No |
| Assign fixed dashboard roles | Yes | No | No | No |

Existing inventory-pricing permissions remain valid for existing inventory actors. The same authorization is enforced on navigation, direct URLs, record actions, relationship actions, and bulk actions.

## Reporting and Audit

- Extend existing Pricing Tiers and Customer Assignments reports.
- Add a Pricing Tier Eligibility report only for product/status/validity analysis not represented by those existing reports.
- Reuse the current export/formatter flow and generic Audit Log resource.
- Use pricing.tier action names; do not create tier-specific audit storage.

## Language and Error Contract

Feature-specific labels, messages, report headings, and tests are English-only for this phase. Unrelated global Arabic resources remain untouched.

Clear validation/danger messages are required for duplicate names/links, invalid discounts/dates, missing activation prerequisites, inactive customers/products, non-positive fixed candidates, below-floor use without approval, and unauthorized actions.
