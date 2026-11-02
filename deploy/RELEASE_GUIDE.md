# SpiceMama first release — VapourHost (Webuzo, no SSH) + GoDaddy DNS

Server `157.20.105.124`, account `spicemam`, home `/home/spicemam`, LiteSpeed, PHP 8.4, MariaDB 10.11.
Build first: `PHP=/opt/homebrew/opt/php@8.4/bin/php sh deploy/build-release.sh` → `dist/spicemama-<commit>/`.
Every step that changes the server or DNS needs the owner's go-ahead at that moment.
Passwords are typed only by the owner and never sent in chat.

## 0. Before starting
- [ ] Webuzo → Applications → Backuply: note the latest backup date (weekly host backups exist).
- [ ] Record today's GoDaddy DNS for rollback: `A @ 13.248.243.5`, `A @ 76.223.105.230`, `CNAME www → spicemama.in`.

## 1. Upload the site (File Manager)
1. Webuzo → Server Utilities → **File Manager** → open `/home/spicemam`.
2. **Upload** `spicemama-site.zip` into `/home/spicemam` (not into public_html).
3. Right-click the zip → **Extract** into `/home/spicemam`. Result:
   - `/home/spicemam/public_html/` — pages, assets, `api/index.php`, `.htaccess`, `uploads/products/`
   - `/home/spicemam/app/` — private code (never inside public_html)
4. Delete `spicemama-site.zip` from the server.

## 2. Database (owner)
1. Database → **Add Database**: name `site` (Webuzo shows it as `spicemam_site`).
2. Database → **Add Database User**: name `app`, use the password generator, store the password in your password manager.
3. Database → **Add User To Database**: user `spicemam_app` → database `spicemam_site`, privileges **SELECT, INSERT, UPDATE, DELETE** only.

## 3. Private config (owner)
1. File Manager → `/home/spicemam/app/config` → copy `production.example.php` to `local.php`.
2. Edit `local.php`: replace the three `CHANGE_ME` values (database name, user, password). Save.
3. Change permissions of `local.php` to `600`.

## 4. Tables and products (phpMyAdmin)
1. Database → **phpMyAdmin** → select `spicemam_site` on the left.
2. **Import** → `setup/01-schema.sql` → Go. Expect 8 tables.
3. **Import** → `setup/02-catalogue.sql` → Go. Expect 4 categories, 12 products, 12 product_images.

## 5. Server check (temporary file)
1. Upload `setup/server-check.php` into `/home/spicemam/public_html/`.
2. Open it via the private preview (step 6) — every line must say `OK`.
3. **Delete** `server-check.php` immediately.

## 6. Private preview (before DNS changes)
From the developer Mac, requests are sent straight to `157.20.105.124` with `curl --resolve` and a
headless Chrome using `--host-resolver-rules` (the placeholder certificate is accepted only there).
Check: home and catalogue load 12 products, product pages show photos, `/api/v1/categories` is 200,
`/app/`, `/api/../app/config/local.php`, `/uploads/products/x.php` are not served, admin pages load.

## 7. Go live: GoDaddy DNS (owner logs in; change needs explicit go-ahead)
1. GoDaddy → My Products → spicemama.in → **DNS**.
2. If the domain is connected to a GoDaddy Website Builder site, disconnect it there first (it owns the A records).
3. Replace both `A @` records with a single `A @ → 157.20.105.124` (TTL 600). Keep `CNAME www → @`.
4. Wait until `dig +short spicemama.in` returns `157.20.105.124`.

## 8. Padlock (immediately after step 7)
1. Webuzo → SSL → **Automatic SSL** → issue for `spicemama.in` and `www.spicemama.in`.
2. Verify `https://spicemama.in` and `https://www.spicemama.in` show a valid certificate (until this
   finishes, browsers that remember GoDaddy's HSTS header show a certificate warning).
3. Uncomment the HTTPS redirect lines in `public_html/.htaccess`.

## 9. After launch
- Smoke test: 12 products, photos, contact buttons, 404 page, `http://` → `https://`.
- Admin account (later): needs a one-time Cron Job running `bin/create-admin.php`, since there is no SSH.

## Rollback
- Site problem: restore the two original `A @` records in GoDaddy (step 0); the old page returns.
- Files: restore `/home/spicemam` from Backuply. Database: drop and re-import steps 4.1–4.3.
