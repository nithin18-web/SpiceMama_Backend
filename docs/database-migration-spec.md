# Database migration specification — not executable SQL

Status: proposed implementation specification. No SQL files, database connection,
migration execution or production resources are created. Exact migration SQL
still requires owner review/approval as required by DATABASE_SCHEMA.md.

## Common conventions

Target MariaDB 10.11, InnoDB, utf8mb4_unicode_ci; no MySQL-8-only collations/features.
IDs: BIGINT UNSIGNED AUTO_INCREMENT, matching unsigned foreign keys. Constrain API
IDs to 1..9007199254740991 for JavaScript precision. Dates: DATETIME(6), UTC from
application/connection; required created_at/updated_at except where listed nullable.
Booleans: constrained 0/1. sort_order INT NOT NULL DEFAULT 0 CHECK >=0.
Text limits enforced by backend validation (character counts), not only SQL type.
Slugs ASCII lowercase hyphen-separated, unique within products/categories; explicit
input, no silent generation. API nullability/defaults must match this document.

## Ordered migration specifications

1. **schema_migrations**: version VARCHAR(100) PK, checksum CHAR(64) NOT NULL,
   applied_at DATETIME(6) NOT NULL. Migration runner rejects changed applied checksums.
2. **admins**: id; email VARCHAR(254) NOT NULL UNIQUE (application trims/normalizes
   consistently); password_hash VARCHAR(255) NOT NULL; display_name VARCHAR(120)
   NOT NULL; role ENUM('admin') NOT NULL DEFAULT 'admin'; is_active BOOLEAN NOT NULL
   DEFAULT 1; last_login_at nullable; created_at/updated_at required. No seeded admin.
3. **categories**: id; name VARCHAR(120) and slug VARCHAR(160), NOT NULL, slug UNIQUE;
   description TEXT NULL DEFAULT NULL; sort_order default 0; is_active default 0;
   created_at/updated_at required. API description max 10000 characters.
   Index (is_active,sort_order,id).
4. **products**: id; category_id NOT NULL FK categories ON DELETE RESTRICT;
   name VARCHAR(180), slug VARCHAR(200) UNIQUE NOT NULL; short_description VARCHAR(500)
   NOT NULL DEFAULT ''; description TEXT NOT NULL (application default empty string);
   pack_size_label VARCHAR(100) NULL; ingredients TEXT NULL; status
   ENUM('draft','published','archived') NOT NULL DEFAULT 'draft'; sort_order default 0;
   published_at nullable; created_at/updated_at required. Description/ingredients
   max 10000 characters. Index (status,category_id,sort_order,id),
   (status,sort_order,id), (status,published_at), plus category FK index as required.
5. **product_images**: id; product_id NOT NULL FK products ON DELETE RESTRICT;
   file_path VARCHAR(255) NOT NULL UNIQUE, generated relative storage key only;
   alt_text VARCHAR(255) NOT NULL DEFAULT ''; sort_order default 0;
   is_primary BOOLEAN NOT NULL DEFAULT 0; width/height positive INT UNSIGNED NOT NULL;
   mime_type VARCHAR(60) NOT NULL; file_size_bytes BIGINT UNSIGNED NOT NULL;
   created_at required. Index (product_id,sort_order,id). API order is authoritative:
   is_primary is internal derived metadata, maintained with product-row locking.
   Empty gallery has no primary; nonempty gallery has exactly one, the first image.
6. **admin_audit_logs**: id; admin_id nullable FK admins ON DELETE SET NULL;
   action VARCHAR(100), entity_type VARCHAR(80) NOT NULL; entity_id BIGINT UNSIGNED
   NULL (polymorphic, no entity FK); details_json JSON NULL; created_at required.
   Index (admin_id,created_at), (entity_type,entity_id,created_at).
   Allowlist changed-field summaries; exclude credentials, session/CSRF tokens,
   raw request bodies and unnecessary personal data.
7. **login_throttles**: proposed persistent operational table. bucket_key CHAR(64)
   PK (HMAC of normalized account or trusted client address with private key);
   attempts INT UNSIGNED NOT NULL DEFAULT 0; window_started_at required;
   blocked_until nullable; updated_at required. Index (updated_at).
   Atomic counters/locks prevent concurrent bypass; apply account and client
   buckets separately. Proxy headers trusted only with explicit proxy configuration.
   Thresholds, HMAC-key storage/rotation and cleanup policy require security review
   before implementation. Never store passwords or raw attempted account/IP values.
8. **site_settings**: setting_key VARCHAR(120) PK, setting_value TEXT NOT NULL,
   updated_at required. Reserved public-safe content only; no API or seeded business
   values in phase 1. No secrets.

## Transaction and lifecycle rules

- Create products as drafts. No permanent product/category deletion workflow.
- Public lookup/list requires published product plus active category, including search.
- First successful publication sets published_at; unpublish/archive/republish retain it.
- Publishing requires nonblank short/full descriptions, active category and a gallery
  with at least one nonblank image alt text. Published edits preserve these rules.
- Category deactivation hides products without altering their status; reactivation
  restores eligible products. This exception does not erase publication history.
- Gallery reorder submits all current IDs exactly once. Lock product then gallery;
  reject stale/foreign sets with 409; normalize sort_order to 0..n-1 atomically.
- Lock same product for upload/remove/reorder/publish to preserve gallery invariants.
- Block removing final image of published product; promote next image on primary removal.
- DB/filesystem operations cannot share one transaction. Validate/re-encode in private
  staging, move to generated public path, commit metadata/audit, compensate failed
  writes. Failed file cleanup must be recorded/retried via bounded maintenance tooling.
  Never interpret user input as a deletion path; never target repository Images/.
- Use transactions for business changes and their audit records; avoid cascade loss.

## Migration review and verification gate

Future SQL must include exact CREATE/ALTER statements, index names, checks and
rollback/restore instructions. Test on disposable MariaDB 10.11: constraints,
UTF-8 text, duplicate slugs, FK restrictions, defaults, transaction rollback,
concurrent gallery changes, auth throttling and public visibility.
MariaDB DDL can implicitly commit; do not claim transaction-only schema rollback.
Take/verify backups before an approved live migration; prefer forward fixes and
compatible releases. Destructive rollback always needs separate approval.
Retention for audit/throttle data and operational cleanup remain pre-implementation
security decisions. No fabricated production seed content or default passwords.
