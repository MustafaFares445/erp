# Notifications Business Rules

---
status: canonical
owner: notifications
last_verified: 2026-10-02
verified_against: notification dispatcher/renderer and tests
---

- Business domains dispatch semantic events/templates; Notifications does not own the originating business transition.
- Rendering is centralized through notification templates/variables.
- Delivery state is persisted independently from the source domain record.
- Failed delivery may be retried through the dispatcher.
- Suppression/bounce/failure must not be misreported as a successful business notification.
- Preferences/templates remain configuration; source business facts remain in their domain.
