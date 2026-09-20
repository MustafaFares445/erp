# Design System & Component Inventory for pen.dev

## 1. Visual direction

Design recommendation:
- Professional field-sales ERP companion.
- Fast to scan outdoors and while moving between customer visits.
- Light theme first; structure tokens so Dark theme can be added later.
- High contrast, restrained decoration, clear state colors.
- Prioritize customer, time, status, location, and next action.
- Avoid dense admin-dashboard styling on mobile.

Branding is not defined by the approved behavior. Use a provisional neutral + blue semantic system and keep every color/font/spacing value as pen.dev variables so branding can be replaced centrally.

## 2. Baseline frame

Primary mobile design frame: 390 x 844.
Also test responsive behavior at narrower and taller mobile frames.

Use safe-area-aware top and bottom regions. Design long pages as vertically scrollable content, with sticky primary actions only where necessary.

## 3. Variables

Create variables instead of hardcoded repeated values.

Color:
- color.bg
- color.surface
- color.surface-subtle
- color.text
- color.text-muted
- color.primary
- color.primary-on
- color.success
- color.warning
- color.danger
- color.info
- color.border
Provisional visual values may use:
- primary: #2563EB
- background: #F8FAFC
- surface: #FFFFFF
- text: #0F172A
- muted: #64748B
- success: #16A34A
- warning: #D97706
- danger: #DC2626
- border: #E2E8F0

Numbers:
- space.1 = 4
- space.2 = 8
- space.3 = 12
- space.4 = 16
- space.5 = 20
- space.6 = 24
- space.8 = 32
- radius.sm = 8
- radius.md = 12
- radius.lg = 16
- control.height = 48

Typography:
- type.body
- type.body-small
- type.label
- type.title
- type.heading
- type.metric

Use a font variable. Prefer a system/Inter-style sans serif until the product brand font is provided.
## 4. Core reusable components

Build component origins before screens.

Navigation:
- AppTopBar
- BottomNavigation
- BottomNavigationItem
- BackTopBar

Actions:
- Button/Primary
- Button/Secondary
- Button/Tertiary
- Button/Danger
- IconButton
- StickyActionBar

Inputs:
- TextField
- PasswordField
- TextArea
- SearchField
- SelectField
- QuantityInput
- Checkbox/Confirm
- SegmentedFilter

Feedback:
- InlineAlert
- StatusBadge
- Toast
- EmptyState
- ErrorState
- SkeletonRow
- ProgressIndicator
Domain components:
- TaskCard
- VisitCard
- ActiveVisitBanner
- CustomerHeader
- CustomerContactActions
- MiniMapCard
- GeofenceStatus
- GPSTrackingStatus
- VisitTimer
- VoiceNoteCard
- VoiceRecorder
- AIStatusCard
- AISuggestionBlock
- OutcomeEditor
- ProductSuggestionCard
- ProductPickerRow
- OpportunityCard
- QuotationCard
- QuotationLine
- MetricCard
- PerformanceBreakdownRow
- SalarySummaryCard
- NotificationRow
- ConversationBubble
- AttachmentRow

## 5. Component states

Buttons:
- Default
- Pressed/selected visual
- Loading
- Disabled
- Destructive where relevant

Cards:
- Default
- Actionable
- Selected
- Urgent/overdue
- Completed

Status badges must map semantic status, not use arbitrary per-screen colors.
## 6. Important composite patterns

### Active Visit Header
Contains customer, elapsed timer, GPS status.
Must remain visually dominant while visit is active.

### AI vs Confirmed Pattern
Always show clear source labels:
- AI suggestion
- Confirmed by you

Never present AI-generated outcome/products as final until confirmation.

### Quotation Line Pattern
Product/variant + quantity + unit + server price + line total.
No warehouse availability controls unless later explicitly scoped.

### Conversation Pattern
Admin and employee bubbles, sender label, timestamp, unread divider.
Message history remains chronological.

## 7. pen.dev implementation rules

- Use variables for repeated colors, spacing, radius, and typography values.
- Build reusable components and use instances in screens.
- Use slots for flexible card/list content where useful.
- Do not detach instances just to make ordinary text/content overrides.
- Name layers/components semantically for later design-to-code handoff.
- Group screens on the infinite canvas by flow: Auth, Daily Work, Visit, Sales, Employee, States.
- Keep component origins in a dedicated Design System zone/frame.
- If the system grows, move reusable components into `ierp-mobile.lib.pen`.
