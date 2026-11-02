# Release manifest (example allowlist)

Copy only these paths. Anything not listed is excluded.

| Source (repository) | Destination (server) | Notes |
|---|---|---|
| `frontend/*.html`, `frontend/admin/*.html`, `frontend/assets/**` | `public_html/` | Approved pages/assets only |
| `backend/public/api/index.php` | `public_html/api/index.php` | Only PHP file under public_html |
| `deploy/public_html.htaccess.example` (reviewed) | `public_html/.htaccess` | |
| `deploy/user.ini.example` (reviewed) | `public_html/.user.ini` | |
| `deploy/uploads.htaccess.example` (reviewed) | `public_html/uploads/products/.htaccess` | Directory writable by PHP |
| `backend/bootstrap/`, `backend/src/`, `backend/bin/`, `backend/database/migrations/` | `/home/spicemam/app/` | Private |
| production `config/local.php` (created on server, never in Git) | `/home/spicemam/app/config/local.php` | `secure_cookie` true, mode 600 |

Never copy: `Images/`, `frontend/dev/`, `frontend/mock/`, `frontend/uploads/`, `tools/`, mocks, `backend/tests/`,
`backend/config/local.php` from a developer machine, `.env*`, `.git/`, SQL dumps,
`backend/bin/dev-router.php` into public_html, documentation.

Record for each release: Git commit, files copied (with checksums), migrations
applied, backup reference, smoke-test results, approver and time (UTC).
