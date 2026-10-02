# Logistics & Shipments Testing

---
status: canonical
owner: logistics
last_verified: 2026-10-02
verified_against: tests/Feature/Shipments and logistics-related Inventory/Sales tests
---

Focused shipment confirmation coverage lives in `tests/Feature/Shipments/ShipmentArrivalConfirmationServiceTest.php`.

Cross-domain logistics behavior is also tested in Inventory delivery/shipment tests and Sales order-completion/fulfillment tests.

Changes to dispatch or arrival must verify Inventory stock/reservation behavior and Sales completion eligibility, not only Shipment status.
