# Milestone 2 verification

Local PHP 8.4.27, MariaDB 10.11.19 (Homebrew, loopback):

- PHP syntax lint: all PHP files pass.
- `php backend/tests/run.php`: 71 checks (query/input validation, sessions, CSRF,
  JSON parsing, migration splitting; catalogue repository on in-memory SQLite).
- `DB_DATABASE=spicemama_test php backend/tests/integration.php`: 164 checks on
  MariaDB — migration runner and checksum drift, login/CSRF/session lifecycle,
  idle expiry, admin deactivation, account and client throttling with Retry-After,
  category/product CRUD, duplicate slugs, publication rules and `published_at`,
  category visibility, uploads (polyglot payload stripped, PHP/SVG/APNG/corrupt/
  oversized/pixel-bomb/forged-path rejected, no leftover files), gallery reorder/
  conflict/remove/primary promotion, audit contents, row-lock contention (503),
  SQL-injection and markup strings stored literally.
- `PHP_BINARY=... python3 backend/tests/http_smoke.py`: 58 cases through the real
  front controller — unconfigured safe errors, private-file 404s, cookie flags,
  session ID regeneration, multipart upload, served image, over-`post_max_size` 413,
  204 logout without body.

Both MariaDB suites rebuild the disposable `spicemama_test` database and refuse
other names. Not verified: Webuzo rewrite/uploads rules, hosting PHP handler,
reverse-proxy client addresses, production limits. Independent review is still required.
