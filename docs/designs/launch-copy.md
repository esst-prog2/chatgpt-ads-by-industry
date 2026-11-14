# Design: Launch copy for PPC managers (login page + invitation message)

Status: APPROVED (/office-hours 2026-11-14; /autoplan CEO, design and eng reviews 2026-11-14, gate approval p1a)
Branch: main
Repo: esst-prog2/chatgpt-ads-by-industry

This is the consolidated spec. It contains only current, approved requirements; superseded and withdrawn instructions from the review rounds are removed. The full review history (decision audit trail, reviewer findings, test plan) is kept outside the repo in the gstack project folder.

## Problem

The ChatGPT Ads Industry Dashboard works, but only its builder uses it. The goal is to get the first 2-3 PPC managers at the agency to open it for a real client media plan. No colleague has asked for these numbers yet, so the page's job is to recruit first users, not to promote broadly.

- **Status quo:** PPC managers run a small test budget on ChatGPT Ads without platform-specific estimates and adjust after 1-2 weeks. The tool sits alongside that test budget; it never claims to replace it.
- **First user:** PPC Manager A, with an upcoming ChatGPT Ads plan for a Professional Services client. That industry has 2 real accounts, below the Rule of 3, so its chart and totals still include synthetic data.
- **Demand:** unverified ("No direct ask yet"). The Assignment conversation (below) tests it before any code change.

## Constraints

- The app is internal: everything except `/login` and the Google OAuth routes requires sign-in.
- Privacy (CLAUDE.md): no external client or partner names, client data or personal email addresses in tracked files. The agency's own name (Intren), brand and email domain are allowed.
- DESIGN_AGENT.md: tables and box plots are not redesigned. Reader-facing copy changes only on the login page and in the invitation message.
- What the dashboard shows: only the account rows in an industry's drill-down carry a Real/Synthetic tag; campaigns are listed under each account in that account's own currency. The box plot and "Industry totals" are untagged and include synthetic data for any industry below the Rule of 3. The copy may only point readers at what's tagged.
- Claude never commits. Each step's changes are left uncommitted; the user reviews and commits them.

## Login page (`resources/views/auth/login.blade.php`)

**Order:** job, action, how, honesty. The sign-in button sits directly under the subhead.

**Layout:** one left-aligned column inside the existing card, `max-width:480px`, padding 40px (24px under 480px wide), logo centered above (logo and its `alt` text unchanged). Headline Montserrat 700, body Montserrat 300 at 16px minimum, `--fg` on `--card`. Steps are an ordered list with numbers in `--accent`. The Coming next box uses a 1px `--line` border on a `--bg` background with `--muted` text. The button keeps the existing `.google-btn` style and focus ring and is visible without scrolling at 375×667 and 1280×800, including when an `error` flash is shown. Existing errors (expired sign-in, unauthorized Google account) render above the button with their current wording. A signed-in visitor opening `/login` is redirected to the dashboard by the existing `guest` middleware.

**Copy:**
- **Eyebrow:** Internal tool · ChatGPT Ads beta
- **Headline:** Planning ChatGPT Ads for a client?
- **Subhead:** See what real campaigns on the platform pay, by industry, before you set the test budget.
- **Button:** Sign in with Google
- **Heading:** How to use it
  1. **Pick your client's industry.** The dashboard opens on the last 28 days; that industry's accounts and campaigns are listed below the chart.
  2. **Use only campaigns under accounts marked Real.** The chart and totals above them can include synthetic stand-in data.
  3. **Note the CPC and CTR of those real campaigns before you size the first test budget,** then compare your test against them after week one. CPCs are in each account's own currency; the dashboard footer shows the HUF rate.
- **Coming next box:** Coming next: a one-line industry benchmark you can paste into a plan, shown once your client's industry has a third real account.
- **Fine print:** Sign in with your company Google account. No client names are shown.
- No account or industry counts on this unauthenticated page.

## Invitation message

### Shared builder (`app/Notifications/InviteMessage.php`)

`InviteMessage::for(UserRole $role, ?string $industry)` returns the email parts (subject, plain-text lines, action text, absolute action URL) and a chat variant. Its only inputs are role and industry. `UserAccessController::store()` calls it once during the request and passes the result to both the flash and the queued notification, so both carry the same absolute URL.

- **Role label:** a new `UserRole::label()` returns "Viewer" or "Admin". The chat variant, email Line 1 and the existing `status` flash all use it, never the raw enum value.
- **Link:** `url()->query('/', array_filter(['industry' => $industry, 'metric' => 'cpc'], fn ($v) => $v !== null))`. Industry names containing `&` are URL-encoded; no `industry` key appears when none was picked. Links use the admin's request host, so invites are created from the production host.

