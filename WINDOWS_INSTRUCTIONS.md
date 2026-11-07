# SpiceMama — continuing on Windows

Handover written 2026-11-03 when moving from the original Mac to a Windows laptop.
Read this first, then `CLAUDE.md`. Work happens on branch **`feature/full-site-v1`**.

This repository is **public**. Never commit passwords, the server config file, Webuzo
session links, the pre-launch preview code, or anything from `dist/` (it is gitignored).

---

## 1. What this project is

Brand and product-catalogue website for **Spice Mama** (Andhra Standard Products, Guntur).
No cart or prices in phase 1. Plain HTML/CSS/JavaScript frontend + PHP 8.4 API + MariaDB 10.11.

| Folder | What |
|---|---|
| `frontend/` | Public pages, admin screens (`frontend/admin/`), assets, `maintenance.html` (pre-launch page with admin Launch button), `mock/` (browser-only sample data, never deployed) |
| `backend/` | PHP API (`src/`), CLI tools (`bin/`), migrations, tests, config templates |
| `deploy/` | Release builder, phpMyAdmin exporter, `.htaccess` templates, `RELEASE_GUIDE.md` |
| `tools/` | Headless-Chrome end-to-end test, nav/footer copier |
| `Images/` | 16 original owner photos — **never edit**; derived web copies live in `frontend/assets/images/` (see `SOURCES.md` there) |
| Root `*.md`, `API_CONTRACT.yaml` | Project rules, contract (v1.0.0, authoritative), architecture, decisions |

The same frontend also exists in the separate repo `nithin18-web/SpiceMama_Frontend`, but **this
repo is the newest and complete copy** (that repo lacks the Diwali page and later fixes).

## 2. Where things stand (2026-11-03)

### Done
- All 16 API operations (public catalogue + admin: login, categories, products, publishing, photo
  upload/reorder/describe/remove), sessions + CSRF, login throttling, audit log, migrations, CLI tools.
- Frontend updated for contract 1.0.0; mock mode matches the backend.
- Tests: 72 unit, 164 MariaDB integration, 58 HTTP, 49 headless-Chrome checks — all passing on the Mac.
- **Live server set up** on VapourHost and **the domain points to it**:
  - `https://spicemama.in` serves the full site with 12 products (4 categories, includes test product "Test1").
  - Let's Encrypt certificate (Webuzo Automatic SSL) valid to 1 Feb 2027, auto-renews.
  - Private files, `error_log` and fake uploads are blocked; API and photos verified.

### Done on 2026-11-04 (live)
- New pack photos + hero text fix uploaded; the 11 products use the new photos (`deploy/pack-photo-update.php`).
- **Diwali pre-launch page is live**: visitors see "Opening this Diwali" until 00:00 IST Sun 8 Nov 2026;
  owner preview via the private `?preview=` link (kept out of Git); `http://` redirects to `https://`;
  HTML pages are sent with `Cache-Control: no-cache` so nobody sees stale pages around launch.
- **Live admin account exists** (created via phpMyAdmin) and login is confirmed.
- Note: the host firewall briefly blocks an IP that sends many requests quickly (~5 min). Keep checks gentle.

### Built on 2026-11-07 (upload package `dist/live-update-2026-11-07/`, Mac only)
- New pack mockups, family artwork, recipe squares, Three Mango Mirchi Powder (first product).
- **Launch button replaces the countdown**: the pre-launch page shows artwork and a public "Launch the website"
  button (owner decision: anyone may press it) → `POST /api/v1/launch` creates `public_html/.launched`
  → fireworks 4 s → home page. `POST /api/v1/admin/launch` (admin session) still works as a fallback.
  `.htaccess` now checks that file instead of a time. See section 5.
- Must be uploaded **before 00:00 IST Sun 8 Nov 2026**, otherwise the old timer rule opens the site by itself.

### Pending — in this order
1. **Launch event (8 Nov 2026):** follow section 5 "Launch day". Archive "Test1" before launching.
2. **Merge** `feature/full-site-v1` into `main` here, and `feature/hero-and-diwali` into `main` in
   `SpiceMama_Frontend` (or retire that repo — this one is the complete copy).
