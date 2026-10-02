# Data Model Reference

---
status: canonical
owner: engineering
last_verified: 2026-10-02
verified_against: current migrations/models and architecture data documentation
---

The canonical schema source is the current Laravel migrations and Eloquent models.

For architecture and aggregate ownership, read [Data Architecture](../architecture/DATA_ARCHITECTURE.md).

For business relationships/provenance, read [Canonical Business Flows](../product/BUSINESS_FLOWS.md) and the owning domain docs.

## Inspecting Current Schema

Use current Laravel/database tooling rather than a static all-table Markdown ERD.

Useful approaches include:

- inspect `database/migrations/`;
- inspect the Eloquent model and relationships;
- use the project's database-schema tooling where available;
- use read-only database inspection in the active environment.

## Documentation Rule

Add a domain-specific `DATA_MODEL.md` only where a diagram/table materially helps a developer understand a complex aggregate. Do not duplicate every migration column in prose.
