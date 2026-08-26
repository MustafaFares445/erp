# Contract: Sales Permissions and Roles

**Feature Directory**: `019-sales-lifecycle-payments-credits`

**Created**: 2026-08-23

Guard: `web`. Backed by `spatie/laravel-permission`, seeded by `SalesPermissionSeeder`, consumed by `App\Enums\SalesPermission` and `App\Policies\Concerns\ChecksSalesPermissions`.

## 1. Permission Catalogue

`App\Enums\SalesPermission` is the single source of truth (FR-071). The seeder and every policy read from it; no permission string is written literally anywhere else.

| Case | Value | Governs |
|---|---|---|
| `SalesSettingView` | `sales.setting.view` | View sales settings |
| `SalesSettingManage` | `sales.setting.manage` | Edit tax rate and posting accounts |
| `PaymentTermView` | `sales.payment-term.view` | List and view terms |
| `PaymentTermManage` | `sales.payment-term.manage` | Create, edit, delete terms |
| `PaymentMethodView` | `sales.payment-method.view` | List and view methods |
| `PaymentMethodManage` | `sales.payment-method.manage` | Create, edit, delete methods |
| `QuotationView` | `sales.quotation.view` | List and view quotations |
| `QuotationManage` | `sales.quotation.manage` | Create, edit, delete **drafts**; send |
| `QuotationDecide` | `sales.quotation.decide` | Record the customer's accept or reject |
| `QuotationConvert` | `sales.quotation.convert` | Convert an accepted quotation to an order |
| `OrderView` | `sales.order.view` | View an order's commercial detail |
| `OrderManage` | `sales.order.manage` | Edit an order's commercial detail |
| `DeliveryNoteView` | `sales.delivery-note.view` | The Delivery Notes surface |
| `InvoiceView` | `sales.invoice.view` | List and view invoices |
| `InvoiceManage` | `sales.invoice.manage` | Create, edit, delete **drafts** |
| `InvoiceIssue` | `sales.invoice.issue` | Transition `draft` → `issued`, posting to the ledger |
| `InvoiceSend` | `sales.invoice.send` | Generate the PDF and dispatch the email |
| `InvoiceConfirmReceipt` | `sales.invoice.confirm-receipt` | Record a receipt confirmation with signature |
| `PaymentView` | `sales.payment.view` | List and view payments |
| `PaymentRecord` | `sales.payment.record` | Record a payment, allocate it, post it |
| `PaymentReverse` | `sales.payment.reverse` | Reverse a posted payment |
| `CreditNoteView` | `sales.credit-note.view` | List and view credit notes |
| `CreditNoteManage` | `sales.credit-note.manage` | Create, edit, delete **drafts** |
| `CreditNoteConfirm` | `sales.credit-note.confirm` | Transition `draft` → `confirmed`, posting to the ledger |
| `CreditNoteReverse` | `sales.credit-note.reverse` | Reverse a confirmed credit note |
| `AuditView` | `sales.audit.view` | Sales entries in the audit log |

**Six separations are deliberate and are what FR-072 requires.** Each names the moment where an act stops being reversible or stops being about our own records:

1. `QuotationManage` does **not** imply `QuotationDecide`. Drafting an offer is our own record; asserting that the customer accepted it commits the company to a price on a third party's behalf. This is the same reasoning ADR 0006 applied to admin-recorded supplier confirmations.
2. `QuotationDecide` does **not** imply `QuotationConvert`. The answer and the commitment to fulfil it are separate acts, and conversion is what enters the fulfillment machinery.
3. `InvoiceManage` does **not** imply `InvoiceIssue`. A draft is editable and deletable; issuing makes the invoice immutable, undeletable, and posts to the ledger. It is the single least reversible action in the feature.
4. `InvoiceIssue` does **not** imply `InvoiceSend`. Sending puts the document in front of the customer. A held invoice is a legitimate state.
5. `PaymentRecord` does **not** imply `PaymentReverse`. Recording collection adds to history; reversal changes the meaning of history already reported, including tax already recognised. Mirrors 018's `JournalEntryPost` / `JournalEntryReverse` split exactly.
6. `CreditNoteManage` does **not** imply `CreditNoteConfirm`, and `CreditNoteConfirm` does **not** imply `CreditNoteReverse`. A credit note is the only correction path for an issued invoice, so confirming one is how money leaves the receivable.

