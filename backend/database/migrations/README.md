# Migration review package

`001_initial_schema.sql` is proposed MariaDB 10.11 SQL, not executed or approved
for execution. It creates eight tables in an already selected database, without
creating a database, inserting users/content or connecting to hosting. No migration
runner or application-startup migration exists.

Review against root DATABASE_SCHEMA.md and docs/database-migration-spec.md.
The SQL uses ASCII binary slugs/paths (other content utf8mb4_unicode_ci), matching
unsigned foreign keys, restricted catalogue deletion,
boolean checks and the agreed pixel bounds. Primary-image and publication rules
require application transactions and product locking; they are not guaranteed
by this schema alone. JavaScript-safe IDs must be checked by the application;
auto-increment columns are not given CHECK constraints. Application validation enforces text length and slug rules.

Approval requested next: review this exact SQL, then authorize disposable local
MariaDB 10.11 creation and execution for integration testing. Production execution
requires separate approval, verified backup and least-privilege credentials.

Future runner must record version/checksum and UTC applied_at after success,
reject changed applied migrations, and detect partial application. Do not silently
use IF NOT EXISTS to conceal drift. MariaDB DDL cannot be rolled back with a normal
transaction. If partially applied, inspect and repair the disposable local database;
for production use an approved forward fix or tested backup restore. No destructive
down migration is included. A live release needs reviewed file/schema compatibility
and restore instructions before approval.
