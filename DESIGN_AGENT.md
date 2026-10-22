# Design & UI/UX Agent

Standing design guidelines for this repository. Read this before any UI/UX
work on the dashboard (new views, redesigns, component work, styling
changes). It defines the brand identity and the non-negotiable constraints
that every design change must respect.

## 1. Professional UI/UX standards

- Deliver modern, polished, professional UI/UX - this is a tool people use
  to compare real ad performance, not a prototype.
- Follow standard best practices: clear visual hierarchy, deliberate
  whitespace, intuitive navigation, responsive layouts that work down to
  phone width, accessible contrast ratios (WCAG AA minimum against the
  backgrounds in section 3), and clear interactive feedback - hover/active
  states on every clickable element, and explicit loading/empty states
  rather than a silently blank area.

## 2. Preserve prior architectural & visualization decisions (CRITICAL)

- Do **not** override or replace the project's core data-visualization
  decisions.
- The dashboard's metrics/analytics must keep being shown as **tables**
  (industry totals, account/campaign drilldowns) and **box plots** (per-
  campaign metric distribution by industry, via Chart.js +
  chartjs-chart-boxplot). These were deliberate choices made and verified
  earlier in the project (see PLANNING_LOG.md) - they are not up for
  redesign.
- Any design work styles and elevates these existing representations
  (spacing, color, type, interaction polish) - it never replaces a table
  with cards, or a box plot with some other chart type, without the user
  explicitly asking for that specific change.

## 3. Brand identity & color palette

| Role | Hex |
|---|---|
| Primary Blue | `#0600FF` |
| Accent Orange | `#FF9100` |
| Success Green | `#02B77F` |
| Cyan / Light Blue | `#04A5BA` |
| Purple Accent | `#5B148B` |
| Dark Navy / Base Text | `#19095B` |

Backgrounds: `#FFFFFF` (primary) / `#F8FAFC` (secondary, subtle panels).
Maintain high contrast for text and interactive elements over both.

## 4. Typography

- Primary font family: **Montserrat**, sans-serif.
- Allowed weights: **Light 300** and **Bold 700** only - no other weights.
  Use 300 for body copy, 700 for headings/emphasis; don't reach for
  italics or additional weights to create hierarchy.

## 5. Logo & visual rules

- Brand: **Intren** - this is the dashboard's own product/tool identity
  (the team building and operating this internal tool), not an advertiser
  client. Logo asset: `public/images/brand-logo.png`.
- Maintain generous clear space around the logo; never stretch, recolor,
  or rotate it.
- Maintain appropriate spacing and high contrast when placed over light
  backgrounds (`#FFFFFF` / `#F8FAFC`).

## 6. Strict compliance

- Follow the privacy mandates in `CLAUDE.md` without exception: never
  expose a real advertiser/client/partner name anywhere in the repo or UI.
  The Intren brand identity in this file governs the dashboard's own
  chrome (logo, header, colors, typography) - it must never be used to
  label or identify any advertiser account's data. Advertiser accounts
  stay under their existing anonymized labels (`real-account-N`,
  `Education Partner A/B`, `Professional Services Partner A/B`,
  `Health Partner A`) everywhere in the data views, exactly as required by
  CLAUDE.md.
- Build UI with modular Tailwind CSS utility classes and Laravel Blade
  components going forward, rather than growing the existing page's
  hand-rolled inline `<style>` block further. (The current
  `resources/views/dashboard.blade.php` predates this guideline and isn't
  rewritten wholesale by it alone - migrate it incrementally, or when
  explicitly asked for a redesign, not as a side effect of unrelated
  changes.)
