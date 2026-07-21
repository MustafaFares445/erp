# Sequence Diagrams

## 1. Overview

These diagrams show the core IERP workflows. They are implementation-focused and keep the Laravel API as the source of business truth.

## 2. Authentication Flow

```mermaid
sequenceDiagram
    actor User
    participant Client
    participant API
    participant DB
    User->>Client: Enter credentials
    Client->>API: POST /api/auth/login
    API->>DB: Find user and verify password
    DB-->>API: User record
    API-->>Client: Token and profile
```

## 3. Product and Variant Management Flow

```mermaid
sequenceDiagram
    actor Admin
    participant Dashboard
    participant API
    participant ProductService
    participant DB
    Admin->>Dashboard: Submit product/variant
    Dashboard->>API: POST /api/dashboard/products
    API->>ProductService: Validate and create
    ProductService->>DB: Save product, variant, attributes
    ProductService->>DB: Write audit log
    API-->>Dashboard: Product response
```

## 4. Inventory Adjustment Flow

```mermaid
sequenceDiagram
    actor Admin
    participant Dashboard
    participant API
    participant InventoryService
    participant DB
    Admin->>Dashboard: Submit stock adjustment
    Dashboard->>API: POST /api/dashboard/inventory/adjustments
    API->>InventoryService: Validate variant and warehouse
    InventoryService->>DB: Update inventory_stocks
    InventoryService->>DB: Create inventory_movements
    InventoryService->>DB: Audit adjustment
    API-->>Dashboard: Adjustment confirmed
```

## 5. Stock Transfer Flow

```mermaid
sequenceDiagram
    actor Admin
    participant Dashboard
    participant API
    participant InventoryService
    participant DB
    Admin->>Dashboard: Transfer stock
    Dashboard->>API: POST /api/dashboard/inventory/transfers
    API->>InventoryService: Validate source/destination stock
    InventoryService->>DB: Decrease source warehouse stock
    InventoryService->>DB: Increase destination warehouse stock
    InventoryService->>DB: Create two movement records
    API-->>Dashboard: Transfer completed
```

## 6. Quotation Creation Flow

```mermaid
sequenceDiagram
    actor AdminOrEmployee
    participant Client
    participant API
    participant SalesService
    participant DB
    AdminOrEmployee->>Client: Create quotation
    Client->>API: POST /api/.../quotations
    API->>SalesService: Validate customer/items/prices
    SalesService->>DB: Save quotation and items
    SalesService->>DB: Audit quotation creation
    API-->>Client: Quotation created
```

## 7. Customer Quotation Acceptance Flow

```mermaid
sequenceDiagram
    actor Customer
    participant App
    participant API
    participant SalesService
    participant DB
    Customer->>App: Accept quotation
    App->>API: POST /api/customer/quotations/{id}/accept
    API->>SalesService: Check ownership and status
    SalesService->>DB: Set quotation accepted
    SalesService->>DB: Audit status change
    API-->>App: Accepted response
```

## 8. Delivery Note Confirmation Flow

```mermaid
sequenceDiagram
    actor AdminOrEmployee
    participant Client
    participant API
    participant SalesService
    participant InventoryService
    participant DB
    AdminOrEmployee->>Client: Confirm delivery note
    Client->>API: POST /api/dashboard/delivery-notes/{id}/confirm
    API->>SalesService: Validate status
    SalesService->>InventoryService: Decrease stock by variant + warehouse
    InventoryService->>DB: Update inventory_stocks
    InventoryService->>DB: Create inventory_movements
    SalesService->>DB: Set delivery note confirmed
    API-->>Client: Confirmed response
```

## 9. Invoice Creation and PDF Flow

```mermaid
sequenceDiagram
    actor Admin
    participant Dashboard
    participant API
    participant InvoiceService
    participant DB
    participant Queue
    Admin->>Dashboard: Create invoice
    Dashboard->>API: POST /api/dashboard/invoices
    API->>InvoiceService: Validate source and totals
    InvoiceService->>DB: Save invoice and items
    InvoiceService->>Queue: Dispatch PDF generation
    API-->>Dashboard: Invoice created
```

