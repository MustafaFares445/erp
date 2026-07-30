# Entity Relationship Diagram

## 1. Database Overview

The IERP database is a normalized relational schema for a Laravel API ERP. It supports product variants, multi-warehouse inventory, full chart of accounts, journal entries, sales documents, payments, tax recognition, employee plans, AI voice-note processing, tickets, maintenance, CRM, notifications, and audit logs.

## 2. Design Principles

- Use relational integrity with foreign keys.
- Use decimal values for money and quantities.
- Track stock by `product_variant_id + warehouse_id`.
- Create an inventory movement for every stock-changing operation.
- Do not physically delete confirmed financial documents.
- Recognize tax only on collected payments.
- Use audit logs for sensitive operations.

## 3. Entity Groups

| Group | Tables |
|---|---|
| Identity | users, user_devices |
| Parties | customer_profiles, employee_profiles, suppliers |
| Products | product_categories, products, product_variants, variant_attributes, variant_attribute_values, product_files |
| Inventory | warehouses, warehouse_locations, inventory_stocks, inventory_movements, adjustments, transfers, reservations |
| Accounting | account_types, chart_accounts, fiscal_periods, journal_entries, journal_entry_lines |
| Sales | quotations, orders, supplier_confirmations, delivery_notes, invoices, credit_notes |
| Payments | payment_methods, payments, manual_payment_records, stripe_payment_records, tax_recognition_entries |
| Employee Operations | sales_plans, plan_tasks, visits, GPS logs, voice notes, transcriptions, performance, salary |
| Support | tickets, maintenance_records, maintenance_tasks |
| CRM | customer_profiles, product_subscriptions, product_subscription_products, customer_product_subscriptions, crm_leads, crm_interactions, marketing_campaigns, recipients, responses |
| System | notifications, email_logs, push logs, audit_logs, export_logs |

## 4. Full Entity List

`users`, `user_devices`, `customer_profiles`, `employee_profiles`, `suppliers`, `product_categories`, `products`, `variant_attributes`, `variant_attribute_values`, `product_variants`, `product_variant_values`, `product_files`, `warehouses`, `warehouse_locations`, `inventory_stocks`, `inventory_movements`, `inventory_adjustments`, `inventory_adjustment_items`, `stock_transfers`, `stock_transfer_items`, `stock_reservations`, `account_types`, `chart_accounts`, `fiscal_periods`, `journal_entries`, `journal_entry_lines`, `payment_terms`, `quotations`, `quotation_items`, `orders`, `order_items`, `supplier_confirmations`, `delivery_notes`, `delivery_note_items`, `invoices`, `invoice_items`, `invoice_files`, `invoice_confirmations`, `credit_notes`, `credit_note_items`, `payment_methods`, `payments`, `payment_allocations`, `manual_payment_records`, `stripe_payment_records`, `tax_recognition_entries`, `sales_plans`, `plan_tasks`, `task_status_logs`, `customer_visits`, `visit_gps_logs`, `visit_attachments`, `employee_voice_notes`, `voice_note_transcriptions`, `ai_keyword_rules`, `sales_opportunity_drafts`, `employee_performance_scores`, `employee_salary_calculations`, `bonus_suggestions`, `tickets`, `ticket_messages`, `ticket_attachments`, `ticket_assignments`, `ticket_payment_links`, `maintenance_records`, `maintenance_tasks`, `crm_leads`, `crm_interactions`, `marketing_campaigns`, `campaign_recipients`, `campaign_responses`, `notifications`, `notification_templates`, `email_logs`, `push_notification_logs`, `audit_logs`, `export_logs`

## 5. Relationships

- Users may have customer or employee profiles.
- Products have variants and variants have attribute values.
- Warehouses hold stock balances per product variant.
- Inventory movements reference source documents using `source_type` and `source_id`.
- Quotations convert to delivery notes; delivery notes convert to invoices; invoices receive payments.
- Payments generate tax recognition entries and journal entries.
- Credit notes correct invoices without destructive deletion.
- Employee plans contain tasks; tasks may produce visits; visits may produce voice notes; AI may produce sales opportunity drafts.
- Tickets may create maintenance records.
- CRM campaigns target customers or leads.
- Product subscriptions link products and active customer profiles; their price candidates are resolved without stacking.

### Table: `product_subscriptions`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key and deterministic price tie-breaker |
| `name` | varchar(150) | No |  | Unique discount agreement name |
| `discount_type` | varchar(20) | No |  | `percentage` or `fixed` |
| `discount_value` | decimal(15,2) | No |  | Positive discount value |
| `visibility` | varchar(20) | No |  | `public` or `restricted` dashboard classification |
| `is_active` | boolean | No | false | Lifecycle switch |
| `valid_from` / `valid_until` | date | Yes | null | Inclusive validity window |
| `created_by` / `updated_by` | bigint unsigned | No / Yes |  / null | Dashboard actors |
| `deleted_at` | timestamp | Yes | null | Soft deletion |

`name` is unique. Index `(is_active, valid_from, valid_until, deleted_at)` and
`(visibility, is_active, deleted_at)` support eligibility and dashboard
filtering.

### Table: `product_subscription_products`

| Column | Type | Nullable | Description |
|---|---|---|---|
| `product_subscription_id` | bigint unsigned | No | Subscription foreign key |
| `product_id` | bigint unsigned | No | Product foreign key |
| `created_at` / `updated_at` | timestamp | No | Link timestamps |

The composite `(product_subscription_id, product_id)` is unique; the reverse
`(product_id, product_subscription_id)` index supports eligibility lookup.

### Table: `customer_product_subscriptions`

