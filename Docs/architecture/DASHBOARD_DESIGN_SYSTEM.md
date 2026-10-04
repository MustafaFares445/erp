# Dashboard Design System

---
status: canonical
owner: engineering
last_verified: 2026-10-04
verified_against: resources/css/filament/admin/*, app/Filament/Support/IerpColors.php, app/Providers/Filament/AdminPanelServiceProvider.php, design/employee-sales-app-v1.pen, design/customer-app-v1.pen
---

## Purpose

The Filament administration dashboard ("IERP Desktop Edition") shares one visual identity with the Employee Sales and Customer mobile apps. It is a desktop-first, dense ERP surface that reuses the apps' tokens, hierarchy and component vocabulary. It is **not** a mobile layout: sidebar, topbar, breadcrumbs, tables, filters and forms stay desktop patterns.

Visual sources: `design/employee-sales-app-v1.pen` and `design/customer-app-v1.pen` (variables `color.*`, `space.*`, `radius.*`, `font.*`).

## Architecture

| Piece | Location | Role |
| --- | --- | --- |
| Palette | `app/Filament/Support/IerpColors.php` | Registered with `->colors(IerpColors::all())`. Tailwind 3 scales, so shade 600 equals the mobile tokens exactly. |
| Tokens | `resources/css/filament/admin/tokens.css` | `--ierp-*` custom properties, light values on `:root`, dark values on `.dark`. |
| Graphite scale | `.../graphite.css` | Unlayered `html.dark` override of Filament's `--gray-*` scale, so stock `dark:*-gray-*` utilities agree with the dark tokens. |
| Chart theme | `resources/js/filament/chart-theme.js` | Chart.js plugin (registered as a Filament `Js` asset) that swaps `IerpColors::CHART_*` series colours for their dark equivalents and sets the legend colour. |
| Shell | `.../shell.css` | Canvas, typography, topbar, module switcher, sidebar, page header, language switcher. |
| Controls | `.../controls.css` | Buttons, icon buttons, fields, tabs, dropdowns, badges. |
| Surfaces | `.../surfaces.css` | Sections/cards, tables, pagination, KPI widgets, modals, notifications/callouts, empty and loading states. |
| Shared components | `.../components.css` | `ierp-*` classes for custom Blade (stepper, progress, alert, card, tile, table, status, metric, label, eyebrow). |
| Entry | `.../theme.css` | Declares the layer order and imports the partials. |

### Layer order

`theme.css` starts with `@layer theme, base, components, ierp, utilities;`. All partials except `graphite.css` (see Dark mode) are imported into the `ierp` layer, which sits **after** Filament's `components` layer and **before** Tailwind `utilities`:

- partial rules override Filament component styles without specificity hacks or `!important`;
- utility classes in custom Blade (`p-0`, `text-warning-600`, …) still win over partials;
- that first statement must stay ahead of the `@import` lines, because it fixes the order.

### Palette

| Token | Source | Value |
| --- | --- | --- |
| primary | blue 600 | `#2563EB` |
| success | green 600 | `#16A34A` |
| warning | amber 600 | `#D97706` |
| danger | red 600 | `#DC2626` |
| info | sky 600 | `#0284C7` |
| gray scale | slate | 50 `#F8FAFC` (bg), 100 `#F1F5F9` (subtle), 200 `#E2E8F0` (border), 500 `#64748B` (muted), 900 `#0F172A` (text) |

Filament's own `Color::*` constants are Tailwind 4 and noticeably more saturated; do not substitute them. Amber is **only** the warning colour.

### Typography

- Latin: Inter (Filament's bundled `Inter Variable`).
- Arabic: Noto Sans Arabic, loaded through the Vite fonts plugin (`vite.config.js`) and rendered by the panel `HEAD_END` hook. The face is limited to the Arabic unicode range, so it only downloads on pages that contain Arabic.
- Instrument Sans is used only by the public join-us page (`resources/css/app.css`), not by the dashboard.

### Scales

Spacing 4/8/12/16/20/24/32/40, radius sm 8 / md 12 / lg 16 / pill 999 (pill only for status badges and similar compact semantic elements). Desktop control height is 40px (32px compact, 28px extra compact, 44px large) instead of the apps' 48px touch target.

## Component mapping

| App component | Dashboard implementation |
| --- | --- |
| Button/Primary | solid `primary` Filament action |
| Button/Secondary | uncoloured / `outlined` action (white, hairline) |
| Button/Danger | solid `danger` action; in page headers it renders as a soft destructive button, solid red is reserved for the confirmation dialog |
| IconButton | `.fi-icon-btn` |
| TextField / SearchField / PasswordField | `.fi-input-wrp` (every Filament text-like input) |
| Checkbox/Confirm | `.fi-checkbox-input` |
| StatusBadge | `x-filament::badge` / `BadgeColumn`; badges in table cells and infolists lead with a dot, status text is always kept |
| InlineAlert | `Callout`, notifications, `.ierp-alert` |
| SegmentedFilter | `x-filament::tabs`, the module switcher |
| ProgressIndicator | `.ierp-progress`, `.ierp-stepper` |
| EmptyState / ErrorState | `.fi-empty-state`, `.ierp-empty` |
| SkeletonRow | lazy-widget placeholder (`.fi-loading-section`) |
| MetricCard | stats overview widget, `.ierp-metric` |
| TaskCard | `.fi-section`, `.ierp-card`, `.ierp-card-link`, `.ierp-tile` |

### Status colour semantics

- primary/info blue: active, in progress, sent, informational;
- success green: paid, completed, approved, delivered, healthy;
- warning amber: pending, attention, partial, awaiting;
- danger red: failed, rejected, blocked, critical overdue;
- neutral grey: draft, cancelled, inactive, archived, not started.

Never convey status by colour alone; the label text must stay.

### Page header action hierarchy

CSS in `controls.css` enforces one obvious primary per header: the first solid primary stays solid, later solid primaries (for example "Edit" next to a workflow transition) render as secondary, and danger actions render soft. Prefer expressing intent in the action definition (`->color()`), not by adding classes.

## Custom Blade views (`resources/views/filament/**`)

- Use native Filament components first (`x-filament::section`, `input.wrapper`, `button`, `badge`).
- Do not use `<input class="fi-input …">` outside `x-filament::input.wrapper`: `fi-input` is borderless by design and renders as an unstyled field.
- Do not put Blade directives such as `@js()` inside a component tag attribute; Blade compiles component tags before directives. Compute the value in `@php` and echo it.
- Use the `ierp-*` classes instead of hand-rolled `rounded-xl border … shadow …` combinations.
- Avoid hard-coded colours. Standalone asset stylesheets (`resources/css/filament/*.css`) may use `var(--ierp-*)`; they load on the same pages as the theme.
- Chart.js needs literal colours: use `IerpColors::CHART_*` (mirrors the light tokens; `chart-theme.js` swaps them in dark mode). Use `CHART_PRIMARY` for ordinary series; semantic colours only where the colour carries meaning.

## Dark mode

Components only read `--ierp-*` tokens, so dark mode is the `.dark` token set in `tokens.css`; avoid per-component `dark:` rules in partials. Utility-class views still need explicit `dark:` variants.

Dark mode is an independent **graphite neutral** palette, not the light palette darkened and not Tailwind slate: no surface, border or text colour carries a blue hue. Blue is an accent only (active navigation, primary actions, selected tabs, links, focus rings, the primary chart series, informational status). Test: with every primary-blue element removed, the dark UI must still look neutral.

| Role | Light | Dark |
| --- | --- | --- |
| page (`--ierp-bg`) | `#F8FAFC` | `#0B0D10` |
| sidebar / topbar (`--ierp-chrome`) | `#FFFFFF` | `#101216` |
| card (`--ierp-surface`) | `#FFFFFF` | `#15181D` |
| hover (`--ierp-surface-hover`, `--ierp-control-hover`) | `#F8FAFC` / `#F1F5F9` | `#1A1E24` |
| subtle / tracks (`--ierp-surface-subtle`) | `#F1F5F9` | `#1D2128` |
| input (`--ierp-input`) | `#FFFFFF` | `#111419` |
| table header (`--ierp-table-head`) | `#F8FAFC` | `#171A20` |
| border / strong / hover | `#E2E8F0` / `#CBD5E1` / `#94A3B8` | `#2A3038` / `#363D47` / `#4B5563` |
| text / secondary / muted | `#0F172A` / `#475569` / `#64748B` | `#F4F6F8` / `#A7AFBA` / `#7C8592` |
| primary / soft | `#2563EB` / `#EFF6FF` | `#3B82F6` / `rgb(59 130 246 / 14%)` |

Rules that follow from the palette:

- Cards separate from the page by surface contrast plus a hairline border; dark shadows are off (`--ierp-shadow-xs/sm` are `0 0 #0000`). Only popovers, dropdowns and modals keep a shadow.
- Active navigation, tabs and selected rows use the translucent primary-soft fill with `--ierp-primary-on` (`#60A5FA`) text, never a solid blue block.
- Fields use `--ierp-input` with a `--ierp-border-strong` hairline; focus is a `--ierp-primary` border with a 20% ring (`--ierp-field-focus-ring`).
- Semantic badges are translucent fills (12%) with light text tones (`--ierp-*-on`: `#60A5FA` info, `#4ADE80` success, `#FBBF24` warning, `#F87171` danger, `#A7AFBA` neutral); badge text colours are set in `controls.css`, not taken from Filament's per-shade defaults.
- `graphite.css` is the one partial imported **without** a layer: Filament emits its colour scales as an unlayered `:root` block, and an unlayered declaration beats every layered one, so only an unlayered `html.dark` rule can re-point `--gray-*`. Light mode keeps the slate scale.
- Charts: grid `--ierp-chart-grid`, tick labels `--ierp-chart-axis`, legend `--ierp-chart-legend`, read through Filament's `.fi-wi-chart-*-color` probes. Series colours are re-mapped by `chart-theme.js` (primary `#3B82F6`, previous/comparison `#68717D`, success `#22C55E`, warning `#F59E0B`, danger `#EF4444`, info `#38BDF8`); keep its `DARK_SERIES` table in sync with `IerpColors::CHART_*`. After changing the script run `php artisan filament:assets` (the published copy in `public/js/app/` is committed).

## RTL

- Use logical properties (`padding-inline`, `inset-inline-start`, `border-inline-end`, `text-align: start/end`) in CSS.
- `letter-spacing` is reset to `normal` in RTL (`shell.css`): tracking breaks Arabic letter joining.
- Directional glyphs in copy (`→`, `←`) are text; mirror them explicitly in markup when they indicate direction.

## Responsive behaviour

Desktop first, verified at 1440, 1280, 1024 and 768 px:

- the module strip shows from 90rem (1440px); below that it collapses into the module dropdown; module icons appear from 104rem;
- the sidebar collapses behind the topbar toggle below `lg`;
- tables scroll horizontally rather than hiding columns.

## Verification

Run `npm run build` and the relevant Pest tests. Visual QA: Sales, CRM, Accounting, Inventory, Purchasing, Employees, Support dashboards, quotation/order/ticket detail pages, reports and settings, in light, dark, LTR and RTL.
