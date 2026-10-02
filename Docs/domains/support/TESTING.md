# Support & Maintenance Testing

---
status: canonical
owner: support
last_verified: 2026-10-02
verified_against: tests/Feature/Support
---

Coverage includes ticket intake/triage/lifecycle/priority/payment/provider settlement, SLA, maintenance assessment/billing/costs, service execution/parts, preventive schedules, permissions/pages/reports, warranty activation/claims/service/recovery and cross-domain race/settlement guards.

Representative tests include `TicketLifecycleTest`, `TicketTriageTest`, `TicketPaymentTest`, `SlaTest`, `MaintenanceBillingTest`, `ServiceRecordPartTest`, `PreventiveMaintenanceFlowTest`, `WarrantyClaimWorkflowTest`, `WarrantyRecoveryTest` and `TicketRecoveryEntitlementRaceTest`.