## 10. Stripe Payment and Tax Recognition Flow

```mermaid
sequenceDiagram
    actor Customer
    participant App
    participant API
    participant Stripe
    participant PaymentService
    participant DB
    Customer->>App: Pay invoice
    App->>API: POST /api/customer/invoices/{id}/pay
    API->>Stripe: Create payment intent/link
    Stripe-->>App: Payment URL/client secret
    Stripe->>API: POST /api/webhooks/stripe
    API->>PaymentService: Process succeeded event idempotently
    PaymentService->>DB: Create payment
    PaymentService->>DB: Create tax recognition entry
    PaymentService->>DB: Create journal entry
    PaymentService->>DB: Update invoice status
```

## 11. Manual Payment and Tax Recognition Flow

```mermaid
sequenceDiagram
    actor Admin
    participant Dashboard
    participant API
    participant PaymentService
    participant DB
    Admin->>Dashboard: Record manual payment
    Dashboard->>API: POST /api/dashboard/manual-payments
    API->>PaymentService: Validate method, amount, invoice
    PaymentService->>DB: Create payment and manual record
    PaymentService->>DB: Create tax recognition entry
    PaymentService->>DB: Create journal entry
    PaymentService->>DB: Update invoice status
    API-->>Dashboard: Payment recorded
```

## 12. Credit Note Flow

```mermaid
sequenceDiagram
    actor Admin
    participant Dashboard
    participant API
    participant CreditNoteService
    participant DB
    Admin->>Dashboard: Create credit note
    Dashboard->>API: POST /api/dashboard/credit-notes
    API->>CreditNoteService: Validate invoice and amount
    CreditNoteService->>DB: Save credit note and items
    CreditNoteService->>DB: Create accounting reversal on confirm
    CreditNoteService->>DB: Audit credit note
    API-->>Dashboard: Credit note response
```

## 13. Supplier Confirmation Flow

```mermaid
sequenceDiagram
    actor Admin
    participant Dashboard
    participant API
    participant OrderService
    participant DB
    Admin->>Dashboard: Update supplier confirmation
    Dashboard->>API: POST /api/dashboard/orders/{id}/supplier-confirmations
    API->>OrderService: Validate supplier/order
    OrderService->>DB: Save confirmation status and notes
    OrderService->>DB: Update order status
    API-->>Dashboard: Confirmation saved
```

## 14. Employee Visit, GPS, and AI Voice Note Flow

```mermaid
sequenceDiagram
    actor Employee
    participant App
    participant API
    participant VisitService
    participant Storage
    participant Queue
    participant AI
    participant DB
    Employee->>App: Check in and send GPS
    App->>API: POST /api/employee/visits/{id}/check-in
    API->>VisitService: Save check-in and GPS
    VisitService->>DB: Update visit
    Employee->>App: Upload voice note
    App->>API: POST /api/employee/visits/{id}/voice-notes
    API->>Storage: Store private audio
    API->>DB: Save voice note
    API->>Queue: Dispatch transcription job
    Queue->>AI: Transcribe audio
    AI-->>Queue: Transcript
    Queue->>DB: Save transcript and sales draft if keywords match
```

## 15. Ticket Payment and Live Status Flow

```mermaid
sequenceDiagram
    actor Customer
    participant App
    participant API
    participant Stripe
    participant DB
    Customer->>App: Create paid ticket
    App->>API: POST /api/customer/tickets
    API->>DB: Create ticket pending_payment
    API->>Stripe: Create payment link
    Stripe-->>App: Payment URL
    Stripe->>API: Webhook payment succeeded
    API->>DB: Mark ticket live
```

## 16. Open Questions

- Confirm whether the employee should see AI-detected drafts or only admins.

## 17. Future Spec Kit Extraction Map

See project Future Spec Kit extraction map in the main docs.
