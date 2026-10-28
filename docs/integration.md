# Frontend/backend integration agreement

## Ownership and current status

Claude owns `frontend/`; Codex owns `backend/`. Root `API_CONTRACT.yaml` is the
single API source. Root architecture, handoffs and database documents are shared.
Backend milestone 1 now supplies public catalogue APIs and a local router; see
backend/README.md. No MariaDB database or migration runner exists, and admin
endpoints remain unimplemented. Claude still owns frontend implementation.
Do not deploy the repository wholesale as public_html.

## Contract change record: draft to 1.0.0

- Added GET /admin/products/{id}.
- Added PATCH/DELETE /admin/images/{id} and PUT /admin/products/{id}/images/order.
- Split create/patch schemas, defined required fields, bounds and unknown-key rejection.
- Added admin pagination/filtering, exact category-slug filtering, search and stable order.
- Defined first image as primary, preserving existing ProductImage response fields.
- Defined active-category visibility, explicit slugs, archive workflow and publication checks.
- Defined pre-login CSRF, post-login token refresh, cookie/session lifecycle and errors.
- Defined single-file 5 MiB ceiling plus stricter server and pixel limits.

These are development rules, not business approvals. No prices, claims, stock,
cart, orders, payments, contact submissions or settings APIs have been added.
The handoff's health endpoint is deferred to operations design; no undocumented
frontend health call should be introduced.

## Integration sequence after implementation approval

1. Claude builds mocks from YAML and the frontend API guide. Codex later implements
   the same operations, JSON types, error codes and status codes.
2. Review sample request/response fixtures together before connecting UI to backend.
3. Serve frontend and PHP behind one local origin. Avoid file://, cross-origin CORS
   workarounds, and persistent mock fallback when a real API call fails.
4. Use only a dedicated disposable local MariaDB 10.11 database after explicit local
   setup approval. Never point tests at hosting or production credentials.
5. Verify public filtering, deterministic pagination and draft/archived slug 404s.
6. Verify session/CSRF refresh, category visibility, draft editing, publishing,
   upload validation, reorder conflicts, image removal and archiving end to end.
7. Check 204 handling, non-JSON failures, timeouts and reconciliation after ambiguous writes.
8. Review accessibility, content approval, security tests and release exclusions.

## Assets and release boundaries

`Images/` contains 16 immutable originals. Separately named optimized copies may
be created under frontend/assets/images with an original-to-copy mapping; none
are generated in this task. Logo, product range and packaging versions still need
owner confirmation. Mocks and placeholder content must not ship in production.

No release automation, live connection, DNS change or database provisioning is
authorized by this foundation. Deployment needs separate owner approval.
