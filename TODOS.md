# TODOs

Deferred work, one entry per item. Added by the launch-copy plan review (2026-11-14); see `docs/designs/launch-copy.md`.

## E3 — Deliver invitation emails for real
- **What:** Configure a real mailer and a running queue worker so `UserInvited` reaches inboxes.
- **Why:** `MAIL_MAILER=log` and `QUEUE_CONNECTION=database` mean invitation emails are only written to the application log today; the copy-paste invite message is the only working channel.
- **Pros:** Colleagues get the invite without the admin pasting it into chat.
- **Cons:** Needs mail credentials and an always-on worker process.
- **Context:** Credentials go in `.env` only, never in tracked files. Set `APP_URL` for the worker's environment. Invite links are built from the admin's request host, so create invites from the production host (behind a proxy, configure trusted proxies or `force_https_urls` so links use https). Before starting the worker, inspect or clear `UserInvited` jobs queued before the launch-copy change (they fall back to the generic text, but should be checked). Then send one test invite end to end.
- **Effort:** human S / CC S
- **Priority:** P2
- **Depends on:** mail credentials from the project owner.

## D-T3 — Land deep links on real data first
- **What:** A dashboard anchor or a Real-first view so a deep link does not open on the blended chart.
- **Why:** The copy tells readers to ignore the chart, yet the deep link lands on it; the copy compensates with "listed below the chart".
- **Pros:** First impression matches the instructions.
- **Cons:** Changes the dashboard layout, which DESIGN_AGENT.md protects; needs an explicit design decision.
- **Context:** URL fragments do not survive the sign-in redirect, so a server-side option is needed.
- **Effort:** human M / CC S
- **Priority:** P3
- **Depends on:** first real use by a colleague.

## T6 — Real/Synthetic guidance inside the drill-down
- **What:** A short static note in the drill-down explaining which rows to trust.
- **Why:** Guidance belongs where the decision happens, not only on the login page.
- **Pros:** Helps every visitor, not just new ones.
- **Cons:** New reader-facing dashboard copy, outside the agreed copy surfaces.
- **Context:** Raised by the independent CEO review; deferred because it changes an agreed premise.
- **Effort:** human S / CC S
- **Priority:** P3
- **Depends on:** first real use by a colleague.

## T7 — Monthly per-industry snapshot pushed to colleagues
- **What:** Send a monthly CPC/CTR summary (Real accounts only) into the channel PPC managers already use.
- **Why:** Removes the need for colleagues to come to the dashboard at all.
- **Pros:** Reaches people who never open the tool.
- **Cons:** New channel to build and maintain; must respect the no-single-client-figures rule.
- **Context:** Alternative raised by the independent CEO review; revisit if the copy-paste invite gets no use.
- **Effort:** human M / CC S
- **Priority:** P3
- **Depends on:** outcome of the first invites.
