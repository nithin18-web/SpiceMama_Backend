# Claude project instructions

Read and follow `AGENTS.md`, `README.md`, `ARCHITECTURE.md`, and `API_CONTRACT.yaml` before editing.

Claude owns both `frontend/` and `backend/`. Use `FRONTEND_HANDOFF.md` and `DESIGN_CONTENT_BRIEF.md` for UI work; use `BACKEND_HANDOFF.md`, `DATABASE_SCHEMA.md`, and `backend/README.md` for backend work. Read `DECISIONS_AND_CHECKLIST.md` and `docs/integration.md` for approvals and integration requirements.

Inspect the existing implementation and tests before proposing changes. Continue existing work; do not assume the repository is an empty scaffold. Use HTML, CSS, and vanilla JavaScript for frontend work and PHP 8.4, PDO, and MariaDB 10.11 for backend work unless the owner approves a change.

Shared API contract changes require explicit owner approval. Do not invent business facts or expose credentials. Never write to live hosting, change DNS, purchase services, create production databases, or deploy without owner approval. Preserve existing local database approval requirements. Review and test code changes before release.
