# pen.dev Build Sequence

Use this sequence instead of asking the agent to generate the entire app in one uncontrolled pass.

## Phase 0 — Setup

1. Create/open `design/employee-sales-app-v1.pen`.
2. Keep the file inside the same repository.
3. Add `pen-skill/SKILL.md` as a custom skill if using the pen.dev desktop agent.
4. Ask the agent to read the approved spec and all pen-ui docs.
5. Create empty named canvas zones before screen generation.

Exit criteria:
- Correct file open.
- Agent confirms source files.
- Canvas zones created.

## Phase 1 — Design System

Prompt:
"Build only the Design System zone from 04_DESIGN_SYSTEM_AND_COMPONENTS.md. Create variables and reusable component origins. Do not build app screens yet."

Review:
- Variables exist.
- Components are reusable origins.
- Status/color semantics are consistent.
- Touch targets are appropriate.
## Phase 2 — Authentication + Navigation + Home

Prompt:
"Using the approved components, build A01-A04, B01-B04, the five-item main bottom navigation, Profile avatar entry, and a separate six-item navigation comparison frame."

Review:
- First password change cannot be skipped.
- Device rejection is clear.
- Home prioritizes active/next work.
- Task mobile actions are Start and Complete only.

## Phase 3 — Visit End-to-End

Prompt:
"Build C01-C11 in journey order. Treat this as the primary V1 workflow. Include default, error, processing, and completed states."

Review with special attention to:
- Geofence and permission states.
- Running visit duration.
- GPS active/sync indication.
- Voice processing states.
- AI vs employee-confirmed distinction.
- Product editing.
- No Opportunity path.
- Check Out prerequisites.
- Conversation after completion.

Do not continue until this flow is coherent screen-to-screen.
## Phase 4 — Sales

Prompt:
"Build D01-D06 using the existing design system. Show opportunity review state, confirmed products, quotation creation, server-resolved pricing, status timeline, and requote. Do not add inventory actions."

Review:
- Opportunity ownership is clear.
- Disabled quotation CTA has an explanation.
- Draft vs Sent is visually obvious.
- Price changes can be reviewed before send.
- Accepted quotation does not turn into a warehouse workflow.

## Phase 5 — Employee Information

Prompt:
"Build E01-E04: performance, salary and bonus, notifications, and profile/security."

Review:
- Employee sees only personal performance/financial records.
- Notification rows deep-link clearly.
- Device reset is not offered as self-service.
- Profile gives access to change password/logout.

## Phase 6 — Error/Empty/Loading Coverage

Prompt:
"Build a state gallery from 05_STATES_ERRORS_AND_MICROCOPY.md. Reuse real components rather than drawing disconnected mockups."

Review all required utility states and confirm the same messages/components are used inside screens.
## Phase 7 — Visual QA

Ask the pen.dev agent:
"Audit the whole current document against the approved spec and screen contracts. Report missing screens, unsupported actions, inconsistent components, accessibility concerns, and navigation dead ends. Fix only presentation/design issues; do not invent business behavior."

Manual review:
- No Support/Maintenance.
- No GPS outside visits.
- No offline Check In/Out.
- No admin-only transitions.
- No warehouse/inventory actions.
- AI never appears automatically confirmed.
- All critical actions have confirmation/loading/error feedback.

## Phase 8 — Handoff Preparation

- Ensure semantic component/layer names.
- Ensure component origins remain linked.
- Keep annotations outside device frames.
- Save the `.pen` file in Git.
- Export overview screenshots if needed for stakeholder review.
- Later, when Flutter implementation starts, use the same `.pen` file and repository context for design-to-code assistance rather than recreating the design elsewhere.