3. **Content (owner):** remove/archive "Test1" in admin; confirm the 4 placeholder categories (Chilli powders,
   Spices, Masalas, Flours); WhatsApp number (`frontend/assets/js/config.js` → `business.whatsapp`);
   privacy policy and terms; founding story; real recipe photos. Do not publish claims such as
   "no preservatives" or "100% ethically sourced" unless the owner confirms them.
4. **Optional:** admin "change password" screen (today passwords are changed via a hash in phpMyAdmin);
   better pounding animation needs the pack designer's layered artwork.
5. **Housekeeping:** log out of Webuzo after each session (its URLs contain a login session); delete the 11 old
   product photo files listed at the end of the pack-photo SQL from `public_html/uploads/products/`.

## 3. Set up this laptop (Windows)

Install (PowerShell as administrator for `winget`):
```powershell
winget install Git.Git
winget install OpenJS.NodeJS.LTS
winget install Python.Python.3.12
winget install Google.Chrome
```
- **PHP 8.4:** download "VS17 x64 Thread Safe" zip from https://windows.php.net/download/ → extract
  to `C:\php` → copy `php.ini-development` to `php.ini` → in `php.ini` set `extension_dir = "ext"` and
  uncomment `extension=curl`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite` →
  add `C:\php` to PATH. Check: `php -v` shows 8.4 and `php -m` lists those extensions.
- **MariaDB 10.11 (LTS):** MSI from https://mariadb.org/download/ (choose 10.11). Set a root password,
  install as a service. Client: `"C:\Program Files\MariaDB 10.11\bin\mariadb.exe"`. It listens on
  127.0.0.1:3306; do not open it to the network.

Get the code:
```powershell
git clone https://github.com/nithin18-web/SpiceMama_Backend.git spicemama
cd spicemama
git checkout feature/full-site-v1
```

Local databases (disposable; choose your own password, keep it out of Git):
```sql
-- mariadb -u root -p
CREATE DATABASE spicemama_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE spicemama_test  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'spicemama_local'@'127.0.0.1' IDENTIFIED BY 'choose-a-local-password';
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES,DROP ON spicemama_local.* TO 'spicemama_local'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES,DROP ON spicemama_test.*  TO 'spicemama_local'@'127.0.0.1';
```

Private config (gitignored): copy `backend\config\local.example.php` to `backend\config\local.php`
and set, using **absolute paths** (forward slashes work on Windows):
- `database`: host `127.0.0.1`, port `3306`, database `spicemama_local`, user `spicemama_local`, your password
- `storage_path`: e.g. `C:/Users/<you>/spicemama/backend/storage`
- `upload_path`: e.g. `C:/Users/<you>/spicemama/frontend/uploads/products`
- `throttle_key`: any 64 random hex characters (or leave empty — it is then generated in storage)
- `secure_cookie`: `false` (allowed for loopback only)

Create tables, sample catalogue and a local admin:
```powershell
php backend\bin\migrate.php --apply
php backend\bin\seed-dev.php
php backend\bin\create-admin.php --email=you@example.test --name="Your Name"
```
(On Windows the password you type is visible — it is a local test account only.)

Run the site (frontend + API on one address):
```powershell
php -d display_errors=0 -S 127.0.0.1:8080 backend\bin\dev-router.php
```
Open `http://127.0.0.1:8080/?api=live` (real local API) or `?api=mock` (sample data in the browser;
the tab remembers the choice). Admin: `http://127.0.0.1:8080/admin/`. Pre-launch page:
`http://127.0.0.1:8080/maintenance.html`.

Tests (the MariaDB suites wipe and rebuild `spicemama_test` only):
```powershell
php backend\tests\run.php
$env:DB_DATABASE="spicemama_test"; php backend\tests\integration.php; Remove-Item Env:DB_DATABASE
$env:PHP_BINARY="php"; python backend\tests\http_smoke.py
$env:CHROME_BINARY="C:\Program Files\Google\Chrome\Application\chrome.exe"; node tools\e2e-live.mjs
```

## 4. How the live site is built

