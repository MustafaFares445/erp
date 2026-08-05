# Contract: Dashboard Surfaces, Navigation, and Screen Behaviors

This feature extends the existing `/admin` Filament dashboard. It adds no second dashboard,
customer-facing interface, or employee-facing API/app surface (ADR 0003).

## Resources

Class names for the six pinned resources are **fixed** by `AdminModuleRegistry`. Navigation sort
belongs to the `employees` range **600–699** (group `sort => 6`, per the registry's documented
"group position × 100" convention).

| Resource | Model | Sort | Pages |
|---|---|---|---|
| `Employees\EmployeeResource` | `EmployeeProfile` | 601 | List, Create, View, Edit |
| `MonthlyPlans\MonthlyPlanResource` | `SalesPlan` | 611 | List, Create, View, Edit |
| `Tasks\TaskResource` | `PlanTask` | 612 | List, View, Edit |
| `Visits\VisitResource` | `CustomerVisit` | 621 | List, View (+ Edit for `visit.field-edit` holders) |
| `Performance\PerformanceResource` | `EmployeePerformanceScore` | 641 | List, View (preview screen) |
| `SalaryCalculations\SalaryCalculationResource` | `EmployeeSalaryCalculation` | 642 | List, View |
| `EmployeeReports\EmployeeReportResource` | report aggregate | reports group | List with filters + export |

Each follows the established layout: `{Domain}/{Name}Resource.php`, `{Domain}/Pages/*.php`,
`{Domain}/Schemas/{Name}Form.php`, `{Domain}/Schemas/{Name}Infolist.php`,
`{Domain}/Tables/{Name}sTable.php`, with `#[\Override]` on the inherited statics and
`navigationGroup = 'admin.groups.employees'`.

## Registry additions required

Four surfaces have no registry entry yet: voice notes, AI keyword rules, sales-opportunity drafts,
and bonus suggestions. The `employees` navigation group has 6 items and needs 10. The `employees`
group gains `sections`, exactly as the `inventory` group already does (the registry already
supports this, and `AdminPanelServiceProvider::navigation()` renders sections as collapsible
`NavigationGroup`s):

| Section key | Label key | Items |
|---|---|---|
| `workforce` | `admin.sections.workforce` | Employees |
| `planning` | `admin.sections.planning` | Monthly Plans, Tasks |
| `field` | `admin.sections.field` | Visits, Voice Notes |
| `intelligence` | `admin.sections.intelligence` | Keyword Rules, Opportunity Drafts |
| `compensation` | `admin.sections.compensation` | Performance, Salary Calculations, Bonus Suggestions |

This requires new `admin.sections.*` and `admin.resources.*` keys in `lang/en/admin.php` and
matching registry items. `AdminModuleRegistryTest` and `DashboardLayoutTest` need updating for the
new sections and items.

## Screen behaviors

- **Plan form** — weight fields with a live sum indicator; save blocked unless the four weights sum
  to exactly 100 and the plan has ≥1 task (D4/FR-022), with the failure explained inline (FR-080). A
  `required_visit_minutes` field shows the config default as its placeholder, labeled as the
  threshold work-time adherence measures against (FR-026).
- **Plan actions** — "Copy to month" and "Assign to another employee" both open the D9 duplication
  flow (see [plan-lifecycle.md](./plan-lifecycle.md)), naming the target employee and month and
  refusing up front when that employee already has an active plan for it. Plus
  Activate/Pause/Complete/Archive (each gated by the transition table), Delete (disabled with a
  reason once a task is completed), Restore.
- **Task form** — `starts_at` and `due_at` are required and validated against the plan window
  (FR-030, FR-032).
- **Task table** — filters for Overdue / Due soon / Completed (FR-034); a status-change action that
  always writes a `TaskStatusLog` with a note, and refuses a rejected transition with a clear message
  rather than a hidden button. Reopening a `Completed` task warns that it clears `completed_at` and
  makes the plan's performance score stale.
- **Visit view** — infolist with computed duration, a chronological GPS timeline (`RepeatableEntry`
  over `visit_gps_logs`), an attachments gallery from the media collection, and an audio player per
  voice note. The whole form is read-only when `recorded_channel = field` and the user lacks
  `employees.visit.field-edit`.
- **Visit review note (D7)** — a single "Add / update review note" action, visible to
  `employees.visit.review` holders and **available even while the visit is locked**. The infolist
  shows the current note with `reviewed_by` and `reviewed_at`; the audit log link exposes prior
  versions, since the column itself holds only the latest text.
- **Voice note panel** — processing status, transcript, `detected_language`, and `error_message`
  shown as a warning when transcription failed (FR-051). Confidence rendering is governed by
  `confidence_source` (see [voice-note-ai.md](./voice-note-ai.md)): `ProviderReported` → `87.50%`;
  `DerivedFromLogProb` → `≈ 87.50%` with a tooltip stating it is derived, not provider-reported;
  `Unavailable` → "Not reported by provider". A null confidence must never render as `0.00%`
  (FR-056).
- **Performance view** — the FR-061 preview: each factor's numerator, denominator, ratio, weight,
  and weighted contribution, read from `calculation_breakdown` (see
  [performance-scoring.md](./performance-scoring.md)), including the effective
  `required_visit_minutes` and the count of completed visits excluded for missing timestamps, plus
  an explicit "no data" marker on any zero-denominator factor.
- **Salary view** — a `PendingConfirmation` banner when a plan change has produced a new
  calculation, with an explicit Confirm action (FR-065) and a link to the superseded row.
  `payable_base` is shown alongside its source mode.
- **Every table** — searchable, filterable, paginated (FR-085, FR-014, FR-070).

## Guarantees

- Ten dashboard surfaces are reachable, permission-gated, English, and audited.
- No route, navigation item, or resource outside `/admin` is introduced for this feature.
- Every table supports search, filter, and pagination.

## Verification

- `AdminModuleRegistryTest` and `DashboardLayoutTest`: all ten items render under the correct
  sections for an authorized user and are absent for an unauthorized one.
- `PageUsageGuide` renders correct help text for each new resource without additional registration
  (derives from the class name).
- Manual/browser smoke: each of the ten surfaces opens, lists, filters, and paginates; the plan
  weight-sum indicator and copy/assign flows behave as described; the visit form locks correctly for
  a field-recorded visit and unlocks only the review-note action; voice-note confidence renders per
  the three-row table above; the salary `PendingConfirmation` banner and Confirm action appear after
  a plan-driven recalculation.