| Column | Type | Nullable | Description |
|---|---|---|---|
| `product_subscription_id` | bigint unsigned | No | Subscription foreign key |
| `customer_profile_id` | bigint unsigned | No | Customer profile foreign key |
| `created_at` / `updated_at` | timestamp | No | Assignment timestamps |

The composite `(product_subscription_id, customer_profile_id)` is unique; the
reverse `(customer_profile_id, product_subscription_id)` index supports
customer eligibility lookup. `price_floor_overrides.product_subscription_id` is
nullable provenance for a below-floor subscription candidate.

## 6. Table Definitions

### Table: `users`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(255) | No |  | Full name |
| `email` | varchar(255) | No |  | Unique login email |
| `phone` | varchar(50) | Yes | null | Phone number |
| `password` | varchar(255) | No |  | Hashed password |
| `user_type` | enum(admin,customer,employee) | No |  | Primary user type |
| `is_active` | boolean | No | true | Can user login |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `user_devices`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `user_id` | bigint unsigned | No |  | Owner user |
| `device_token` | varchar(500) | No |  | Push token |
| `platform` | varchar(50) | No |  | ios/android/web |
| `last_seen_at` | timestamp | Yes | null | Last activity |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `customer_profiles`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `user_id` | bigint unsigned | No |  | Linked user |
| `customer_code` | varchar(50) | No |  | Unique customer code |
| `company_name` | varchar(255) | Yes | null | Customer company |
| `address` | text | Yes | null | Primary address |
| `default_payment_term_id` | bigint unsigned | Yes | null | Default payment term |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `employee_profiles`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `user_id` | bigint unsigned | No |  | Linked user |
| `employee_code` | varchar(50) | No |  | Unique employee code |
| `job_title` | varchar(255) | Yes | null | Employee role |
| `use_base_salary` | boolean | No | false | Whether base salary applies |
| `base_salary` | decimal(15,2) | Yes | null | Optional base salary |
| `salary_calculation_mode` | varchar(80) | No | performance_only | Salary rule mode |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `suppliers`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(255) | No |  | Supplier name |
| `code` | varchar(50) | No |  | Supplier code |
| `email` | varchar(255) | Yes | null | Supplier email |
| `phone` | varchar(50) | Yes | null | Supplier phone |
| `address` | text | Yes | null | Supplier address |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `product_categories`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(255) | No |  | Category name |
| `parent_id` | bigint unsigned | Yes | null | Parent category |
| `is_active` | boolean | No | true | Visibility flag |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `products`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `category_id` | bigint unsigned | Yes | null | Product category |
| `sku` | varchar(100) | No |  | Base SKU |
| `name` | varchar(255) | No |  | Product name |
| `description` | text | Yes | null | Description |
| `is_active` | boolean | No | true | Can be sold |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `variant_attributes`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(100) | No |  | Attribute name such as color |
| `type` | varchar(50) | No | text | Value type |
| `is_active` | boolean | No | true | Can be used |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `variant_attribute_values`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `variant_attribute_id` | bigint unsigned | No |  | Attribute |
| `value` | varchar(255) | No |  | Attribute value |
| `sort_order` | int | No | 0 | Display order |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `product_variants`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `product_id` | bigint unsigned | No |  | Parent product |
| `sku` | varchar(100) | No |  | Variant SKU |
| `name` | varchar(255) | No |  | Variant display name |
| `barcode` | varchar(100) | Yes | null | Barcode |
| `unit_price` | decimal(15,2) | No | 0 | Default sale price |
| `cost_price` | decimal(15,2) | Yes | null | Cost price |
| `base_price` | decimal(15,2) | Yes | null | Computed base price used as the basis for customer tiers and discounts |
| `min_price` | decimal(15,2) | Yes | null | Minimum allowed selling price (price floor); sale below this is blocked unless a System Admin approves the override |
| `is_active` | boolean | No | true | Can be sold |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `product_variant_values`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `variant_attribute_id` | bigint unsigned | No |  | Attribute |
| `variant_attribute_value_id` | bigint unsigned | No |  | Value |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `product_files`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `product_id` | bigint unsigned | Yes | null | Product |
| `product_variant_id` | bigint unsigned | Yes | null | Variant |
| `file_path` | varchar(500) | No |  | Stored file path |
| `file_type` | varchar(50) | No |  | image/document |
| `sort_order` | int | No | 0 | Display order |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `pricing_tiers`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(100) | No |  | Tier name (e.g., standard, laboratory, distributor) |
| `discount_percent` | decimal(5,2) | No | 0 | Discount percentage applied to the base price |
| `customer_id` | bigint unsigned | Yes | null | If set, a customer-specific tier that overrides the general tier |
| `is_active` | boolean | No | true | Whether the tier is usable |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Customer-specific tiers take priority over general tiers; when no tier applies, the product base price is used.
- Use transactions for changes that touch financial or inventory records.

### Table: `customer_pricing_tiers`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `customer_id` | bigint unsigned | No |  | Customer profile |
| `pricing_tier_id` | bigint unsigned | No |  | Assigned general pricing tier |
| `is_active` | boolean | No | true | Whether the assignment is active |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `price_floor_overrides`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `product_variant_id` | bigint unsigned | No |  | Variant sold below its minimum price |
| `customer_id` | bigint unsigned | Yes | null | Customer the sale applied to |
| `attempted_price` | decimal(15,2) | No |  | Price that fell below the floor |
| `min_price` | decimal(15,2) | No |  | Minimum price at the time of the sale |
| `approved_by` | bigint unsigned | No |  | System Admin who approved the below-floor sale |
| `approved_at` | timestamp | No | current timestamp | When the override was approved |
| `reason` | text | Yes | null | Optional justification |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- A record is written only when a sale below the minimum price is explicitly approved; without approval the sale is blocked.
- Use transactions for changes that touch financial or inventory records.

