# SpiceMama backend — milestone 2

All 16 operations in root `API_CONTRACT.yaml` (unchanged, v1.0.0) are implemented
and tested locally against MariaDB 10.11. Not release-ready: hosting checks,
owner security decisions and independent review are still required. No live
hosting, production database, real catalogue or production credentials were used.

## Request flow

`public/api/index.php` → `Http\Kernel` (routing) → public `CatalogueController`
(no session) or admin controllers → `Middleware\AdminGuard` (session 401, then
CSRF 403 on non-GET) → `Validation\AdminInput` (400/415/422) → repositories
(PDO prepared statements, transactions, product-row locks) with audit rows in the
same transaction → JSON `{data,meta}` / `{error:{code,message,fields}}`.

| Area | Code |
|---|---|
| Routing, request/response | `src/Http/{Kernel,Request,Response}.php` |
| Sessions, CSRF, login throttling | `src/Services/{SessionSecurity,NativeSession,LoginThrottle}.php`, `src/Middleware/AdminGuard.php` |
| Catalogue data | `src/Repositories/{Product,Category,Image,Admin}Repository.php`, `ProductRows.php` |
| Publication rules | `src/Services/PublicationRules.php` |
| Uploads | `src/Services/{ImageProcessor,ImageStore}.php` |
| CLI | `bin/migrate.php`, `bin/create-admin.php`, `bin/maintenance.php` |

## Behaviour notes

- Login: pre-login CSRF; attempts counted before password check under row locks
  (account bucket 5 / 15 min, client bucket 20 / 15 min, 15 min block, provisional
  pending owner review); constant-cost check for unknown emails; session ID
  regenerated and CSRF rotated on success. Deactivated admins lose sessions at once.
- Products are created as drafts; publishing (and editing while published) enforces
  active category, nonblank short/full description and one image with alt text.
  `published_at` is set once. Category deactivation hides products only.
- Uploads: 5 MiB cap and PHP `post_max_size` → 413; MIME by content (JPEG/PNG/WebP)
  → 415; dimension, pixel, animation, corrupt-data and memory checks → 422; GD
  re-encode in private staging (strips metadata and appended payloads, EXIF
  orientation is not applied), random name, then DB insert under lock with file
  compensation on failure. Deletion unlinks after commit; leftovers are reported by
  `bin/maintenance.php`.
- Gallery reorder/remove/upload/publish lock the product row then its images.
  Lock timeouts/deadlocks return 503 (transaction rolled back; reload and retry).
- Missing records on `PATCH /admin/categories/{id}` and
  `POST /admin/products/{id}/status` return 404 `not_found` (contract lists no 404
  for these two; flagged for owner review rather than changing the contract).

## Local setup (this Mac)

PHP 8.4: `/opt/homebrew/opt/php@8.4/bin/php` (not globally linked).
MariaDB 10.11 (Homebrew, keg-only), loopback only, not a login service:

```sh
/opt/homebrew/opt/mariadb@10.11/bin/mysqld_safe --datadir=/opt/homebrew/var/mysql --bind-address=127.0.0.1 &
```

Disposable databases `spicemama_local` and `spicemama_test` with user
`spicemama_local@127.0.0.1` exist; credentials live only in the gitignored
`config/local.php` (copy `config/local.example.php` to recreate elsewhere).
Sessions and upload staging use `backend/storage/` (gitignored); uploads go to
`frontend/uploads/products/` (gitignored) so the dev router serves them.

From repository root:

```sh
PHP=/opt/homebrew/opt/php@8.4/bin/php
$PHP backend/bin/migrate.php            # dry run; add --apply to execute
$PHP backend/bin/create-admin.php --email=you@example.test --name="Your Name"
$PHP backend/bin/seed-dev.php             # LOCAL ONLY: sample catalogue (skips existing slugs)
$PHP -d display_errors=0 -S 127.0.0.1:8080 backend/bin/dev-router.php
```

`display_errors=0` matters: PHP prints oversized-POST warnings before any script runs.

## Tests

```sh
$PHP backend/tests/run.php                                        # 71 unit checks, SQLite fixtures
DB_DATABASE=spicemama_test $PHP backend/tests/integration.php     # 164 MariaDB checks
PHP_BINARY=$PHP python3 backend/tests/http_smoke.py               # 58 HTTP cases
PHP_BINARY=$PHP node tools/e2e-live.mjs                           # 49 browser checks, frontend + API
```

Integration and HTTP suites drop and rebuild `spicemama_test` and refuse any
database not ending in `_test`. See `tests/README.md` for coverage.

## Production mapping

Public entry point → `public_html/api/index.php`; bootstrap, src, bin, migrations
and config → `/home/spicemam/app` with `SPICEMAMA_APP_PATH` set by the server.
See `deploy/README.md` for templates, manifest and runbook. Do not deploy without
owner approval.

## Open before release

Owner decisions: throttle thresholds, audit/throttle retention, admin users,
`post_max_size`, HTTPS redirect. Hosting checks: fileinfo/GD WebP, rewrite and
uploads rules, `SetEnv` visibility, reverse proxy (`REMOTE_ADDR`), permissions,
backups. Independent security review.
