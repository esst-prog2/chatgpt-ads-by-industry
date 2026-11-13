@AGENTS.md

## Strict rules - privacy & workflow

### Anonymization & privacy
- Never record, log, or commit any private data anywhere in this repository (codebase, comments, logs, documentation, tests, or env examples) - not just real client, partner, or company names, but also personal data such as real email addresses, including the project owner's own. "It's my own data" is not an exception: nothing private belongs in a tracked file, since anything tracked is available on GitHub. Real secrets/config values (API keys, personal emails used for a named exception, etc.) belong only in .env (gitignored), referenced from tracked code via env() with an empty/placeholder default - never hardcoded as a literal default value in a tracked file. Tests exercise the mechanism with a synthetic placeholder, never the real value.
- Always use the standardized anonymized labels already established for this project: `real-account-N`, `Education Partner A`, `Education Partner B`, `Professional Services Partner A`, `Professional Services B`, `Health Partner A`.
- Before committing, always verify no real entity names, personal emails, company suffixes (Kft., Zrt., LLC, Ltd., GmbH, Inc., etc.), or other identifying/private data have been introduced - including inside a note that documents fixing a previous leak; never repeat the leaked value itself when describing its own redaction.

### Commits and pushes
- Commit after each step, with no separate review-and-approve pause first (changed 2026-11-13 - see PLANNING_LOG.md; the previous rule required explicit approval before every commit). One step = one commit.
- After each step's commit, add one row to PROCESS.md: the step, what the tool did, and what the user decided.
- Pushing to a remote is unchanged and still requires the user's explicit approval, given after they've had the chance to review the proposed changes - show the proposed changes first and wait for explicit go-ahead before pushing.