### Table: `warehouses`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(255) | No |  | Warehouse name |
| `code` | varchar(50) | No |  | Warehouse code |
| `address` | text | Yes | null | Address |
| `is_active` | boolean | No | true | Can be used |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `warehouse_locations`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `warehouse_id` | bigint unsigned | No |  | Warehouse |
| `name` | varchar(255) | No |  | Location/bin name |
| `code` | varchar(50) | Yes | null | Internal code |
| `is_active` | boolean | No | true | Can be used |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `inventory_stocks`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `warehouse_id` | bigint unsigned | No |  | Warehouse |
| `on_hand_quantity` | decimal(15,3) | No | 0 | Physical quantity |
| `reserved_quantity` | decimal(15,3) | No | 0 | Reserved quantity |
| `available_quantity` | decimal(15,3) | No | 0 | Computed available quantity |
| `reorder_level` | decimal(15,3) | Yes | null | Low stock threshold |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `inventory_movements`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `warehouse_id` | bigint unsigned | No |  | Warehouse |
| `movement_type` | varchar(50) | No |  | sale/return/adjustment/transfer/reservation |
| `quantity` | decimal(15,3) | No |  | Positive or negative movement |
| `source_type` | varchar(100) | Yes | null | Source document type |
| `source_id` | bigint unsigned | Yes | null | Source document id |
| `notes` | text | Yes | null | Movement note |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `inventory_adjustments`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `warehouse_id` | bigint unsigned | No |  | Warehouse |
| `adjustment_number` | varchar(100) | No |  | Adjustment number |
| `reason` | text | No |  | Reason |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `inventory_adjustment_items`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `inventory_adjustment_id` | bigint unsigned | No |  | Adjustment |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `old_quantity` | decimal(15,3) | No |  | Quantity before |
| `new_quantity` | decimal(15,3) | No |  | Quantity after |
| `difference` | decimal(15,3) | No |  | Difference |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `stock_transfers`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `from_warehouse_id` | bigint unsigned | No |  | Source warehouse |
| `to_warehouse_id` | bigint unsigned | No |  | Destination warehouse |
| `transfer_number` | varchar(100) | No |  | Transfer number |
| `notes` | text | Yes | null | Notes |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `stock_transfer_items`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `stock_transfer_id` | bigint unsigned | No |  | Transfer |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `quantity` | decimal(15,3) | No |  | Quantity |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `stock_reservations`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `warehouse_id` | bigint unsigned | No |  | Warehouse |
| `quantity` | decimal(15,3) | No |  | Reserved quantity |
| `source_type` | varchar(100) | No |  | Quotation/order/delivery |
| `source_id` | bigint unsigned | No |  | Source id |
| `expires_at` | timestamp | Yes | null | Reservation expiry |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `account_types`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(100) | No |  | Asset/liability/equity/income/expense |
| `normal_balance` | enum(debit,credit) | No |  | Normal balance |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `chart_accounts`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `account_type_id` | bigint unsigned | No |  | Account type |
| `parent_id` | bigint unsigned | Yes | null | Parent account |
| `code` | varchar(50) | No |  | Account code |
| `name` | varchar(255) | No |  | Account name |
| `is_postable` | boolean | No | true | Allows journal lines |
| `is_active` | boolean | No | true | Can be used |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `fiscal_periods`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(100) | No |  | Fiscal period name |
| `starts_at` | date | No |  | Start date |
| `ends_at` | date | No |  | End date |
| `is_closed` | boolean | No | false | Closed flag |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `journal_entries`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `fiscal_period_id` | bigint unsigned | Yes | null | Fiscal period |
| `entry_number` | varchar(100) | No |  | Journal entry number |
| `entry_date` | date | No |  | Accounting date |
| `description` | text | Yes | null | Description |
| `source_type` | varchar(100) | Yes | null | Source document type |
| `source_id` | bigint unsigned | Yes | null | Source document id |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `journal_entry_lines`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `journal_entry_id` | bigint unsigned | No |  | Journal entry |
| `chart_account_id` | bigint unsigned | No |  | Account |
| `debit` | decimal(15,2) | No | 0 | Debit amount |
| `credit` | decimal(15,2) | No | 0 | Credit amount |
| `description` | text | Yes | null | Line description |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `payment_terms`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(100) | No |  | Payment term name |
| `due_days` | int | No | 0 | Number of days until due |
| `grace_days` | int | No | 0 | Grace period |
| `discount_percent` | decimal(5,2) | Yes | null | Optional discount |
| `is_default` | boolean | No | false | Default term |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `quotations`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `quotation_number` | varchar(100) | No |  | Quotation number |
| `customer_id` | bigint unsigned | No |  | Customer profile |
| `employee_id` | bigint unsigned | Yes | null | Employee creator |
| `payment_term_id` | bigint unsigned | Yes | null | Payment term |
| `issue_date` | date | No |  | Issue date |
| `expires_at` | date | Yes | null | Expiry date |
| `subtotal` | decimal(15,2) | No | 0 | Subtotal |
| `tax_total` | decimal(15,2) | No | 0 | Tax total |
| `grand_total` | decimal(15,2) | No | 0 | Grand total |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `quotation_items`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `quotation_id` | bigint unsigned | No |  | Quotation |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `description` | text | Yes | null | Item description |
| `quantity` | decimal(15,3) | No |  | Quantity |
| `unit_price` | decimal(15,2) | No |  | Unit price |
| `tax_amount` | decimal(15,2) | No | 0 | Tax amount |
| `line_total` | decimal(15,2) | No |  | Line total |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `orders`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `order_number` | varchar(100) | No |  | Order number |
| `customer_id` | bigint unsigned | No |  | Customer |
| `supplier_id` | bigint unsigned | Yes | null | Related supplier |
| `quotation_id` | bigint unsigned | Yes | null | Source quotation |
| `payment_status` | varchar(50) | No | pending | Payment state |
| `pending_reason` | varchar(100) | Yes | null | Why order is pending |
| `grand_total` | decimal(15,2) | No | 0 | Total |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `order_items`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `order_id` | bigint unsigned | No |  | Order |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `quantity` | decimal(15,3) | No |  | Quantity |
| `unit_price` | decimal(15,2) | No |  | Unit price |
| `line_total` | decimal(15,2) | No |  | Line total |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `supplier_confirmations`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `order_id` | bigint unsigned | No |  | Order |
| `supplier_id` | bigint unsigned | No |  | Supplier |
| `confirmed_by` | bigint unsigned | Yes | null | Admin who updated |
| `confirmed_at` | timestamp | Yes | null | Confirmation timestamp |
| `confirmation_status` | varchar(50) | No | pending | pending/confirmed/rejected |
| `notes` | text | Yes | null | Supplier discussion notes |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `delivery_notes`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `delivery_note_number` | varchar(100) | No |  | Delivery note number |
| `quotation_id` | bigint unsigned | Yes | null | Source quotation |
| `order_id` | bigint unsigned | Yes | null | Source order |
| `customer_id` | bigint unsigned | No |  | Customer |
| `warehouse_id` | bigint unsigned | No |  | Source warehouse |
| `delivered_at` | timestamp | Yes | null | Delivery time |
| `notes` | text | Yes | null | Notes |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `delivery_note_items`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `delivery_note_id` | bigint unsigned | No |  | Delivery note |
| `product_variant_id` | bigint unsigned | No |  | Variant |
| `quantity` | decimal(15,3) | No |  | Delivered quantity |
| `unit_price` | decimal(15,2) | Yes | null | Reference price |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `invoices`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `invoice_number` | varchar(100) | No |  | Invoice number |
| `customer_id` | bigint unsigned | No |  | Customer |
| `delivery_note_id` | bigint unsigned | Yes | null | Source delivery note |
| `payment_term_id` | bigint unsigned | Yes | null | Payment term |
| `invoice_date` | date | No |  | Invoice date |
| `due_date` | date | No |  | Due date |
| `subtotal` | decimal(15,2) | No | 0 | Subtotal |
| `tax_total` | decimal(15,2) | No | 0 | Total tax |
| `paid_amount` | decimal(15,2) | No | 0 | Collected amount |
| `grand_total` | decimal(15,2) | No | 0 | Grand total |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `invoice_items`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `invoice_id` | bigint unsigned | No |  | Invoice |
| `product_variant_id` | bigint unsigned | Yes | null | Variant |
| `description` | text | No |  | Line description |
| `quantity` | decimal(15,3) | No |  | Quantity |
| `unit_price` | decimal(15,2) | No |  | Unit price |
| `tax_amount` | decimal(15,2) | No | 0 | Tax amount |
| `line_total` | decimal(15,2) | No |  | Line total |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `invoice_files`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `invoice_id` | bigint unsigned | No |  | Invoice |
| `file_path` | varchar(500) | No |  | PDF path |
| `file_type` | varchar(50) | No | pdf | File type |
| `generated_at` | timestamp | No |  | Generated time |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `invoice_confirmations`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `invoice_id` | bigint unsigned | No |  | Invoice |
| `confirmed_by_user_id` | bigint unsigned | No |  | Customer or employee |
| `confirmation_type` | varchar(50) | No |  | customer_received/employee_confirmed |
| `signature_path` | varchar(500) | Yes | null | Signature file |
| `confirmed_at` | timestamp | No |  | Confirmation time |
| `notes` | text | Yes | null | Notes |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `credit_notes`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `credit_note_number` | varchar(100) | No |  | Credit note number |
| `invoice_id` | bigint unsigned | Yes | null | Related invoice |
| `customer_id` | bigint unsigned | No |  | Customer |
| `reason` | text | No |  | Reason |
| `issue_date` | date | No |  | Issue date |
| `subtotal` | decimal(15,2) | No | 0 | Subtotal |
| `tax_total` | decimal(15,2) | No | 0 | Tax total |
| `grand_total` | decimal(15,2) | No | 0 | Total |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `credit_note_items`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `credit_note_id` | bigint unsigned | No |  | Credit note |
| `invoice_item_id` | bigint unsigned | Yes | null | Related invoice item |
| `description` | text | No |  | Description |
| `quantity` | decimal(15,3) | No |  | Quantity |
| `unit_price` | decimal(15,2) | No |  | Unit price |
| `tax_amount` | decimal(15,2) | No | 0 | Tax amount |
| `line_total` | decimal(15,2) | No |  | Line total |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `payment_methods`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(100) | No |  | Payment method |
| `type` | varchar(50) | No |  | cash/bank_transfer/cheque/custom/stripe |
| `is_online` | boolean | No | false | Online method |
| `is_active` | boolean | No | true | Can be used |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `payments`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `payment_number` | varchar(100) | No |  | Payment number |
| `customer_id` | bigint unsigned | No |  | Customer |
| `invoice_id` | bigint unsigned | Yes | null | Invoice |
| `payment_method_id` | bigint unsigned | Yes | null | Manual method or stripe |
| `amount` | decimal(15,2) | No |  | Payment amount |
| `currency` | varchar(3) | No |  | Currency |
| `payment_date` | timestamp | No |  | Payment date |
| `source` | varchar(50) | No |  | stripe/manual |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `payment_allocations`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `payment_id` | bigint unsigned | No |  | Payment |
| `invoice_id` | bigint unsigned | No |  | Invoice |
| `amount` | decimal(15,2) | No |  | Allocated amount |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `manual_payment_records`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `payment_id` | bigint unsigned | No |  | Payment |
| `reference_number` | varchar(255) | Yes | null | Transfer/cheque reference |
| `proof_file_path` | varchar(500) | Yes | null | Payment proof |
| `admin_note` | text | Yes | null | Admin note |
| `recorded_by` | bigint unsigned | No |  | Admin recorder |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `stripe_payment_records`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `payment_id` | bigint unsigned | Yes | null | Local payment |
| `stripe_payment_intent_id` | varchar(255) | No |  | Stripe payment intent |
| `stripe_charge_id` | varchar(255) | Yes | null | Stripe charge |
| `amount` | decimal(15,2) | No |  | Amount |
| `currency` | varchar(3) | No |  | Currency |
| `raw_payload` | json | Yes | null | Webhook payload |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `tax_recognition_entries`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `invoice_id` | bigint unsigned | No |  | Invoice |
| `payment_id` | bigint unsigned | No |  | Payment |
| `journal_entry_id` | bigint unsigned | Yes | null | Accounting entry |
| `payment_amount` | decimal(15,2) | No |  | Payment amount |
| `recognized_tax_amount` | decimal(15,2) | No |  | Recognized tax |
| `recognition_date` | date | No |  | Recognition date |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `sales_plans`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `employee_id` | bigint unsigned | No |  | Assigned employee |
| `name` | varchar(255) | No |  | Plan name |
| `month` | date | No |  | Plan month |
| `task_weight` | decimal(5,2) | No | 40 | Task completion weight |
| `visit_weight` | decimal(5,2) | No | 40 | Visit completion weight |
| `schedule_weight` | decimal(5,2) | No | 10 | Schedule weight |
| `work_time_weight` | decimal(5,2) | No | 10 | Work time weight |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `plan_tasks`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `sales_plan_id` | bigint unsigned | No |  | Monthly plan |
| `customer_id` | bigint unsigned | Yes | null | Related customer |
| `title` | varchar(255) | No |  | Task title |
| `description` | text | Yes | null | Task details |
| `starts_at` | timestamp | Yes | null | Scheduled start |
| `due_at` | timestamp | Yes | null | Due date |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `task_status_logs`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `plan_task_id` | bigint unsigned | No |  | Task |
| `old_status` | varchar(50) | Yes | null | Old status |
| `new_status` | varchar(50) | No |  | New status |
| `changed_by` | bigint unsigned | No |  | User |
| `changed_at` | timestamp | No |  | Time |
| `notes` | text | Yes | null | Notes |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `customer_visits`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `employee_id` | bigint unsigned | No |  | Employee |
| `customer_id` | bigint unsigned | No |  | Customer |
| `plan_task_id` | bigint unsigned | Yes | null | Related task |
| `scheduled_at` | timestamp | Yes | null | Scheduled time |
| `checked_in_at` | timestamp | Yes | null | Check in |
| `checked_out_at` | timestamp | Yes | null | Check out |
| `result_notes` | text | Yes | null | Visit notes |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `visit_gps_logs`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `customer_visit_id` | bigint unsigned | No |  | Visit |
| `lat` | decimal(10,7) | No |  | Latitude |
| `lng` | decimal(10,7) | No |  | Longitude |
| `accuracy` | decimal(10,2) | Yes | null | GPS accuracy |
| `recorded_at` | timestamp | No |  | Record time |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `visit_attachments`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `customer_visit_id` | bigint unsigned | No |  | Visit |
| `file_path` | varchar(500) | No |  | Stored file |
| `file_type` | varchar(50) | No |  | image/audio/document |
| `notes` | text | Yes | null | Notes |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `employee_voice_notes`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `customer_visit_id` | bigint unsigned | No |  | Visit |
| `employee_id` | bigint unsigned | No |  | Employee |
| `audio_path` | varchar(500) | No |  | Private audio path |
| `duration_seconds` | int | Yes | null | Duration |
| `language` | varchar(20) | Yes | null | Audio language |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `voice_note_transcriptions`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `employee_voice_note_id` | bigint unsigned | No |  | Voice note |
| `provider` | varchar(100) | Yes | null | AI provider |
| `transcript_text` | longtext | Yes | null | Extracted text |
| `confidence` | decimal(5,2) | Yes | null | Confidence |
| `error_message` | text | Yes | null | Failure reason |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `ai_keyword_rules`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `keyword` | varchar(255) | No |  | Keyword or phrase |
| `product_id` | bigint unsigned | Yes | null | Related product |
| `product_variant_id` | bigint unsigned | Yes | null | Related variant |
| `is_active` | boolean | No | true | Active rule |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `sales_opportunity_drafts`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `employee_id` | bigint unsigned | No |  | Employee |
| `customer_id` | bigint unsigned | Yes | null | Customer |
| `customer_visit_id` | bigint unsigned | Yes | null | Visit |
| `voice_note_transcription_id` | bigint unsigned | Yes | null | Transcription |
| `matched_keyword` | varchar(255) | Yes | null | Matched keyword |
| `description` | text | Yes | null | Draft description |
| `bonus_suggested` | boolean | No | false | Bonus suggestion flag |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `employee_performance_scores`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `sales_plan_id` | bigint unsigned | No |  | Plan |
| `employee_id` | bigint unsigned | No |  | Employee |
| `task_score` | decimal(5,2) | No | 0 | Task score |
| `visit_score` | decimal(5,2) | No | 0 | Visit score |
| `schedule_score` | decimal(5,2) | No | 0 | Schedule score |
| `work_time_score` | decimal(5,2) | No | 0 | Work time score |
| `total_score` | decimal(5,2) | No | 0 | Total score |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `employee_salary_calculations`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `employee_id` | bigint unsigned | No |  | Employee |
| `sales_plan_id` | bigint unsigned | No |  | Plan |
| `use_base_salary` | boolean | No | false | Whether base salary used |
| `base_salary` | decimal(15,2) | Yes | null | Base salary |
| `performance_percent` | decimal(5,2) | No | 0 | Performance |
| `bonus_amount` | decimal(15,2) | No | 0 | Bonus |
| `final_salary` | decimal(15,2) | No | 0 | Calculated salary |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `bonus_suggestions`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `employee_id` | bigint unsigned | No |  | Employee |
| `sales_opportunity_draft_id` | bigint unsigned | Yes | null | Related draft |
| `suggested_amount` | decimal(15,2) | Yes | null | Suggested bonus |
| `reason` | text | No |  | Reason |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `tickets`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `ticket_number` | varchar(100) | No |  | Ticket number |
| `customer_id` | bigint unsigned | No |  | Customer |
| `assigned_employee_id` | bigint unsigned | Yes | null | Assigned employee |
| `type` | varchar(50) | No |  | software/hardware/general/maintenance |
| `title` | varchar(255) | No |  | Title |
| `description` | text | No |  | Description |
| `pending_reason` | varchar(100) | Yes | null | Reason pending |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `ticket_messages`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `ticket_id` | bigint unsigned | No |  | Ticket |
| `sender_user_id` | bigint unsigned | No |  | Sender |
| `message` | text | No |  | Message body |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `ticket_attachments`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `ticket_id` | bigint unsigned | No |  | Ticket |
| `file_path` | varchar(500) | No |  | File path |
| `file_type` | varchar(50) | No |  | File type |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `ticket_assignments`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `ticket_id` | bigint unsigned | No |  | Ticket |
| `employee_id` | bigint unsigned | No |  | Assigned employee |
| `assigned_by` | bigint unsigned | No |  | Admin |
| `assigned_at` | timestamp | No |  | Assignment time |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `ticket_payment_links`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `ticket_id` | bigint unsigned | No |  | Ticket |
| `stripe_payment_record_id` | bigint unsigned | Yes | null | Stripe record |
| `amount` | decimal(15,2) | No |  | Amount |
| `currency` | varchar(3) | No |  | Currency |
| `payment_url` | varchar(1000) | Yes | null | Stripe payment URL |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `maintenance_records`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `customer_id` | bigint unsigned | No |  | Customer |
| `ticket_id` | bigint unsigned | Yes | null | Source ticket |
| `product_variant_id` | bigint unsigned | Yes | null | Product variant |
| `serial_number` | varchar(255) | Yes | null | Equipment serial |
| `description` | text | No |  | Issue description |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `maintenance_tasks`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `maintenance_record_id` | bigint unsigned | No |  | Maintenance record |
| `employee_id` | bigint unsigned | Yes | null | Assigned employee |
| `title` | varchar(255) | No |  | Task title |
| `description` | text | Yes | null | Details |
| `due_at` | timestamp | Yes | null | Due date |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `crm_leads`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(255) | No |  | Lead name |
| `company_name` | varchar(255) | Yes | null | Company |
| `email` | varchar(255) | Yes | null | Email |
| `phone` | varchar(50) | Yes | null | Phone |
| `source` | varchar(100) | Yes | null | Lead source |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `crm_interactions`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `lead_id` | bigint unsigned | Yes | null | Lead |
| `customer_id` | bigint unsigned | Yes | null | Customer |
| `employee_id` | bigint unsigned | Yes | null | Employee |
| `interaction_type` | varchar(100) | No |  | Call/email/visit/etc |
| `notes` | text | Yes | null | Notes |
| `interacted_at` | timestamp | No |  | Interaction time |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `marketing_campaigns`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `name` | varchar(255) | No |  | Campaign name |
| `channel` | varchar(100) | No |  | Email/SMS/etc |
| `content` | text | Yes | null | Campaign content |
| `scheduled_at` | timestamp | Yes | null | Schedule time |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `campaign_recipients`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `marketing_campaign_id` | bigint unsigned | No |  | Campaign |
| `customer_id` | bigint unsigned | Yes | null | Customer |
| `lead_id` | bigint unsigned | Yes | null | Lead |
| `sent_at` | timestamp | Yes | null | Sent time |
| `response_status` | varchar(100) | Yes | null | Response status |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `campaign_responses`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `campaign_recipient_id` | bigint unsigned | No |  | Recipient |
| `response_type` | varchar(100) | No |  | Clicked/replied/interested |
| `response_data` | json | Yes | null | Response metadata |
| `responded_at` | timestamp | No |  | Response time |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `notifications`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `user_id` | bigint unsigned | No |  | Recipient |
| `title` | varchar(255) | No |  | Title |
| `body` | text | No |  | Body |
| `type` | varchar(100) | No |  | Notification type |
| `read_at` | timestamp | Yes | null | Read timestamp |
| `data` | json | Yes | null | Payload |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `notification_templates`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `key` | varchar(100) | No |  | Template key |
| `title_template` | varchar(255) | No |  | Title template |
| `body_template` | text | No |  | Body template |
| `channel` | varchar(50) | No |  | database/email/push |
| `is_active` | boolean | No | true | Active |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `created_by` | bigint unsigned | Yes | null | User who created the record |
| `updated_by` | bigint unsigned | Yes | null | User who last updated the record |
| `deleted_at` | timestamp | Yes | null | Soft delete timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `email_logs`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `user_id` | bigint unsigned | Yes | null | Recipient user |
| `to_email` | varchar(255) | No |  | Email address |
| `subject` | varchar(255) | No |  | Email subject |
| `status` | varchar(50) | No | pending | Email status |
| `sent_at` | timestamp | Yes | null | Sent time |
| `error_message` | text | Yes | null | Error |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `push_notification_logs`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `user_id` | bigint unsigned | No |  | Recipient |
| `device_token` | varchar(500) | Yes | null | Device token |
| `title` | varchar(255) | No |  | Title |
| `status` | varchar(50) | No | pending | Push status |
| `sent_at` | timestamp | Yes | null | Sent time |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `audit_logs`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `actor_user_id` | bigint unsigned | Yes | null | Actor |
| `action` | varchar(100) | No |  | Action name |
| `entity_type` | varchar(150) | No |  | Entity type |
| `entity_id` | bigint unsigned | Yes | null | Entity id |
| `old_values` | json | Yes | null | Old values |
| `new_values` | json | Yes | null | New values |
| `source_channel` | varchar(50) | Yes | null | dashboard/customer/employee/system |
| `ip_address` | varchar(50) | Yes | null | IP address |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.

