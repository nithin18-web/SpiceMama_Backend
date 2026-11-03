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
| `frontend/` | Public pages, admin screens (`frontend/admin/`), assets, `maintenance.html` (Diwali pre-launch page), `mock/` (browser-only sample data, never deployed) |
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
- Diwali pre-launch page built: `frontend/maintenance.html` with an animated chilli-pounding scene
  (`frontend/assets/images/campaign/pounding-scene.webp`). **Not uploaded yet** (see pending #1).

### Pending — in this order
1. **Before Diwali: put the pre-launch page live** (owner action in Webuzo, ~10 min). Right now the
   full site is publicly visible and `http://` does not redirect to `https://`. See section 5.
2. **Live admin account** — none exists on the server yet (section 4, "Admin account (live)").
3. **Sunday 8 Nov 2026, 00:00 IST:** the pre-launch page should switch off by itself. Check
   `https://spicemama.in` after midnight; if it still shows "Opening this Diwali", delete the
   pre-launch block from `public_html/.htaccess` (section 5).
4. **Merge** `feature/full-site-v1` into `main` (this repo, and decide whether
   `SpiceMama_Frontend` is retired or kept in sync).
5. **Content (owner):** remove/archive "Test1"; confirm the 4 placeholder categories (Chilli powders,
   Spices, Masalas, Flours); WhatsApp number (`frontend/assets/js/config.js` → `business.whatsapp`);
   privacy policy and terms; founding story; real recipe photos. Do not publish claims such as
   "no preservatives" or "100% ethically sourced" unless the owner confirms them.
6. **Optional:** better pounding animation needs the pack designer's layered artwork (separate pestle/arms).
7. **Housekeeping:** log out of Webuzo after each session (its URLs contain a login session);
   delete leftover files on the server (`spicemama-site.zip` if still in `/home/spicemam`).

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

## 5. Diwali pre-launch page (pending #1)

Goal: visitors see "Opening this Diwali" with a countdown until **00:00 IST Sunday 8 Nov 2026**
(= `20261107183000` UTC, the server clock); admin, API, images keep working; the owner can preview the
real site with a private link.

Owner steps in Webuzo File Manager — **in this order**:
1. Upload `frontend/assets/images/campaign/pounding-scene.webp` → `public_html/assets/images/campaign/`
2. Upload `frontend/maintenance.html` → `public_html/`
3. Build the new `.htaccess` text from `deploy/public_html.htaccess.example`:
   - in the "Optional pre-launch page" block remove the leading `# ` from the 7 `Rewrite...` lines,
   - replace every `TOKEN` with a random code (e.g. PowerShell:
     `-join ((1..16) | % { '{0:x}' -f (Get-Random -Maximum 16) })`) — keep it private,
   - replace `LAUNCH_UTC` with `20261107183000`,
   - then in Webuzo: `public_html/.htaccess` → Edit file → replace everything → Save.
4. Check (or ask Claude to check): `https://spicemama.in` shows the Diwali page; `http://` redirects to
   `https://`; `https://spicemama.in/?preview=<your code>` shows the real site in that browser for 7 days.

Notes: the template already enables the HTTPS redirect. If LiteSpeed ignores the time condition the
page simply stays up (it never opens early) — then delete the pre-launch block on Sunday.
A preview code was generated on the Mac but lives only in its gitignored `dist/` folder; generate a
new one on Windows if the page was not uploaded from the Mac.

## 6. Rules that still apply

- Owner approval before any live change, DNS change, purchase or deployment; no secrets in Git.
- Do not invent business facts or claims; mark placeholders clearly.
- API contract changes need owner approval. Open question for the owner: `PATCH /admin/categories/{id}`
  and `POST /admin/products/{id}/status` return 404 for missing records, which the contract does not list.
- Login throttle (provisional, owner to confirm): 5 failed attempts per account or 20 per network
  address in 15 minutes → 15-minute block.