`DeliveryNoteView` grants the Sales-side read surface only. **Completing, dispatching or cancelling a delivery from that surface requires the existing `InventoryPermission` cases**, unchanged — a Sales role does not gain the ability to move stock (FR-034). This is the contract's most important line: it is what stops the new surface becoming a second authorisation path to the same stock mutation.

## 2. Fixed Roles

Three new roles, registered in `App\Enums\DashboardRole` (FR-073) so every module's admin-bypass check narrows consistently. `SalesManagerRoleNarrowingTest` proves the narrowing rather than assuming it, following `PurchasingRoleNarrowingTest` and `AccountingRoleNarrowingTest`.

| Permission | System Admin | Sales Manager | Sales Officer | Billing Officer | Reviewer |
|---|---|---|---|---|---|
| `sales.setting.view` | ✅ | ✅ | — | ✅ | ✅ |
| `sales.setting.manage` | ✅ | — | — | — | — |
| `sales.payment-term.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `sales.payment-term.manage` | ✅ | ✅ | — | — | — |
| `sales.payment-method.view` | ✅ | ✅ | — | ✅ | ✅ |
| `sales.payment-method.manage` | ✅ | — | — | — | — |
| `sales.quotation.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `sales.quotation.manage` | ✅ | ✅ | ✅ | — | — |
| `sales.quotation.decide` | ✅ | ✅ | ✅ | — | — |
| `sales.quotation.convert` | ✅ | ✅ | — | — | — |
| `sales.order.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `sales.order.manage` | ✅ | ✅ | — | — | — |
| `sales.delivery-note.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `sales.invoice.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `sales.invoice.manage` | ✅ | ✅ | — | ✅ | — |
| `sales.invoice.issue` | ✅ | ✅ | — | ✅ | — |
| `sales.invoice.send` | ✅ | ✅ | — | ✅ | — |
| `sales.invoice.confirm-receipt` | ✅ | ✅ | ✅ | ✅ | — |
| `sales.payment.view` | ✅ | ✅ | — | ✅ | ✅ |
| `sales.payment.record` | ✅ | — | — | ✅ | — |
| `sales.payment.reverse` | ✅ | — | — | — | — |
| `sales.credit-note.view` | ✅ | ✅ | — | ✅ | ✅ |
| `sales.credit-note.manage` | ✅ | ✅ | — | ✅ | — |
| `sales.credit-note.confirm` | ✅ | ✅ | — | — | — |
| `sales.credit-note.reverse` | ✅ | — | — | — | — |
| `sales.audit.view` | ✅ | ✅ | — | ✅ | ✅ |

Reading the matrix as job descriptions:

- **Sales Manager** owns the customer relationship: quotes, converts, prices orders, and confirms credit notes. Cannot record or reverse a payment — the person who agrees a discount is not the person who says the money arrived.
- **Sales Officer** works the front end only: quotes, records the customer's answer, confirms delivery receipt. Touches no invoice and no money.
- **Billing Officer** owns the money: issues and sends invoices, records payments, drafts credit notes. Cannot convert a quotation, cannot confirm a credit note, and cannot reverse anything. Drafting the correction and approving it are split.
- **Reviewer** is read-only across every sales surface (FR-074), consistent with its role in Inventory, CRM, Employees, Support and Accounting.
- **`sales.payment.reverse` and `sales.credit-note.reverse` are System Admin only.** Both rewrite the meaning of already-reported financial history. 018 gave reversal to Chief Accountant because reversal *is* that role's job; here no sales role's job is undoing collections.

`sales.setting.manage` and `sales.payment-method.manage` are also System Admin only: both choose which ledger accounts the module posts to, which is an accounting decision wearing a sales label.

## 3. Policy Mapping

One policy per model, each using `ChecksSalesPermissions`. Filament resources derive every action's visibility from the policy — no `visible()` closure re-implements a check (the 017 and 018 convention).

| Model | `viewAny`/`view` | `create`/`update` | `delete` | Additional abilities |
|---|---|---|---|---|
| `SalesSetting` | `setting.view` | `setting.manage` | never | — |
| `PaymentTerm` | `payment-term.view` | `payment-term.manage` | `payment-term.manage` **and** unreferenced | — |
| `PaymentMethod` | `payment-method.view` | `payment-method.manage` | `payment-method.manage` **and** unreferenced | — |
| `Quotation` | `quotation.view` | `quotation.manage` **and** status `draft` | `quotation.manage` **and** status `draft` | `send`, `decide`, `convert` |
| `Order` | `order.view` | `order.manage` | existing `OrderPolicy` rules, unchanged | — |
| `InventoryOperation` | *unchanged* — existing `InventoryOperationPolicy`, plus `delivery-note.view` for the Sales surface | *unchanged* | *unchanged* | *unchanged* |
| `Invoice` | `invoice.view` | `invoice.manage` **and** not issued | `invoice.manage` **and** not issued | `issue`, `send`, `confirmReceipt` |
| `InvoiceConfirmation` | `invoice.view` | `invoice.confirm-receipt` | **never** (append-only) | — |
| `Payment` | `payment.view` | `payment.record` **and** not posted | `payment.record` **and** not posted | `reverse` |
| `PaymentAllocation` | `payment.view` | `payment.record` **and** parent not posted | as create | — |
| `TaxRecognitionEntry` | `payment.view` | **never** (service-written) | **never** (append-only) | — |
| `CreditNote` | `credit-note.view` | `credit-note.manage` **and** status `draft` | `credit-note.manage` **and** status `draft` | `confirm`, `reverse` |

`InventoryOperation`'s row is the load-bearing one: its policy is **not modified**. The Sales surface adds a view gate on top of the existing policy and inherits every stock-mutating gate unchanged.

## 4. Service-Layer Enforcement

Every sales service authorizes through `Gate::forUser($actor)` on an explicit `User` argument, exactly as `JournalPostingService` does. **No sales service calls `auth()`, `request()`, or any ambient accessor** (FR-077), and an architecture test proves it — so a service invoked from a queued job, a console command, or a test authorizes against the actor it was given rather than against nobody.

The three ledger-posting services authorize their **own** ability and then call `JournalPostingService`, which authorizes `createFromSource` and `postFromSource` on `JournalEntry` in turn — not the plain `create`/`post` a human authoring a manual entry needs. `JournalPostingService::draft()` and `::post()` choose between the two ability names based on whether a `$source` document is given (spec 019, ADR 0008), so a document-sourced posting and a free-form manual one are provably different acts to the Gate, not merely by convention:

| Service | Sales ability checked | Accounting abilities then required |
|---|---|---|
| `InvoicePostingService::post()` | `issue` on the invoice | `JournalEntry` `createFromSource` + `postFromSource` |
| `PaymentPostingService::post()` | `record` on the payment | `JournalEntry` `createFromSource` + `postFromSource` |
| `CreditNotePostingService::confirm()` | `confirm` on the credit note | `JournalEntry` `createFromSource` + `postFromSource` |
| `PaymentService::reverse()` | `reverse` on the payment | `JournalEntry` `reverse` |
| `CreditNoteService::reverse()` | `reverse` on the credit note | `JournalEntry` `reverse` |

**This double gate has a consequence the seeder must handle**: Billing Officer holds `sales.invoice.issue` but issuing also needs `accounting.journal-entry.post-from-source` — one new permission this feature adds to `AccountingPermission` (`JournalEntryPostFromSource`), covering both the `createFromSource` and `postFromSource` abilities, since a role trusted to let its own document post through both steps of that atomic action has no real separation-of-duties reason to hold one without the other. `SalesPermissionSeeder` grants Billing Officer and Sales Manager **exactly** this one accounting permission, and nothing else from the catalogue — no ledger view, no reversal, no period close, no chart management, and critically, **not** `JournalEntryManage`.

This is not a loosening of 018's separations, and the reason is structural rather than a promise: `JournalEntryPolicy::create()` (the Class-level ability the Journal Entries resource's "New Journal Entry" page checks) still requires `JournalEntryManage`, which Billing Officer never holds. A Billing Officer who calls `JournalPostingService::draft()` directly with no `$source` is refused by the Gate at `create`, because they hold no permission mapped to it — `JournalEntryPostFromSource` only satisfies `createFromSource`/`postFromSource`, the ability names used exclusively when a source document is present. They can post only the entries the sales services construct on a real document's behalf, and can neither see nor use the manual Journal Entries page at all.

`CrossModulePermissionLeakTest` (extending the 017 pattern) asserts exactly this: a Billing Officer holds precisely two accounting permissions, cannot reach any accounting page, and cannot create a manual journal entry.