### Table: `export_logs`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto increment | Primary key |
| `user_id` | bigint unsigned | No |  | Requester |
| `export_type` | varchar(100) | No |  | invoices/reports/etc |
| `file_path` | varchar(500) | Yes | null | Export file |
| `filters` | json | Yes | null | Export filters |
| `created_at` | timestamp | No | current timestamp | Creation timestamp |
| `updated_at` | timestamp | No | current timestamp | Update timestamp |
| `status` | varchar(50) | No | draft/pending | Workflow status |

#### Indexes
- Primary key on `id`.
- Index all foreign key columns.
- Index `status`, document number, date fields, and searchable code/name fields where applicable.

#### Constraints
- Enforce foreign keys for parent records.
- Enforce uniqueness for business numbers/codes/SKUs where applicable.

#### Notes
- Use transactions for changes that touch financial or inventory records.


## 7. Indexes

Global index requirements:

- All foreign keys.
- All document numbers: quotation, delivery note, invoice, credit note, payment, ticket, adjustment, transfer.
- All `status` fields used in filters.
- Date fields used in reports.
- Searchable fields: customer code/name, employee code/name, supplier code/name, product SKU/name, variant SKU/barcode.
- Stripe IDs must be unique.
- `(product_variant_id, warehouse_id)` must be unique on `inventory_stocks`.

