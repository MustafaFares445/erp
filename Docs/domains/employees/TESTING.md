# Employees Testing

---
status: canonical
owner: employees
last_verified: 2026-10-07
verified_against: tests/Feature/Employees and tests/Feature/Performance
---

Coverage includes onboarding/access, role/permission matrix, plan invariants/lifecycle/duplication, task transitions/audit, visit lifecycle/GPS/media/review, customer-owned equipment validation, follow-up provenance, voice-note intake/playback/transcription isolation, OpenAI/fake transcribers, AI opportunity review, Approved-only opportunity scoring, performance, salary recalculation/confirmation, reports and authorization.

The voice/AI path has dedicated failure/isolation tests and must remain independently testable from visit completion.
