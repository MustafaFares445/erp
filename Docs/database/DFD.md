# Data Flow Diagrams

## 1. DFD Overview

The diagrams describe how System Admin, Customer, Employee, Stripe, AI provider, and mail service exchange data with the Laravel API and database.

## 2. Context Diagram

```mermaid
flowchart TD
    Admin[System Admin] --> Dashboard[Dashboard UI]
    Customer[Customer] --> CustomerApp[Customer App]
    Employee[Employee] --> EmployeeApp[Employee App]
    Dashboard --> API[Laravel API]
    CustomerApp --> API
    EmployeeApp --> API
    API --> DB[(IERP Database)]
    API --> Storage[(Private Storage)]
    API --> Queue[Queue Workers]
    API --> Stripe[Stripe]
    API --> Mail[Mail Provider]
    Queue --> AI[AI Transcription Provider]
```

## 3. Level 0 DFD

```mermaid
flowchart TD
    UI[Dashboard / Customer App / Employee App] --> Auth[Authentication]
    Auth --> Modules[IERP Domain Modules]
    Modules --> Sales[Sales Documents]
    Modules --> Inventory[Inventory]
    Modules --> Accounting[Accounting]
    Modules --> Support[Tickets & Maintenance]
    Modules --> EmployeeOps[Plans Visits AI]
    Sales --> DB[(Database)]
    Inventory --> DB
    Accounting --> DB
    Support --> DB
    EmployeeOps --> DB
    Accounting --> Jobs[Jobs]
    Jobs --> External[Stripe / Mail / AI]
```

## 4. Level 1 DFD Per Main Feature

### Product and Variant Management

```mermaid
flowchart TD
    Admin --> ProductForm[Product/Variant Form]
    ProductForm --> API[Product API]
    API --> ProductService[Product Service]
    ProductService --> DB[(Products, Variants, Attributes)]
    ProductService --> Audit[(Audit Logs)]
```

### Multi-Warehouse Inventory Movement

```mermaid
flowchart TD
    Admin --> InventoryAction[Adjustment Transfer Delivery]
    InventoryAction --> API[Inventory API]
    API --> StockService[Inventory Movement Service]
    StockService --> Stock[(inventory_stocks)]
    StockService --> Movements[(inventory_movements)]
    StockService --> Audit[(Audit Logs)]
```

### Quotation to Payment

```mermaid
flowchart TD
    AdminEmployee[Admin/Employee] --> Quote[Create Quotation]
    Quote --> Customer[Customer Accepts]
    Customer --> Delivery[Delivery Note]
    Delivery --> Stock[Decrease Stock]
    Delivery --> Invoice[Create Invoice]
    Invoice --> Payment[Stripe or Manual Payment]
    Payment --> Tax[Tax Recognition]
    Tax --> Journal[Journal Entry]
```

### Stripe Payment and Tax Recognition

```mermaid
flowchart TD
    Customer --> StripeCheckout[Stripe Checkout/Payment]
    StripeCheckout --> StripeWebhook[Stripe Webhook]
    StripeWebhook --> PaymentService[Payment Service]
    PaymentService --> Payment[(payments)]
    PaymentService --> Tax[(tax_recognition_entries)]
    PaymentService --> Journal[(journal_entries)]
    PaymentService --> Notify[Notification]
```

### Manual Payment and Tax Recognition

```mermaid
flowchart TD
    Admin --> ManualPaymentForm[Manual Payment Form]
    ManualPaymentForm --> PaymentAPI[Payment API]
    PaymentAPI --> PaymentService[Payment Service]
    PaymentService --> Payment[(payments)]
    PaymentService --> Proof[(payment proof file)]
    PaymentService --> Tax[(tax_recognition_entries)]
    PaymentService --> Journal[(journal_entries)]
```

### Employee Visit, GPS, and Voice Note

```mermaid
flowchart TD
    Employee --> VisitApp[Visit UI]
    VisitApp --> VisitAPI[Visit API]
    VisitAPI --> Visits[(customer_visits)]
    VisitAPI --> GPS[(visit_gps_logs)]
    VisitAPI --> Voice[(employee_voice_notes)]
    Voice --> AIJob[AI Transcription Job]
    AIJob --> AIProvider[AI Provider]
    AIJob --> Draft[(sales_opportunity_drafts)]
```

### Ticket and Maintenance

```mermaid
flowchart TD
    Customer --> TicketForm[Ticket Form]
    TicketForm --> TicketAPI[Ticket API]
    TicketAPI --> Ticket[(tickets)]
    TicketAPI --> PaymentLink[Stripe Payment Link if needed]
    Ticket --> Maintenance[(maintenance_records)]
    Admin --> Assign[Assign Employee]
    Assign --> Ticket
```

### CRM and Marketing

```mermaid
flowchart TD
    Admin --> CRMUI[CRM UI]
    CRMUI --> CRMAPI[CRM API]
    CRMAPI --> Leads[(crm_leads)]
    CRMAPI --> Interactions[(crm_interactions)]
    CRMAPI --> Campaigns[(marketing_campaigns)]
    Campaigns --> Recipients[(campaign_recipients)]
    Recipients --> Responses[(campaign_responses)]
```

### Audit Logging

```mermaid
flowchart TD
    Actor[Admin/Customer/Employee/System] --> BusinessAction[Business Action]
    BusinessAction --> DomainService[Domain Service]
    DomainService --> Data[(Business Tables)]
    DomainService --> Audit[(audit_logs)]
```

## 5. Data Stores

- Users and profiles.
- Product catalog and variants.
- Warehouses and inventory.
- Sales documents.
- Accounting and journals.
- Payments and tax recognition.
- Tickets and maintenance.
- CRM and marketing.
- AI and sales drafts.
- Notifications and audit logs.

## 6. External Systems

- Stripe.
- Mail provider.
- AI transcription provider.
- Private file storage.

## 7. Open Questions

- Which AI provider will be used?
- Which push notification provider will be used?

## 8. Future Spec Kit Extraction Map

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