## 8. Constraints

- Financial totals must be non-negative unless explicitly representing reversal.
- Journal entry lines must balance: total debit equals total credit before confirmation.
- Payment allocation total must not exceed payment amount.
- Invoice paid amount must not exceed grand total after credit/reversal logic.
- Reserved stock must not make available quantity negative unless admin override is later approved.
- Delivery note confirmation must fail if stock is insufficient.
- Base salary can be null when `use_base_salary=false`.
- Customer price is resolved in order of precedence: active customer-specific tier, then the customer's general tier, then the product/variant base price.
- The final price after any tier discount must not fall below the variant `min_price`. If it does, the sale is blocked and can proceed only with explicit System Admin approval, recorded in `price_floor_overrides`.

## 9. Data Integrity Rules

- Use transactions for quotation conversion, delivery confirmation, invoice issuance, payment posting, tax recognition, stock transfer, and credit note confirmation.
- Confirmed financial records are immutable except through approved correction workflows.
- All payment webhooks must be idempotent.
- AI job failure must not roll back the visit record.
- Supplier confirmation is manually changed by an admin only.

## 10. Status and Enum Catalog

- `quotations`: draft, sent, accepted, rejected, expired, converted_to_delivery, cancelled
- `delivery_notes`: draft, confirmed, delivered, customer_confirmed_received, employee_confirmed_delivered, converted_to_invoice, cancelled
- `invoices`: draft, issued, sent, customer_received, employee_confirmed_received, partially_paid, paid, overdue, cancelled, credited
- `credit_notes`: draft, confirmed, cancelled
- `payments`: pending, processing, succeeded, failed, cancelled, refunded, partially_refunded
- `orders`: pending, pending_supplier_confirmation, supplier_confirmed, supplier_rejected, approved, rejected, processing, delivering, completed, cancelled
- `tasks`: not_started, in_progress, done, cancelled
- `visits`: scheduled, checked_in, checked_out, completed, cancelled
- `tickets`: pending, pending_payment, live, assigned, in_progress, waiting_customer, resolved, closed, cancelled
- `maintenance`: open, in_progress, closed, cancelled
- `sales_drafts`: detected, reviewed, converted, dismissed

