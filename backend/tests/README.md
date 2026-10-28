# Milestone 1 verification

Run on local PHP 8.4.27:

- PHP syntax lint: all 15 PHP files passed.
- `php backend/tests/run.php`: 48 checks passed.
- `PHP_BINARY=... python3 backend/tests/http_smoke.py`: 11 HTTP cases passed.
- Original asset SHA-256 comparison: all 16 unchanged.

Repository behavior tests use in-memory SQLite only. HTTP tests bind a temporary
loopback port and terminate the server afterward; no database credentials/config
are needed. No MariaDB migration was executed. Full OpenAPI conformance, MariaDB
10.11 SQL/locking, admin authentication/authorization, persistent throttling,
uploads and Webuzo deployment still require implementation and verification.

Implementation review during this milestone checked visibility predicates on both
list/detail, bound SQL/search escaping, safe image paths, transaction cleanup,
JSON integer/boolean/null types, private-file exclusions and no automatic schema
execution. Independent review is still required before release.
