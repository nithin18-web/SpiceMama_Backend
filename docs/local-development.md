# Local development status update

Backend milestone 2 is implemented. Read [backend/README.md](../backend/README.md)
for current runnable commands. With owner approval (2026-11-01), MariaDB 10.11 was
installed via Homebrew and runs on 127.0.0.1 only (not as a login service); the
disposable databases spicemama_local and spicemama_test exist and the initial
migration was applied to them. The .env file is not automatically loaded; private
configuration uses the gitignored config/local.php.

## Run the whole site (frontend + API) on one address

The frontend from `nithin18-web/SpiceMama_Frontend` was copied into `frontend/`
(and its preview helpers into `tools/`) on 2026-11-01. From the repository root:

```sh
/opt/homebrew/opt/php@8.4/bin/php -d display_errors=0 -S 127.0.0.1:8080 backend/bin/dev-router.php
```

Open http://127.0.0.1:8080/?api=live to use the real API (choice kept per tab);
`?api=mock` switches back to the in-browser sample data in `frontend/mock/`.
Admin: create a local account once with `backend/bin/create-admin.php`.
`node tools/serve.mjs` still serves the frontend alone (mock mode only).

Live browser test (headless Chrome, disposable spicemama_test database):

```sh
PHP_BINARY=/opt/homebrew/opt/php@8.4/bin/php node tools/e2e-live.mjs
```

The notes below are historical.

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