## 11. Migration Order

1. `users`
2. `user_devices`
3. `customer_profiles`
4. `employee_profiles`
5. `suppliers`
6. `product_categories`
7. `products`
8. `variant_attributes`
9. `variant_attribute_values`
10. `product_variants`
11. `product_variant_values`
12. `product_files`
13. `pricing_tiers`
14. `customer_pricing_tiers`
15. `price_floor_overrides`
16. `warehouses`
14. `warehouse_locations`
15. `inventory_stocks`
16. `inventory_movements`
17. `inventory_adjustments`
18. `inventory_adjustment_items`
19. `stock_transfers`
20. `stock_transfer_items`
21. `stock_reservations`
22. `account_types`
23. `chart_accounts`
24. `fiscal_periods`
25. `journal_entries`
26. `journal_entry_lines`
27. `payment_terms`
28. `quotations`
29. `quotation_items`
30. `orders`
31. `order_items`
32. `supplier_confirmations`
33. `delivery_notes`
34. `delivery_note_items`
35. `invoices`
36. `invoice_items`
37. `invoice_files`
38. `invoice_confirmations`
39. `credit_notes`
40. `credit_note_items`
41. `payment_methods`
42. `payments`
43. `payment_allocations`
44. `manual_payment_records`
45. `stripe_payment_records`
46. `tax_recognition_entries`
47. `sales_plans`
48. `plan_tasks`
49. `task_status_logs`
50. `customer_visits`
51. `visit_gps_logs`
52. `visit_attachments`
53. `employee_voice_notes`
54. `voice_note_transcriptions`
55. `ai_keyword_rules`
56. `sales_opportunity_drafts`
57. `employee_performance_scores`
58. `employee_salary_calculations`
59. `bonus_suggestions`
60. `tickets`
61. `ticket_messages`
62. `ticket_attachments`
63. `ticket_assignments`
64. `ticket_payment_links`
65. `maintenance_records`
66. `maintenance_tasks`
67. `crm_leads`
68. `crm_interactions`
69. `marketing_campaigns`
70. `campaign_recipients`
71. `campaign_responses`
72. `notifications`
73. `notification_templates`
74. `email_logs`
75. `push_notification_logs`
76. `audit_logs`
77. `export_logs`

