# pen.dev UI Handoff — Employee Sales App V1

This folder converts the approved employee-app behavior into a UI/UX build package for pen.dev.

## Authoritative product source

Read first:
- `../EMPLOYEE_APP_V1_APPROVED_SPEC.md`

The approved behavior is the source of truth for business rules. The files in this folder define presentation, navigation, states, and the sequence for building the UI.

## Files

1. `01_INFORMATION_ARCHITECTURE_AND_NAVIGATION.md`
2. `02_USER_FLOWS.md`
3. `03_SCREEN_SPECIFICATIONS.md`
4. `04_DESIGN_SYSTEM_AND_COMPONENTS.md`
5. `05_STATES_ERRORS_AND_MICROCOPY.md`
6. `06_PEN_MASTER_PROMPT.md`
7. `07_PEN_BUILD_SEQUENCE.md`
8. `pen-skill/SKILL.md`

## pen.dev target

Create the design as a `.pen` file inside the project workspace so the pen.dev agent can read the specifications and, later, implementation code from the same repository.
Recommended output location:
- `design/employee-sales-app-v1.pen`
- Optional reusable library: `design/ierp-mobile.lib.pen`

Use pen.dev variables, components, component instances, and slots rather than duplicating raw layers.

## Product scope guardrails

V1 is Sales Field Employees only.

Do not introduce:
- Support or Maintenance workflows.
- Location tracking outside an active visit.
- Offline Check In or Check Out.
- Warehouse, Logistics, Inventory receiving, or stock movement screens.
- Employee access to other employees' records.

## UI design status

Business behavior: approved.
Visual style: design recommendation until a brand-specific mobile design system is supplied.

The UI should be production-oriented, not a conceptual dashboard mockup. Every primary action must map to an approved state transition or server action.