### Chat variant: the primary invite channel

Until a real mailer exists (TODOS.md E3), the admin sends this text via Slack, Teams or a direct message. Wording, exactly:

- With an industry: "You now have {Role} access to the ChatGPT Ads Industry Dashboard; it shows what live ChatGPT Ads campaigns pay, by industry. Before you size a {industry} client's first test budget, open this and note the CPC and CTR of campaigns under accounts marked Real, listed below the chart: {url}"
- Without an industry, the second sentence is: "Before you size a client's first test budget, open their industry and note the CPC and CTR of campaigns under accounts marked Real, listed below the chart: {url}"

### Email (`app/Notifications/UserInvited.php`)

With today's `MAIL_MAILER=log` and `QUEUE_CONNECTION=database`, this email only reaches the application log. No success criterion depends on it.

- **Subject:** Planning ChatGPT Ads for a client? See what real campaigns pay
- **Greeting:** Hi,
- **Line 1:** You now have **{Role}** access to the ChatGPT Ads Industry Dashboard. It shows what live ChatGPT Ads campaigns pay, by industry, from accounts we manage.
- **Line 2:** Use it before you set a client's first test budget: open their industry and note the CPC and CTR of the campaigns under accounts marked **Real**. CPCs are in each account's own currency; the dashboard footer shows the HUF rate.
- **Button:** "Open {industry} on the dashboard" when an industry was picked, otherwise "Open the dashboard".
- **Line 3:** Coming next: a one-line benchmark you can paste straight into a media plan, once your client's industry has a third real account.
- **Line 4 (only when an industry was picked):** If the dashboard opens without {industry} selected, pick it from the industry list.
- **Closing (unchanged):** If you weren't expecting this, you can ignore this email.

`UserInvited` keeps its constructor (`UserRole $role`) and gains a non-readonly `public ?array $message = null` holding the prebuilt message. Jobs queued before this change unserialize with `null` and fall back to today's generic text and `/login` link. `toMail` adds the bold around the role.

## Admin page (`resources/views/admin/index.blade.php`, `UserAccessController`)

### Industry list (`app/Services/RealIndustries.php`)

