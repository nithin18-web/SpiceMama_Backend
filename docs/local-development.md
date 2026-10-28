# Local development status update

Backend milestone 1 is now implemented. Read [backend/README.md](../backend/README.md)
for current runnable commands and limitations. The original foundation notes below
are historical: the local router, public APIs, composer.json and review-only SQL
now exist. No MariaDB database or migrations have been executed. The .env file is
not automatically loaded; current private configuration uses config/local.php and
exported environment variables. Admin endpoints remain unavailable pending their
implementation/testing. PHP 8.4 was installed locally; no persistent PHP service
was started.

---

# Local development foundation

## Available now

Only directory scaffolding, API definitions and documentation exist. There is no
Composer project, PHP entry point, router, migration runner or SQL to execute yet.
Do not expect API requests to succeed. No hosting access is needed for this stage.

Claude can serve `frontend/` with an existing local static preview server after
adding frontend pages and explicit development mock transport. Do not use file://
for API integration. Keep preview bound to loopback; do not publish mock content.

## Prerequisites for later approved backend development

- PHP 8.4 CLI and web runtime; PDO/pdo_mysql, sessions, mbstring, fileinfo, GD with
  JPEG/PNG/WebP support; OpenSSL for deployment. Check CLI and web modules separately.
- MariaDB 10.11 for integration tests; InnoDB and utf8mb4_unicode_ci.
- Composer 2 for locally assembled dependencies; deployment must not require it
  to be installed on hosting. Commit composer.lock when dependencies are chosen.
- A private writable storage directory and separate generated product-upload path.

Read-only runtime checks, where installed: `php -v`, `php -m`, `composer --version`,
`mariadb --version`. Missing tools are prerequisites, not a reason to use live hosting.
No tools/dependencies have been installed by this foundation.

After backend implementation approval, copy backend/.env.example to backend/.env
and replace placeholders with dedicated local settings. No dotenv loader is yet
implemented. Never expose .env via a static server or commit real credentials.
Database creation and migrations are separate future actions; this guide does not
execute them or grant production provisioning approval.

## Future same-origin development setup

A development-only router will map /api/v1 to the PHP front controller and other
paths to frontend files. Its exact command will be documented when implemented;
none exists now. PHP's built-in server is local tooling, not the production server.
Secure cookies require HTTPS in production. An explicit local-only exception may
allow HTTP on 127.0.0.1; never carry that exception into deployment.

Use isolated test storage and a disposable local test database, UTC timestamps,
and synthetic labelled fixtures. Run PHP lint, contract conformance and MariaDB
integration/security tests once executable code exists. Record actual versions,
commands and results then; no application tests can pass at scaffolding stage.
