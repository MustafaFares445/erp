# ADR 0003: Adopt the Existing Filament Dashboard for the Employees Module

**Status**: Accepted

**Date**: 2026-08-04

**Deciders**: Project Owner

**Related**: `specs/015-employees-plans-visits-dashboard/plan.md`, `Docs/PRD.md`, `Docs/SDD.md`, and the IERP Constitution Product Scope & Boundaries section

## Context

The constitution's Product Scope & Boundaries section permits a Filament
dashboard dependency only for the Inventory module (ADR 0001) and the CRM
module (ADR 0002). The Employees module — employee profiles, monthly plans,
tasks, field visits, AI voice-note review, performance scoring, and salary
calculation — has none of that scaffolding built yet, but the `/admin` panel
already reserves the navigation group, resource classes, and English labels
for it (`app/Filament/AdminModuleRegistry.php`, `lang/en/admin.php`).

The Employees module's source of truth is the Arabic SRS
`IERP_Employees_Module_SRS.pdf`, which describes both a dashboard for
managers/payroll/reviewers and a separate employee-facing mobile app for
field visit capture, attendance, and voice notes. The two surfaces have very
different risk profiles: the dashboard is an internal administrative tool
behind Spatie Permission; the mobile app would be a new public-facing API
surface, an authentication flow, and a field-data-capture pipeline. Building
the app was never part of this feature's request, and the constitution's
Product Scope & Boundaries section already excludes new customer/employee-app
API surfaces unless separately approved.

This feature must not create a second audit trail, permission store, media
store, or attendance/shift/working-hours module; it must reuse the existing
`audit_logs`, Spatie Permission, and Spatie Media Library infrastructure the
same way ADR 0001 and ADR 0002 did for Inventory and CRM.

## Decision

Use the existing `/admin` Filament dashboard for the Employees module,
limited to dashboard operations. The seven resources already pinned in
`AdminModuleRegistry` — Employees, Monthly Plans, Tasks, Visits, Performance,
Salary Calculations, and Employee Reports — are backed by real domain models
and services in this feature.

**Authorised** (as `/admin` Filament dashboard surfaces only): employee
profiles; monthly plans; tasks; visits; voice-note review; AI transcription
review; performance calculations; salary calculations; bonus review;
employee reports; and dashboard roles and permissions.

**Not authorised by this ADR**: `/api/employee` endpoints; the employee
mobile application; employee-app visit capture; employee-app attendance
capture; mobile authentication flows; and any other employee-facing API
functionality. Implementing an employee-facing API or mobile app later
requires its own specification and either a separate ADR or an explicit
amendment to this one.

Because field visit and GPS capture belong to the out-of-scope employee app,
this feature treats `customer_visits`, `visit_gps_logs`, and
`employee_voice_notes` as **read, review, and administer** surfaces from the
dashboard. The dashboard may create a visit record for dashboard-originated
entry, but it does not build field capture.

All employee, plan, task, visit, voice-note, performance, and salary
mutations are routed through domain services under `app/Services/Employees/`,
using the existing `AuditLogger`, Spatie Permission, and Spatie Media Library
infrastructure — no parallel audit store, permission store, or media store.
File storage for visit attachments and voice-note audio uses Media Library
collections, not custom per-feature file tables.

This approval is limited to English-only UI strings for this phase, following
spec 013's precedent.

The constitution's Specification Governance extraction order lists this work
as `011-employee-app-plans-visits-ai`; this ADR authorises only that entry's
dashboard portion. The actual feature directory is
`015-employees-plans-visits-dashboard`, reflecting that scope narrowing.

## Consequences

- The constitution's Product Scope & Boundaries section gains a third narrow
  Filament dashboard exception, alongside ADR 0001 (Inventory) and ADR 0002
  (CRM).
- Existing `AuditLogger`, Spatie Permission, Spatie Media Library, and
  `TracksBlameable` infrastructure remain canonical and are extended, not
  duplicated, for the Employees module.
- No attendance, shift, or working-hours module is introduced; schedule and
  work-time performance factors are derived only from data this feature
  already owns (task due dates and visit check-in/check-out timestamps).
- No employee-facing API or mobile surface is introduced by this feature.
  `/api/employee`, the employee mobile app, employee-app visit capture,
  employee-app attendance capture, and mobile authentication flows all stay
  out of scope pending their own specification and ADR.
- Any future Filament dashboard exception for another module still requires
  its own ADR.
