# Deployment boundary and planned mapping

Documentation only. No release scripts, routing configuration or live changes exist.
Owner approval is required before connecting to hosting, creating databases,
changing HTTPS/DNS/settings, executing production migrations or deploying.

Planned mapping:
- frontend public pages/assets/admin UI -> /home/spicemam/public_html/
- future backend/public/api/index.php -> /home/spicemam/public_html/api/index.php
- future backend source/bootstrap/config/vendor -> /home/spicemam/app/
- secrets, sessions, logs, upload staging -> private locations outside public_html
- generated product images -> /home/spicemam/public_html/uploads/products/

Build a reviewed allowlist manifest; never copy the whole repository to web root.
Exclude originals Images/, frontend/dev/, mocks, .env, source/config, tests, SQL,
Git metadata and dependencies from public output. Retain composer.lock privately.
Public upload directory must deny script execution and listing on actual server.

Before release, verify PHP 8.4 extensions and MariaDB 10.11, private include paths,
rewrite rules, fileinfo/GD, memory/POST limits, permissions, HTTPS/certificate renewal,
cache exclusions for admin/session APIs, quotas, backup schedule and tested restore.
Do not assume advertised plan features are account verification or Git push deploys.
Future runbook must specify backups, migration review, maintenance window if needed,
file/database compatibility, rollback, safe health checks and post-release smoke tests.
