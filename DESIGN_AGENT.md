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
  client. Logo assets:
  - `public/images/brand-logo.png` - full-color landscape logo, for light
    backgrounds.
  - `public/images/logo_fekvő-09.png` - white/light landscape variant, for
    dark backgrounds. Swapped in automatically via a `prefers-color-scheme:
    dark` media query (two `<img>` tags, one hidden per mode) - no
    JavaScript, since the page has no other dark-mode toggle mechanism to
    hook into.
- Maintain generous clear space around the logo; never stretch, recolor,
  or rotate it.
- Maintain appropriate spacing and high contrast when placed over light
  backgrounds (`#FFFFFF` / `#F8FAFC`) or dark backgrounds (`#140B3C`,
  section 6).

## 6. Dark-mode palette (ratified 2026-10-26)

Activated via `prefers-color-scheme: dark` (the dashboard has no manual
theme toggle). Not a reinterpretation of the light palette - several
tokens are distinct colors chosen for dark-background legibility, not
just a lightened/darkened version of the light-mode hex.

| Token | Light | Dark | Notes |
|---|---|---|---|
| `--bg` | `#F8FAFC` | `#140B3C` | Page background. Dark variant is a navy tone, not black. |
| `--card` | `#FFFFFF` | `#1D1154` | Panel/card background. |
| `--fg` | `#19095B` (Dark Navy) | `#F2F1FA` | Primary text. |
| `--muted` | `#6E6B85` | `#B5B2CC` | Secondary text. |
| `--line` | `#E2E8F0` | `#352A73` | Borders/dividers. |
| `--accent` | `#0600FF` (Primary Blue) | `#7C83FF` | Links, primary actions, selected-industry link, chart accent. Dark variant is a lighter periwinkle - the literal Primary Blue is too dark to read against the dark navy background. |
| `--success` | `#02B77F` (Success Green) | `#34d3a8` | Real-account badge, button `:active`. |
| `--purple` | `#5B148B` (Purple Accent) | `#C090E0` | Synthetic-account badge. Dark variant is a lighter lavender for the same reason as `--accent`. |
| `--warn` | `#FF9100` (Accent Orange) | `#FF9100` | Notice banners. Same hex in both modes - orange already reads clearly on both the light and dark backgrounds, so it doesn't need a dark-specific variant. |
| `--err` | `#D7263D` | `#FF6B81` | Error banners. Not one of the 6 core brand colors (none of them read as "error") - a crimson chosen to sit comfortably next to Purple Accent and Accent Orange rather than a generic red. Dark variant is lighter for legibility on the dark navy background. |
| `--nav-inactive` | `#19095B` (same as `--fg`) | `#94A3B8` | Inactive industry-link color (section on Industry Selection below). Deliberately **not** the same navy as `--fg` in dark mode - navy-on-navy would be unreadable against the `#140B3C` background, so inactive links get their own muted slate-blue instead of reusing the Dark Navy brand text color. |

**Industry selection/navigation**: the currently-selected industry link is
always `--accent` (bright blue) bold; every inactive industry link is
`--nav-inactive`. In light mode this is literally Dark Navy per the brand
palette; in dark mode it's the muted slate-blue above, not navy, for the
contrast reason noted in the table.

**Chart colors**: the box plot (Chart.js) reads `--chart-accent`,
`--chart-fill`, `--chart-grid`, `--chart-tick`, `--chart-median` via
`getComputedStyle` at render time rather than hardcoding hex per mode in
JavaScript - these mirror `--accent`/`--fg` and exist as separate tokens
only because the chart needs an rgba() fill and a couple of
chart-specific shades (grid lines, tick labels) that the UI chrome
doesn't otherwise use. A palette change only needs to happen in the CSS
`:root` blocks; the JavaScript never hardcodes a color.

## 7. Strict compliance

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