| Item | Value |
|---|---|
| Host | VapourHost, control panel **Webuzo** (log in from the VapourHost client area); account `spicemam`, home `/home/spicemam` |
| Server | `157.20.105.124`, LiteSpeed, PHP 8.4, MariaDB 10.11, memory 64M, upload cap 8M |
| Domain | Registered at **GoDaddy** (renewals there). Nameservers are VapourHost's `ns15`/`ns16.vhostplatform.com`, so **DNS records are edited in Webuzo → Domain → DNS Zone Settings**, not GoDaddy |
| SSL | Webuzo → SSL → Automatic SSL (Let's Encrypt) |
| Public files | `/home/spicemam/public_html/` — pages, `assets/`, `admin/`, `api/index.php`, `uploads/products/`, `.htaccess` |
| Private files | `/home/spicemam/app/` — PHP code; `app/config/local.php` holds the production DB password (owner-only, mode 600, never in Git) |
| Database | `spicemam_site`, app user `spicemam_app` (SELECT/INSERT/UPDATE/DELETE only); manage via Webuzo → phpMyAdmin |
| Backups | Webuzo → Backuply, weekly host backups |
| SSH | **Not available** on this plan. Changes go through Webuzo File Manager and phpMyAdmin |

Updating the live site later: change and test locally, commit, then upload only the changed files
with File Manager into the matching folder (frontend files → `public_html/...`, backend `src/` →
`app/src/...`). For a full rebuild, `deploy/build-release.sh` needs a Unix shell with `zip`
(Git Bash + zip, or WSL); see `deploy/RELEASE_GUIDE.md`. Database changes go through phpMyAdmin SQL.

Claude's own server actions are blocked by Claude Code's safety rules ("production deploy"), so the
owner clicks in Webuzo while Claude guides and verifies from the laptop (`curl`, headless Chrome).

### Admin account (live)
No SSH, so create it with phpMyAdmin:
1. Make a password hash locally (the password itself never leaves the laptop):
   `php -r "echo 'Password: '; echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;"`
   (type a new password of 12+ characters and press Enter; on Windows it is visible while typing).
2. Webuzo → phpMyAdmin → `spicemam_site` → SQL:
   ```sql
   INSERT INTO admins (email, password_hash, display_name, role, is_active, created_at, updated_at)
   VALUES ('owner@example.com', 'PASTE_HASH', 'Display Name', 'admin', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));
   ```
3. Log in at `https://spicemama.in/admin/` (works only over https). Test on "Test1", not real products.

## 5. Pre-launch page and Launch button

Visitors see `/maintenance.html` ("Opening this Diwali", family artwork) until the file
`/home/spicemam/public_html/.launched` exists. Admin, API, assets and uploads keep working; the owner can
preview the real site with the private `?preview=` link (code lives only in the Mac's gitignored `dist/`).
Template: the "Optional pre-launch page" block in `deploy/public_html.htaccess.example` (replace `TOKEN`,
uncomment the 7 `Rewrite...` lines). The `.launched` dotfile is never downloadable (403).

**Launch day**
1. Open `https://spicemama.in` — a "Launch the website" bar is at the bottom (no sign-in; anyone can press it).
2. Press it: the server creates `.launched` (once, audited as `site.launched`), fireworks play for 4 seconds,
   then the home page opens. Everyone typing spicemama.in now gets the home page.
- Rehearse the show without launching: `/maintenance.html?celebrate`.
- Undo: delete `public_html/.launched` in File Manager (show hidden files).
- If the button fails: delete the PRE-LAUNCH block from `public_html/.htaccess` — the site opens at once.
- After launch the pre-launch block can be removed from `.htaccess` at leisure.
- Caching trap (found 2026-11-07): browsers revalidate `/` with If-Modified-Since, and LiteSpeed answers
  304 if the page file is *older* than the pre-launch page they saw — they then stay on the pre-launch page.
  Whenever `maintenance.html` is uploaded, upload the public pages too with *later* file times (the launch
  page also re-downloads `/` before redirecting).

## 6. Rules that still apply

- Owner approval before any live change, DNS change, purchase or deployment; no secrets in Git.
- Do not invent business facts or claims; mark placeholders clearly.
- API contract changes need owner approval. Open question for the owner: `PATCH /admin/categories/{id}`
  and `POST /admin/products/{id}/status` return 404 for missing records, which the contract does not list.
- Login throttle (provisional, owner to confirm): 5 failed attempts per account or 20 per network
  address in 15 minutes → 15-minute block.
