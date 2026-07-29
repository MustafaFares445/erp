# Contract: Dashboard UI and Authorization

This feature extends the existing `/admin` dashboard. It does not add a second
dashboard or a customer-facing interface.

## Existing Screens Kept

| Screen | Treatment |
|---|---|
| Customers | Extend current resource in place |
| Pricing Tiers | Keep behavior; add no duplicate tier screen |
| Customer Pricing Tiers | Keep behavior |
| Price History | Keep read-only; subscription changes do not appear here |
| Price Floor Overrides | Keep read-only; display/filter subscription provenance |
| Inventory/Pricing Reports | Extend current report types and filters |
| Product Variants | Keep pricing form; add read-only effective-price/subscription context only where useful |

## New Screen

### Product Subscriptions

Navigation:

- Group: CRM
- Pages: list, create, view, edit
- Uses soft-delete and restore actions

List columns:

- Name
- Discount summary
- Visibility
- Derived status
- Valid from / valid until
- Linked product count
- Assigned active customer count
- Updated time

Search and filters:

- Name
- Visibility
- Active/inactive
- Scheduled/current/expired/deleted
- Near expiry date range
- Product
- Customer
- Trashed

Record actions:

- View
- Edit
- Activate/deactivate
- Preview price
- Soft-delete
- Restore as inactive

No force-delete action is exposed.

## Product and Customer Links

Use relationship managers or equivalent resource sections on the subscription
view/edit pages:

- Products: attach active products, detach, search, prevent duplicates.
- Customers: attach active customer profiles, detach, search by code/company/
  name/email, prevent duplicates.

All attach/detach and bulk actions call explicit authorization. Hidden buttons
are not the authorization boundary.

## Existing Customer Screen Extension

Add:

- Shared default payment-term selector only when the canonical Payment Terms
  module exists.
- Assigned subscriptions read-only section for Reviewer/Pricing Manager.
- Attach/detach management for CRM Manager/System Admin.
- Derived eligibility/status indicators.
- Existing active/deactivate, soft-delete, and restore behavior remains.

Do not add another customer route, table, model, or resource.

## Price Preview

Inputs:

- Active customer
- Linked product
- Active variant of that product
- Optional business date for preview, defaulting to today

Output:

- Base price
- Customer-specific tier candidate, when present
- Eligible subscription candidates
- Winning subscription and tie-break explanation
- General tier candidate, when present
- Winning source
- Discount type/value/amount
- Final price
- Minimum price and floor warning

Preview is read-only and does not create a price-floor approval.

## Fixed Role Matrix

| Ability | System Admin | CRM Manager | Pricing Manager | Reviewer |
|---|:---:|:---:|:---:|:---:|
| View customers/subscriptions | Yes | Yes | Yes | Yes |
| Create/update customers | Yes | Yes | No | No |
| Deactivate/delete customers | Yes | Yes | No | No |
| Restore customers | Yes | No | No | No |
| Create subscription | Yes | Yes | No | No |
| Edit discount terms | Yes | Yes | Yes | No |
| Edit validity/visibility/activity | Yes | Yes | No | No |
| Attach/detach products | Yes | Yes | No | No |
| Assign/unassign customers | Yes | Yes | No | No |
| Preview pricing | Yes | Yes | Yes | Yes |
| View reports/audit | Yes | Yes | Yes | Yes |
| Approve below-floor price | Yes | No | No | No |
| Assign fixed dashboard roles | Yes | No | No | No |

The same matrix applies to direct URLs, relation actions, and bulk actions.

## Audit View

- Reuse the generic audit resource if it exists at implementation time.
- Otherwise add one reusable read-only Audit Log screen, not a
  subscription-specific audit table/resource.
- Filter by entity type, entity ID, action, actor, and date.
- Customer and subscription pages may deep-link to the filtered audit view.

## Arabic and RTL

- Add English and Arabic translation keys for all new labels and messages.
- Replace hardcoded customer labels touched by this work with translation keys.
- Reuse the existing panel RTL behavior.
- Discount, amount, date, and status presentation must remain clear in both
  directions.

## Error Contract

Dashboard actions provide a clear validation or danger notification for:

- Duplicate name, product link, or customer assignment
- Invalid discount or date range
- Activation without products
- Restricted activation without an active customer
- Assignment of an inactive/deleted customer
- Fixed discount producing zero/negative price
- Below-floor candidate without approval
- Unauthorized record, relationship, or bulk action

No failed multi-record action leaves partial relationships.
