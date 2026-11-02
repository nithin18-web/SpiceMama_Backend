# Deployment boundary and planned mapping

Documentation and reviewed templates only. No release scripts or live changes exist.
Templates: `public_html.htaccess.example`, `uploads.htaccess.example`,
`user.ini.example`, `release-manifest.example.md` — all unverified on Webuzo.
Owner approval is required before connecting to hosting, creating databases,
changing HTTPS/DNS/settings, executing production migrations or deploying.

Planned mapping:
- frontend public pages/assets/admin UI -> /home/spicemam/public_html/
- future backend/public/api/index.php -> /home/spicemam/public_html/api/index.php
- future backend source/bootstrap/config/vendor -> /home/spicemam/app/
- secrets, sessions, logs, upload staging -> private locations outside public_html
- generated product images -> /home/spicemam/public_html/uploads/products/

Build a reviewed allowlist manifest; never copy the whole repository to web root.
Exclude originals Images/, frontend/dev/, frontend/mock/, tools/, mocks, .env, source/config, tests, SQL,
Git metadata and dependencies from public output. Retain composer.lock privately.
Public upload directory must deny script execution and listing on actual server.

Before release, verify PHP 8.4 extensions and MariaDB 10.11, private include paths,
rewrite rules, fileinfo/GD, memory/POST limits, permissions, HTTPS/certificate renewal,
cache exclusions for admin/session APIs, quotas, backup schedule and tested restore.
Do not assume advertised plan features are account verification or Git push deploys.
Future runbook must specify backups, migration review, maintenance window if needed,
file/database compatibility, rollback, safe health checks and post-release smoke tests.

## Runbook (draft — every step needs owner approval at release time)

1. **Pre-flight (read-only):** confirm PHP 8.4 with pdo_mysql, mbstring, fileinfo and
   GD (JPEG/PNG/WebP); MariaDB 10.11; HTTPS certificate validity/renewal; effective
   `post_max_size`; writable `public_html/uploads/products`; whether a reverse proxy
   sits in front of PHP (if so, every client shares one `REMOTE_ADDR` and the login
   client bucket needs review before release).
2. **Backup:** take and verify a database + files backup; record its reference.
3. **Database:** create a least-privilege runtime user (SELECT/INSERT/UPDATE/DELETE)
   and a separate migration user (adds CREATE/ALTER/INDEX/REFERENCES). Write
   `/home/spicemam/app/config/local.php` on the server (mode 600, `secure_cookie`
   true, random 64-hex `throttle_key`, private `storage_path`).
4. **Migrate:** `php bin/migrate.php` (dry run), review, then `--apply` with the
   migration user.
5. **Files:** copy the manifest allowlist into a new release directory, then switch.
6. **Admin:** `php bin/create-admin.php --email=... --name=...` once per approved admin.
7. **Smoke tests:** `GET /api/v1/categories` 200; `/api/v1/admin/me` 401; a `.php`
   placed in uploads is not executed; `config/`, `.env`, `.git` return 403/404;
   admin login over HTTPS shows a `Secure; HttpOnly; SameSite=Lax` cookie.
8. **Rollback:** switch back to the previous release directory. Schema changes are
   forward-only; restoring data uses the verified backup and needs separate approval.
9. **Maintenance:** `php bin/maintenance.php` (report) on a schedule;
   `--delete-orphans` / `--purge-throttles` once retention is approved.