## 12. Seed Data Plan

- User types: admin, customer, employee.
- Account types: asset, liability, equity, income, expense.
- Starter chart of accounts template, to be confirmed by accounting owner.
- Default payment term examples: due on receipt, net 15, net 30.
- Default payment methods: cash, bank transfer, cheque, Stripe.
- Default employee performance weights: 40/40/10/10.
- Default ticket types: software_issue, hardware_issue, general_support, maintenance_request.
- Default statuses for all status catalogs.

## 13. Mermaid ERD


### Identity ERD

```mermaid
erDiagram
    users ||--o{ user_devices : has
    users ||--o| customer_profiles : may_have
    users ||--o| employee_profiles : may_have
```

### Products and Inventory ERD

```mermaid
erDiagram
    product_categories ||--o{ products : contains
    products ||--o{ product_variants : has
    variant_attributes ||--o{ variant_attribute_values : has
    product_variants ||--o{ product_variant_values : has
    warehouses ||--o{ warehouse_locations : has
    product_variants ||--o{ inventory_stocks : stocked_as
    warehouses ||--o{ inventory_stocks : stores
    product_variants ||--o{ inventory_movements : moves
    warehouses ||--o{ inventory_movements : records
    stock_transfers ||--o{ stock_transfer_items : includes
```

