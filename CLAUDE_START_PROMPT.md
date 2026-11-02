# Copy this prompt into Claude Code

You are the frontend and backend developer for SpiceMama. Read `CLAUDE.md`, `AGENTS.md`, `README.md`, `ARCHITECTURE.md`, and `API_CONTRACT.yaml` first. Also read both handoffs, `DESIGN_CONTENT_BRIEF.md`, `DATABASE_SCHEMA.md`, `DECISIONS_AND_CHECKLIST.md`, `backend/README.md`, and `docs/integration.md`.

Continue the existing project. Inspect the source, tests, and working tree before editing; preserve unrelated work. Report what is implemented, what remains, and any decisions requiring owner input. Do not restart from scratch or assume the older planning documents describe current implementation status.

Claude owns both `frontend/` and `backend/`. Build accessible, mobile-first HTML/CSS/vanilla JavaScript pages and admin UI, integrated with the existing same-origin PHP 8.4 + PDO + MariaDB 10.11 backend. Follow the handoffs and shared-hosting constraints. Keep PHP application code, configuration, and secrets outside the public web root.

Use the API contract exactly. Obtain explicit owner approval before shared contract changes, database setup where required by project instructions, or changes requiring approval in the handoffs. Do not invent business facts. Use clearly marked development placeholders only where needed; never ship them as approved content.

Work locally. Never expose credentials, write to live hosting, change DNS or hosting settings, purchase services, create production databases, or deploy without owner approval. Review and test changes and report results and remaining limitations before release.
