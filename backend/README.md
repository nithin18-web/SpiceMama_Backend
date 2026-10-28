# SpiceMama backend — milestone 1

Local implementation started; not release-ready. Root API_CONTRACT.yaml is
unchanged. No live hosting connection, persistent database, real catalogue or
production credentials were used.

## Implemented

- PHP 8.4 front controller, small namespace autoloader, safe JSON errors/headers.
- Lazy PDO MySQL connection with native prepared statements, UTC and repeatable-read.
- GET /api/v1/products, /products/{slug}, /categories; published + active-category
  filtering, deterministic pagination/gallery order, literal parameterized search.
- Strict query parsing/validation; rejects duplicate keys, arrays and malformed input.
- Session/CSRF primitives with idle/absolute expiry, secure cookie defaults.
- Proposed MariaDB SQL; no migration runner or automatic schema execution.
- Local same-origin router that only serves approved frontend file types, never
  repository docs, secrets, backend PHP or original Images/.

## Not yet implemented

All admin endpoints (including login and CSRF issuance) return safe 404 while this
milestone is incomplete. Login, persistent throttling, authorization middleware,
product/category mutations, audit writes, upload processing, image management,
admin provisioning and deployment rules remain future milestones. Session helpers
alone do not provide admin authentication. No health route is added to the contract.

## Run local checks

Use PHP 8.4; on this development Mac it is available at
`/opt/homebrew/opt/php@8.4/bin/php` (not globally linked).

From repository root:

```sh
/opt/homebrew/opt/php@8.4/bin/php backend/tests/run.php
PHP_BINARY=/opt/homebrew/opt/php@8.4/bin/php python3 backend/tests/http_smoke.py
```

The 48 initial checks use disposable in-memory SQLite fixtures for repository
behavior; SQLite is a development-test dependency only. They do not validate
MariaDB syntax, locking or production hosting. No database files are written.
Composer is optional for this milestone: runtime dependencies are PHP extensions
only, and bootstrap/autoload.php works without vendor/. A lockfile has not been
created because Composer dependency installation has not been run.

Serve on loopback from repository root (local only):

```sh
/opt/homebrew/opt/php@8.4/bin/php -S 127.0.0.1:8080 backend/bin/dev-router.php
```

Successful database-backed requests require the next approval gate below. Until
configured, public endpoints return safe 503 JSON; invalid requests are validated
before connection, and unknown/admin routes return 404 without database access.
Frontend pages are owned by Claude and may not exist yet.

## Configuration and next approval gate

Review database/migrations/001_initial_schema.sql and its README. No SQL was
executed. Owner approval is required before executing it, including local MariaDB
integration setup under the current migration-review agreement.

Once authorized and a dedicated local database exists, copy config/local.example.php
to config/local.php (ignored), export DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/
DB_PASSWORD to the PHP process, and run the local server. No .env parser is used;
copying .env.example to .env alone does not configure the application. Never put
production credentials in local config or expose config/ via a web server.

Production mapping: public API entry point goes in public_html/api; bootstrap,
config and src stay in /home/spicemam/app. Server configuration must set
SPICEMAMA_APP_PATH to that private directory. Rewrite and non-executable upload
rules require later Webuzo verification. Do not deploy this milestone.

## Review notes

Local tests cover validation, public visibility, parameterized search, rollback,
response types and session primitives. MariaDB 10.11 behavior, concurrency, live
rewrite rules and extension/resource limits remain unverified. Review before
release; independent review and full security/integration tests are still needed.
