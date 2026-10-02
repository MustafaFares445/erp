# Settings Testing

---
status: canonical
owner: settings
last_verified: 2026-10-02
verified_against: tests/Feature/Settings
---

`tests/Feature/Settings/` covers business-constraint model/service behavior, constraint guards, pricing discount ceilings and system permission seeding.

Changes to a shared constraint require tests in Settings plus focused tests in each consuming domain whose behavior changes.
