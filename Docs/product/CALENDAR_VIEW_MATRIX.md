# IERP Calendar / List View Matrix

## Decision

Provide a **Calendar** action beside the original Filament list, and a **List view** action on the calendar page. This is an alternative *read-only presentation* of the same resource, not a new CRUD surface. Existing tables, filters, bulk actions, business services and role policies remain intact.

Only operational schedule, deadline, validity or expiry fields qualify. Audit-only timestamps (`created_at`, `updated_at`, approval/posting timestamps) do not justify a calendar.

## Enabled resources

| Domain | Resource | Calendar date(s) | Purpose |
| --- | --- | --- | --- |
| Employees | Tasks | `starts_at`, `due_at` | Planned start and task deadline |
| Employees | Monthly Plans | `month` (displayed across month) | Monthly execution strategies / employee plans |
| Employees | Visits | `scheduled_start_at` | Visit appointments (pre-existing calendar) |
| Support | Maintenance Schedules | schedule occurrence `due_on` | Recurring maintenance (pre-existing calendar) |
| Support | Service Appointments | `scheduled_start_at` | Field technician appointments |
| Support | Service Records | `due_at` | Technician task deadlines |
| Support | Tickets | `response_due_at`, `resolution_due_at` | SLA response and resolution deadlines |
| Support | Maintenance Requests | `warranty_expiry_date` | Recorded warranty expiry |
| Support | Support Entitlements | `starts_on`, `ends_on` | Coverage validity |
| Inventory | Inventory Lots | `expires_at` | Expiring physical stock; excludes lots without positive on-hand balance |
| Inventory | Serialized Inventory Units | `warranty_expires_on` | Per-unit warranty expiries |
| Inventory | Inventory Reservations | `expires_at` | Reservation expiry |
| Sales | Orders | `scheduled_at` | Scheduled orders |
| Sales | Delivery Notes | `scheduled_at` | Planned deliveries |
| Sales | Quotations | `expires_at` | Offer validity |
| Sales | Invoices | `due_date` | Customer payment deadlines |
| CRM | Sales Opportunities | `expected_close_date` | Expected close milestones |
| CRM | Campaigns | `scheduled_at` | Marketing send schedule |
| Purchasing | Purchase Orders | `expected_at` | Expected supplier delivery |
| Purchasing | Purchase Agreements | `starts_on`, `ends_on` | Contract validity |
| Purchasing | Purchase RFQs | `needed_by`, `closes_at` | Procurement milestones |
| Purchasing | Supplier Confirmations | `promised_at` | Supplier commitment dates |
| Purchasing | Supplier Product References | `valid_from` | Supplier-reference effective date |
| Accounting | Bills | `due_date` | Supplier-payable deadlines |
| Accounting | Expenses | `due_date` | Planned expense settlement |
| Accounting | Fiscal Periods | `starts_at`, `ends_at` | Opening and closing periods |
| Sales settings | Pricing Tiers | `valid_from`, `valid_until` | Price-rule effective dates |

## Interaction and QA contract

- Calendar supports **month / week / day**, previous/next, today, clickable records and Arabic/English RTL/LTR.
- Records retain their current persisted state and domain actions; the calendar does not change or silently reschedule dates.
- Only records with a populated, applicable date appear. Start/end events are separate; monthly plans span the entire active month.
- Role/resource authorization is enforced through the existing Filament resource routes and policies.
- Tables remain the default presentation. Existing list filters are not changed by the calendar feature; calendar date navigation is independent of table pagination.
- Direct links open authorized resource view/edit pages; modal-only resources fall back to their list. Appointments link to the associated service record when authorized.
- `ResourceCalendarEventService::definitions()` is the source of truth for calendar-enabled resources; corresponding pages are under each Resource's `Pages` directory.
- Validation: `tests/Feature/Filament/ResourceCalendarTest.php`, read-only service smoke for configured resource queries, route registration and Blade view compilation.

## Intentionally excluded

Products, warehouses, customers, static warranties, payment methods and chart of accounts do not have meaningful per-record dates for this view. Payment/journal/refund histories, reports, audit logs, and transaction timestamps remain tables and timelines, rather than being misrepresented as future events.