### Accounting ERD

```mermaid
erDiagram
    account_types ||--o{ chart_accounts : classifies
    chart_accounts ||--o{ chart_accounts : parent
    fiscal_periods ||--o{ journal_entries : contains
    journal_entries ||--o{ journal_entry_lines : has
    chart_accounts ||--o{ journal_entry_lines : posted_to
```

### Sales and Payments ERD

```mermaid
erDiagram
    customer_profiles ||--o{ quotations : requests
    quotations ||--o{ quotation_items : includes
    quotations ||--o{ delivery_notes : converts_to
    delivery_notes ||--o{ delivery_note_items : includes
    delivery_notes ||--o{ invoices : converts_to
    invoices ||--o{ invoice_items : includes
    invoices ||--o{ payments : receives
    payments ||--o{ payment_allocations : allocates
    payments ||--o{ tax_recognition_entries : recognizes_tax
    invoices ||--o{ credit_notes : corrected_by
    credit_notes ||--o{ credit_note_items : includes
```

### Employee Plans and AI ERD

```mermaid
erDiagram
    employee_profiles ||--o{ sales_plans : owns
    sales_plans ||--o{ plan_tasks : includes
    plan_tasks ||--o{ customer_visits : may_create
    customer_visits ||--o{ visit_gps_logs : logs
    customer_visits ||--o{ employee_voice_notes : has
    employee_voice_notes ||--o{ voice_note_transcriptions : transcribed_as
    voice_note_transcriptions ||--o{ sales_opportunity_drafts : detects
    sales_plans ||--o{ employee_performance_scores : scored_by
    sales_plans ||--o{ employee_salary_calculations : calculates
```

### Tickets, CRM, and Notifications ERD

```mermaid
erDiagram
    customer_profiles ||--o{ tickets : creates
    tickets ||--o{ ticket_messages : has
    tickets ||--o{ ticket_attachments : has
    tickets ||--o{ maintenance_records : may_create
    maintenance_records ||--o{ maintenance_tasks : has
    crm_leads ||--o{ crm_interactions : has
    marketing_campaigns ||--o{ campaign_recipients : sends_to
    campaign_recipients ||--o{ campaign_responses : receives
    users ||--o{ notifications : receives
    users ||--o{ audit_logs : performs
```


## 14. Open Questions

- Confirm database engine.
- Confirm currency list and tax rates.
- Confirm account code structure.
- Confirm whether manual payment posting requires approval.
- Confirm whether warehouse locations are required in first implementation or can stay optional.

## 15. Future Spec Kit Extraction Map

| Future Spec | Scope |
|---|---|
| 001-project-foundation | Project rules, actors, glossary, non-functional requirements |
| 002-database-foundation | Base schema, migrations, seed data, data integrity |
| 003-auth-users-access | Authentication and user type access |
| 004-products-variants-warehouses-inventory | Catalog, variants, warehouses, stock movements |
| 005-chart-of-accounts-and-journals | COA, fiscal periods, journals, posting rules |
| 006-sales-flow-quotation-delivery-invoice | Quotation, delivery note, invoice lifecycle |
| 007-payments-stripe-manual-tax-recognition | Stripe, manual payments, tax recognition |
| 008-credit-notes | Credit note workflows and accounting reversal |
| 009-customer-app-flows | Product browsing, quotations, orders, invoices, tickets |
| 010-employee-app-plans-visits-ai | Plans, visits, GPS, voice notes, AI sales drafts |
| 011-tickets-maintenance | Support tickets and maintenance records |
| 012-crm-marketing | Leads, interactions, campaigns, responses |
| 013-reporting-notifications | Reports, alerts, reminders, audit visibility |