`RealIndustries::list()` is the existing `$realIndustries` computation from `AppServiceProvider::register()` (Real sources' ids through the CSV + database base mapping, plus database account industries), extracted into a singleton service. The `DashboardService` factory uses it for its `$newIndustries`, so there is one implementation. It does not build the `Aggregator`, so the admin page never triggers the exchange-rate fetch. It returns industries with at least one Real account, intersected with `Industries::onboardable()` (so OTHER and stray stored values are excluded), in `Industries::ALL` order. A Real account with no recent records still counts.

### Invite form

- An optional "Client industry" select, fed by `RealIndustries::list()` and passed to the view as `inviteIndustries` (the existing `industries` variable stays the ads-account list). After a validation error it keeps the old value.
- Empty list: the select stays enabled with only the blank option, and "No industry has real data yet" appears as helper text under the field.
- `store()` validates the field as `nullable` plus `Rule::in($realIndustries->list())`; anything else is a validation error with no flash. A late click on an industry that has since lost its last Real account falls back to the dashboard without a drill-down (accepted).
- A one-line comment notes that `unifiedAccounts()` keeps its own per-account industry display.

### Invite message block

Directly under the existing `status` flash at the top of the users panel (`invite_message` flash, next page load only):
- A visible label "Message to send" and a read-only, full-width textarea (4 rows) holding the chat variant, rendered with `{{ }}`.
- A Copy button (existing button style, at least 44px tall). On success its text becomes "Copied" for 2 seconds, announced through an `aria-live="polite"` region. When the clipboard API is missing or rejects (always on a plain-HTTP host), the click focuses and selects the textarea and shows "Press Ctrl+C / Cmd+C to copy". Nothing moves focus on page load. The JavaScript goes in the existing `<script>` block.
- The note "Copy it now: it disappears when you leave this page."

## Sign-in tracking

- **Migration:** nullable `users.first_login_at` and `users.last_login_at`; `User` casts both to datetime.
- **Listener (`app/Listeners/RecordSignIn.php`, auto-discovered):** handles `Illuminate\Auth\Events\Login` for both Google callback sign-ins and remember-cookie sessions. It sets `last_login_at` to now on every login and `first_login_at` only while null, writing with `forceFill()->saveQuietly()`. "Last sign-in" means the last session start, not the last request.
- **Failures are visible (user decision d10b):** the listener does not catch its own errors. A failed write shows an error page and Laravel's exception handler logs it. Because Laravel saves the session before firing `Login`, the person is still signed in on their next click; only that session's sign-in time is lost.
- **Display:** one Blade component formats both timestamp styles. The desktop users table gets one "Sign-ins" column with "First: YYYY-MM-DD" and "Last: YYYY-MM-DD HH:MM UTC" (the app timezone is UTC); when null it shows "—" plus the visually hidden sentence "No sign-in recorded since tracking began". The stacked list (under 680px) shows "First sign-in: YYYY-MM-DD" and "Last sign-in: YYYY-MM-DD HH:MM UTC" on separate lines, or that full sentence when null.

## Step order

Claude makes each step's changes and leaves them uncommitted; the user reviews and commits each step.

1. TODOS.md (already written: E3, D-T3, T6, T7).
2. **The Assignment conversation** with PPC Manager A, before any code change. Ask: "What CPC would you budget for this client on ChatGPT Ads today?" and "How did you pick that number?" Write the answers down in their words; if they describe the job differently, rewrite the headline in their words. **Stop rule:** if A would not look at these numbers for this plan, stop after step 3 and revisit steps 4-5.
3. Login copy and layout.
4. `RealIndustries`, `UserRole::label()`, `InviteMessage`, the invite form, the message block and the `UserInvited` changes.
5. Sign-in tracking. The migration runs before this code deploys.

## Tests

Existing tests stay green, except the one deliberate update: the old email-subject assertion at `tests/Feature/AuthTest.php:505` changes to the new subject. `test_the_invitation_notification_mentions_the_granted_role` stays as the legacy-path test.

New tests, with a fixture that uses "Education & Careers" with one Real database `AdsAccount`, `config(['ads.accounts' => []])`, and a `refreshDashboardService()` helper in `tests/TestCase.php` (forgets the `DashboardService` and `RealIndustries` singletons) after every account change:
- **Login page:** the sign-in link comes before "How to use it"; the fine print is present and "Access is by invitation" is absent.
- **Industry list:** OTHER and stray values are excluded; order follows `Industries::ALL`; an empty list gives `[]` and a select with only the blank option plus helper text; the admin page renders with the exchange-rate provider unbound; with a mixed `.env` + database fixture, `RealIndustries::list()` equals the industries the dashboard shows with a Real account.
- **Invite:** storing with "Education & Careers" flashes a chat message whose link decodes to that industry and `metric=cpc`, with no "**"; the rendered mail has the identical link and Line 4; storing without an industry gives "Open the dashboard" and `/?metric=cpc`; an invalid industry or "Other" gives a validation error and no flash; the select keeps its old value after an error; exact strings for both chat variants; an Admin invite shows "Admin" in the chat text, the email and the status message; a builder-path twin of the role test.
- **Queued jobs:** a legacy job captured from `serialize(new SendQueuedNotifications(...))` against a seeded user renders without error.
- **Deep link:** requested while signed out it redirects to `/login`; after the faked Socialite callback, `intended()` lands on the dashboard with "Education & Careers" selected and `metric=cpc`. A stale-industry test removes the Real account after a valid invite and checks the link renders without a drill-down or error.
- **Sign-in tracking (real guard, never `actingAs`):** the callback sets both fields for a new and for a pre-created user; a second sign-in keeps `first_login_at`; a remember-cookie request (explicit `remember_token`, encrypting `withCookie`) sets `last_login_at` and `Auth::check()` is true; `Event::assertListening(Login::class, RecordSignIn::class)`; a schema test asserts both columns exist; the admin view shows "First:", "Last:", "UTC" and the hidden sentence in both blocks.

## Manual checklist

- Login layout at 375×667 and 1280×800, light and dark, with and without an error flash.
- Both Copy states, on localhost and on a plain-HTTP host.
- Drill-down account labels carry no client names.
- Deploy: run migrations before the code; rebuild the event cache if the deploy uses `event:cache`/`optimize`; afterwards sign in once and check that "First sign-in" shows today.
- Create invites from the production host.

## Success criteria

- PPC Manager A (or the next PPC manager with a ChatGPT Ads plan) opens the dashboard for a real client plan within 2 weeks of being invited: confirmed by asking them, plus a `first_login_at` inside that window.
- At least 2 colleagues besides the builder sign in within a month, measured by `first_login_at` inside that month. Industry views are not recorded in v1.
- The login page, dashboard and email contain no external client or partner names.
- Every instruction in the copy points at something the drill-down actually shows.

## Not in scope (see TODOS.md)

Real mailer and queue worker (E3); a dashboard anchor or Real-first view after the deep link (D-T3); Real/Synthetic guidance inside the drill-down (T6); a monthly per-industry push digest (T7).

## Language

English only, matching the app. Hungarian localization is not planned (decided 2026-11-14).
