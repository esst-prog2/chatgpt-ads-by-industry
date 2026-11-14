@AGENTS.md

## Strict rules - privacy & workflow

### Anonymization & privacy
- Never record, log, or commit any private data anywhere in this repository (codebase, comments, logs, documentation, tests, or env examples) - not just real client and partner names, but also personal data such as real email addresses, including the project owner's own.
- Exception (2026-11-14 - see PLANNING_LOG.md): our own agency's name (Intren), brand and email domain may appear in documentation, files and commits. The anonymization rules in this section apply to external clients and partners: their real data, company names, and real email addresses (including in tests). "It's my own data" is not an exception: nothing private belongs in a tracked file, since anything tracked is available on GitHub. Real secrets/config values (API keys, personal emails used for a named exception, etc.) belong only in .env (gitignored), referenced from tracked code via env() with an empty/placeholder default - never hardcoded as a literal default value in a tracked file. Tests exercise the mechanism with a synthetic placeholder, never the real value.
- Always use the standardized anonymized labels already established for this project: `real-account-N`, `Education Partner A`, `Education Partner B`, `Professional Services Partner A`, `Professional Services B`, `Health Partner A`.
- Before committing, always verify no real entity names, personal emails, company suffixes (Kft., Zrt., LLC, Ltd., GmbH, Inc., etc.), or other identifying/private data have been introduced - including inside a note that documents fixing a previous leak; never repeat the leaked value itself when describing its own redaction.

### Commits and pushes
- Never commit (changed 2026-11-14 - see PLANNING_LOG.md; this replaces the 2026-11-13 commit-after-each-step rule). Make each step's changes, leave them uncommitted in the working tree, and list what changed; the user reviews everything and commits manually.
- The user keeps PROCESS.md (one row per commit they make). Decisions are still logged in PLANNING_LOG.md as uncommitted edits.
- Pushing to a remote is unchanged and still requires the user's explicit approval, given after they've had the chance to review the proposed changes - show the proposed changes first and wait for explicit go-ahead before pushing.
