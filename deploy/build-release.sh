#!/bin/sh
# Builds dist/spicemama-<commit>/ for VapourHost (no SSH):
#   spicemama-site.zip   extract inside /home/spicemam  -> public_html/ (public) + app/ (private)
#   setup/               01-schema.sql, 02-catalogue.sql (phpMyAdmin), server-check.php (temporary)
# Needs a clean Git tree and the local database running (catalogue is exported from it).
# Usage: PHP=/opt/homebrew/opt/php@8.4/bin/php sh deploy/build-release.sh
set -eu
cd "$(dirname "$0")/.."
PHP=${PHP:-php}
[ -z "$(git status --porcelain)" ] || { echo "Commit or stash changes first: releases are built from a commit." >&2; exit 1; }
SHA=$(git rev-parse --short HEAD)
OUT="dist/spicemama-$SHA"
rm -rf "$OUT"
STAGE="$OUT/stage"
mkdir -p "$STAGE/public_html/api" "$STAGE/public_html/uploads/products" "$STAGE/app/config" "$OUT/setup"

# Public web root: approved pages and assets only (no mocks, dev files or local uploads).
git archive HEAD frontend | tar -x -C "$STAGE"
mv "$STAGE/frontend/"* "$STAGE/public_html/"
rm -rf "$STAGE/frontend" "$STAGE/public_html/mock" "$STAGE/public_html/dev" "$STAGE/public_html/uploads/products/"*
find "$STAGE/public_html" -name .gitkeep -delete
git show HEAD:backend/public/api/index.php > "$STAGE/public_html/api/index.php"
git show HEAD:deploy/public_html.htaccess.example > "$STAGE/public_html/.htaccess"
git show HEAD:deploy/uploads.htaccess.example > "$STAGE/public_html/uploads/products/.htaccess"

# Private application (outside public_html): no tests, dev router, local config or seeds.
git archive HEAD backend/bootstrap backend/src backend/bin backend/database/migrations | tar -x -C "$STAGE"
mv "$STAGE/backend/"* "$STAGE/app/"
rm -rf "$STAGE/backend" "$STAGE/app/bin/dev-router.php" "$STAGE/app/bin/seed-dev.php"
find "$STAGE/app" -name .gitkeep -delete
git show HEAD:backend/config/production.example.php > "$STAGE/app/config/production.example.php"

# Launch catalogue: SQL for phpMyAdmin plus the referenced, already re-encoded photos.
"$PHP" deploy/export-catalogue.php "$OUT/setup" | while read -r f; do
    [ -n "$f" ] && cp "frontend/uploads/products/$f" "$STAGE/public_html/uploads/products/"
done
git show HEAD:deploy/server-check.php > "$OUT/setup/server-check.php"

(cd "$STAGE" && zip -qrX "../spicemama-site.zip" public_html app)
rm -rf "$STAGE"
echo "Built $OUT"
ls -l "$OUT" "$OUT/setup"
