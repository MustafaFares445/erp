# Cross-module UI and workflow remediation — October 2026

## Delivered behavior

- Quotation View links to the related Order View with permission checks.
- Removed the redundant Next module placeholder from Create Order.
- Create Invoice now uses InvoiceService::createStandalone with user-entered line items, generated invoice numbers and authoritative totals. Domain errors become validation errors.
- Credit Note invoice selection is disabled until a customer is selected, filtered to that customer's issued invoices, and checked again on save.
- Product Variant rows open View; attribute/value selection is dependent, duplicates are guarded, and assignments are visible and editable through resource actions.
- Lot View displays the business LOT only; normalized identifiers remain internal.
- Stock availability and replenishment are separate. Available stock can be below minimum; absence of an active policy is Not configured.
- Stock and Lot quantity displays use the shared six-place meaningful-decimal formatter. Accounting calculations are unchanged.
- Filament selects preload a bounded first 50 options; calendar and time controls have visible type-specific icons.
- Shared Leaflet/OSRM routes are embedded on Shipment, Order, warehouse Transfer and Visit views. Visit road directions are distinct from actual GPS evidence.
- Routing failure or missing coordinates never produces fictitious distance or ETA. Multi-shipment orders do not invent one combined route.

## Safety

- Built-in Filament actions already render Livewire button loading indicators and disable buttons while requests are processing.
- Confirm, Issue, Reverse and other sensitive actions still use existing domain services, permissions and audit logic.
- Browser directions links open an external route planner; they are separate from recorded route evidence.
- Publish new custom Alpine map assets using php artisan filament:assets.

## Validation

- Initial suite: 29 passed (170 assertions).
- New invoice/map suite: 2 passed (10 assertions).
- Broader affected suite: 47 passed (173 assertions).
- npm run build succeeded; Filament assets published successfully.
- Full-suite, browser EN/AR responsive and static-analysis validation are still required before production. These results do not imply 100% code coverage.
