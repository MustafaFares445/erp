# Master Prompt for pen.dev

Paste this into the pen.dev agent composer with the target `.pen` document open.

---

You are designing the production UI/UX for the IERP Employee Sales App V1.

Before changing the canvas, read these files from the current repository:
1. `product-specs/mobile-apps/employee/EMPLOYEE_APP_V1_APPROVED_SPEC.md`
2. `product-specs/mobile-apps/employee/pen-ui/01_INFORMATION_ARCHITECTURE_AND_NAVIGATION.md`
3. `product-specs/mobile-apps/employee/pen-ui/02_USER_FLOWS.md`
4. `product-specs/mobile-apps/employee/pen-ui/03_SCREEN_SPECIFICATIONS.md`
5. `product-specs/mobile-apps/employee/pen-ui/04_DESIGN_SYSTEM_AND_COMPONENTS.md`
6. `product-specs/mobile-apps/employee/pen-ui/05_STATES_ERRORS_AND_MICROCOPY.md`

The approved behavior document is authoritative for business rules. Do not invent new business transitions or remove required behavior.

Goal:
Create a polished, implementation-ready mobile UI in the current pen.dev document for Sales Field Employees only.

Start by creating a Design System zone with variables and reusable component origins. Then build screens as component instances.

Use a primary mobile frame of 390 x 844 and make layouts responsive rather than manually positioning every element.
Visual direction:
- Modern professional field-sales application.
- Clean light surfaces.
- Strong hierarchy and readable outdoor contrast.
- One restrained primary blue plus semantic success/warning/danger.
- Minimal decoration.
- Mobile-first, touch-friendly controls.
- Cards only where grouping helps; do not turn every row into a floating card.
- Status must use text plus visual treatment, not color alone.

Navigation:
Use five persistent bottom items: Home, Tasks, Visits, Sales, Notifications.
Open Profile/Security from the employee avatar in the top app bar.
Also create a small alternate navigation frame showing the six-item variant from the approved specification for product review; do not use it as the main flow.

Critical UX:
- Active Visit must always be obvious and quickly resumable.
- Check In must show location/geofence context.
- During an active visit, show elapsed time and GPS tracking state.
- Voice/AI processing must never feel like it blocks field work.
- Always visually separate AI suggestions from employee-confirmed outcome/products.
- Final visit completion requires a deliberate Review -> Confirm -> Check Out flow.
- Quotations are sales documents only; do not add Inventory/Warehouse actions.
Build these canvas zones left-to-right:
1. Design System
2. Authentication & Security
3. Home / Plan / Tasks
4. Visit End-to-End
5. Sales Opportunities & Quotations
6. Performance / Salary / Notifications / Profile
7. Errors / Empty / Loading states
8. Navigation alternative comparison

Within Visit End-to-End, place frames in actual journey order:
Visit Details -> Check In -> Active Visit -> Voice Recorder -> AI Processing -> Review & Confirm -> Product Picker -> Check Out -> Completed Visit -> Conversation.

For every main screen:
- Use realistic sample ERP data.
- Name the frame with its screen ID from the screen specification.
- Add a small annotation outside the phone frame containing: entry conditions, primary action, important backend state.
- Use consistent component instances.
- Include loading/empty/error variants where relevant.

Do not:
- Add support tickets or maintenance.
- Track employee location outside active visits.
- design offline Check In/Out.
- expose employee IDs or technical enum names.
- add admin controls to mobile.
- add stock levels, warehouse picking, delivery posting, or receiving.
Design system requirements:
- Define pen.dev variables for colors, spacing, radius, typography, and control height.
- Create component origins for navigation, buttons, inputs, cards, status badges, visit/GPS states, voice note, AI suggestions, product rows, quotations, notifications, and conversation bubbles.
- Use component instances and slots where appropriate.
- Keep semantic layer names suitable for later design-to-code use.
- Do not detach instances for routine content overrides.

Language:
Use the English microcopy from the UI handoff for this design pass.
Keep layout and component structure RTL-ready so an Arabic variant can be generated later without redesigning the information architecture.

Quality bar:
This should look like a real employee app ready for Flutter implementation, not a wireframe and not an admin dashboard shrunk to mobile.

Before finishing:
- Verify every approved V1 capability has at least one screen or explicit entry point.
- Verify every primary CTA maps to an approved action.
- Verify all critical error states exist.
- Verify Check In and Check Out cannot appear as offline actions.
- Verify AI suggestions are not presented as final.
- Verify Profile is still reachable although it is not a bottom-nav item.
- Take overview screenshots of each canvas zone for visual review.
