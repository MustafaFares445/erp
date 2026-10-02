# Employees Domain

---
status: canonical
owner: employees
last_verified: 2026-10-02
verified_against: app/Services/Employees, employee enums/permissions, tests/Feature/Employees and Performance
---

## Purpose

Employees owns employee onboarding/access, monthly plans, tasks, visits, voice-note intake/transcription review, performance scoring, salary calculation, bonus suggestions and employee reports.

## Main Code Anchors

EmployeeOnboardingService, EmployeeAccessService, SalesPlanService/DuplicationService, PlanTaskService, VisitReviewService, VoiceNoteIntakeService/transcribers, KeywordDetectionService, PerformanceScoringService, SalaryCalculation/Recalculation services and review/bonus services.

## Main UI Surfaces

Employees, Monthly Plans, Tasks, Visits, Performance, Salary Calculations and Employee Reports.

## Current Mobile/API Status

Employee domain services exist, but the current runtime has no `api/*` routes. The active employee visit/AI API plan describes future exposure and must remain labeled planned until implemented.

## Canonical Cross-Domain Flows

See [IERP Canonical Business Flows](../../product/BUSINESS_FLOWS.md) for end-to-end interactions with other domains.
