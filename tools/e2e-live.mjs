// Live end-to-end check: real pages in headless Chrome against the real PHP API and the
// disposable spicemama_test MariaDB database. Local only; never points at hosting.
// Usage: PHP_BINARY=/opt/homebrew/opt/php@8.4/bin/php node tools/e2e-live.mjs
// Needs Google Chrome (CHROME_BINARY to override). Screenshots go to $E2E_SHOTS if set.
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync, existsSync, mkdirSync, readdirSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const php = process.env.PHP_BINARY || 'php';
const chrome = process.env.CHROME_BINARY || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const shots = process.env.E2E_SHOTS || '';
const work = mkdtempSync(join(tmpdir(), 'spicemama-e2e-'));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let passed = 0;
const check = (ok, label) => { if (!ok) throw new Error(`FAIL: ${label}`); passed += 1; };

const freePort = () => new Promise((res) => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); }); });

// ---------- disposable backend ----------
const uploads = join(root, 'frontend/uploads/products');
const config = join(work, 'config.php');
writeFileSync(config, `<?php
$config = require ${JSON.stringify(join(root, 'backend/config/local.php'))};
$config['database']['database'] = 'spicemama_test';
$config['storage_path'] = ${JSON.stringify(join(work, 'storage'))};
$config['upload_path'] = ${JSON.stringify(uploads)};
return $config;
`);
const env = { ...process.env, SPICEMAMA_CONFIG: config };
const listUploads = () => (existsSync(uploads) ? readdirSync(uploads) : []);
const before = new Set(listUploads());
execFileSync(php, ['backend/tests/reset_test_db.php'], { cwd: root, env, stdio: 'ignore' });
const password = randomBytes(12).toString('hex');
execFileSync(php, ['backend/bin/create-admin.php', '--email=e2e@example.test', '--name=MOCK E2E'], { cwd: root, env, input: `${password}\n`, stdio: ['pipe', 'ignore', 'inherit'] });

const port = await freePort();
const base = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-d', 'display_errors=0', '-S', `127.0.0.1:${port}`, 'backend/bin/dev-router.php'], { cwd: root, env: { ...env, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
const profile = join(work, 'chrome');
const browser = spawn(chrome, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });

async function cleanup() {
  browser.kill();
  server.kill();
  await sleep(300);
  // Files uploaded by this run belong to the test database only.
  for (const name of listUploads()) if (!before.has(name) && /^[a-f0-9]{32}\.(jpg|png|webp)$/.test(name)) rmSync(join(uploads, name));
  rmSync(work, { recursive: true, force: true });
}

try {
  for (let i = 0; i < 100 && !(await fetch(`${base}/api/v1/categories`).then(() => true, () => false)); i++) await sleep(100);
  let devtools = '';
  for (let i = 0; i < 100 && !devtools; i++) {
    if (existsSync(join(profile, 'DevToolsActivePort'))) devtools = readFileSync(join(profile, 'DevToolsActivePort'), 'utf8').split('\n')[0];
    else await sleep(100);
  }
  check(devtools, 'Chrome started');
  const target = await (await fetch(`http://127.0.0.1:${devtools}/json/new?about:blank`, { method: 'PUT' })).json();

  // ---------- minimal CDP client ----------
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
  let seq = 0;
  const pending = new Map();
  const problems = [];
  const requests = [];
  ws.onmessage = ({ data }) => {
    const msg = JSON.parse(data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve: ok, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(msg.error.message)) : ok(msg.result);
    } else if (msg.method === 'Runtime.exceptionThrown') {
      problems.push(`exception: ${msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text}`);
    } else if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
      problems.push(`console.error: ${msg.params.args.map((a) => a.value ?? a.description).join(' ')}`);
    } else if (msg.method === 'Network.requestWillBeSent') {
      requests.push(new URL(msg.params.request.url).pathname);
    }
  };
  const cdp = (method, params = {}) => new Promise((ok, reject) => { const id = ++seq; pending.set(id, { resolve: ok, reject }); ws.send(JSON.stringify({ id, method, params })); });
  await cdp('Runtime.enable');
  await cdp('Network.enable');
  await cdp('Page.enable');

  const evaluate = async (expression) => {
    const res = await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (res.exceptionDetails) throw new Error(`evaluate failed: ${expression}\n${res.exceptionDetails.exception?.description}`);
    return res.result.value;
  };
  const waitFor = async (expression, label, ms = 8000) => {
    const end = Date.now() + ms;
    while (Date.now() < end) {
      try { if (await evaluate(expression)) return check(true, label); } catch { /* page navigating */ }
      await sleep(100);
    }
    check(false, `${label} (timed out waiting for ${expression})`);
  };
  const open = async (path) => {
    await cdp('Page.navigate', { url: base + path });
    await waitFor('document.readyState === "complete"', `load ${path}`);
  };
  const shot = async (name, mobile = false) => {
    if (!shots) return;
    mkdirSync(shots, { recursive: true });
    await cdp('Emulation.setDeviceMetricsOverride', mobile ? { width: 390, height: 844, deviceScaleFactor: 2, mobile: true } : { width: 1366, height: 900, deviceScaleFactor: 1, mobile: false });
    await sleep(400);
    const { data } = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    writeFileSync(join(shots, `${name}${mobile ? '-mobile' : ''}.png`), Buffer.from(data, 'base64'));
    await cdp('Emulation.clearDeviceMetricsOverride');
  };
  const fill = (selector, value) => evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); el.value = ${JSON.stringify(value)}; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
  const click = (selector) => evaluate(`(() => { document.querySelector(${JSON.stringify(selector)}).click(); return true; })()`);
  const clickText = (selector, text) => evaluate(`(() => { const b = [...document.querySelectorAll(${JSON.stringify(selector)})].find((x) => x.textContent.trim() === ${JSON.stringify(text)} && !x.disabled); b.click(); return true; })()`);
  const toastHas = (text) => `[...document.querySelectorAll('.toasts *')].some((t) => t.textContent.includes(${JSON.stringify(text)}))`;
  const confirmOk = () => evaluate(`(() => { const d = document.querySelector('dialog[open]'); d.querySelector('button[value=ok]').click(); return true; })()`);
  const attach = async (path) => {
    const { root: doc } = await cdp('DOM.getDocument');
    const { nodeId } = await cdp('DOM.querySelector', { nodeId: doc.nodeId, selector: '#f-file' });
    await cdp('DOM.setFileInputFiles', { nodeId, files: [path] });
    await evaluate(`document.querySelector('#f-file').dispatchEvent(new Event('change', { bubbles: true })), true`);
  };

  // ---------- public pages before any content ----------
  await open('/?api=live');
  check(await evaluate('sessionStorage.getItem("sm-api")') === 'live', 'live API mode selected');
  for (const page of ['/', '/products.html', '/about.html', '/contact.html']) await open(page);
  await open('/no-such-page');
  await waitFor('document.title.length > 0 && document.body.innerText.length > 20', 'custom 404 page');

  // ---------- admin sign-in ----------
  await open('/admin/products.html');
  await waitFor('location.pathname === "/admin/login.html"', 'protected page redirects to login');
  await shot('admin-login');
  await fill('#l-email', 'e2e@example.test');
  await fill('#l-pass', 'wrong-password');
  await click('#login-btn');
  await waitFor('document.body.innerText.includes("don’t match")', 'wrong password message');
  await fill('#l-pass', password);
  await click('#login-btn');
  await waitFor('location.pathname === "/admin/products.html"', 'login returns to requested page');

  // ---------- category ----------
  await open('/admin/categories.html');
  await waitFor('document.body.dataset.auth === "ok" && document.querySelector("#add-cat")', 'categories page');
  await click('#add-cat');
  await fill('#c-name', 'MOCK Powders');
  await fill('#c-slug', 'mock-powders');
  await click('#cat-save');
  await waitFor('document.body.innerText.includes("MOCK Powders")', 'category created');

  // ---------- product ----------
  await open('/admin/product-edit.html');
  await waitFor('document.querySelector("#editor") && !document.querySelector("#editor").hidden && document.querySelector("#f-category").options.length > 1', 'editor ready');
  await fill('#f-name', 'MOCK Turmeric Powder');
  await waitFor('document.querySelector("#f-slug").value === "mock-turmeric-powder"', 'slug suggested from name');
  await evaluate(`(() => { const s = document.querySelector('#f-category'); s.value = s.options[1].value; s.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
  await fill('#f-short', 'MOCK short description');
  await fill('#f-desc', 'MOCK full description for testing.');
  await fill('#f-pack', '100 g MOCK');
  await click('#save-btn');
  await waitFor('/[?]id=\\d+/.test(location.search)', 'draft created and URL has id');
  const productId = Number(await evaluate('new URLSearchParams(location.search).get("id")'));

  await clickText('#status-acts button', 'Publish');
  await waitFor(toastHas('add a photo with a description'), 'publish explains missing photo');

  for (const [file, alt] of [['frontend/assets/images/packs/turmeric-powder.webp', 'MOCK turmeric pack front'], ['frontend/assets/images/labels/turmeric-back.webp', 'MOCK turmeric pack back']]) {
    const count = await evaluate('document.querySelectorAll("#img-list figure").length');
    await attach(join(root, file));
    await waitFor('!document.querySelector("#upload-preview").hidden', 'preview shown');
    await fill('#f-alt', alt);
    await click('#up-btn');
    await waitFor(`document.querySelectorAll("#img-list figure").length === ${count + 1}`, `uploaded ${alt}`);
  }
  await shot('admin-product-edit');
  await clickText('#img-list button', '→');
  await waitFor('document.querySelector("#img-list figure figcaption").textContent.includes("back")', 'reorder made back photo the main one');
  await clickText('#status-acts button', 'Publish');
  await waitFor('document.querySelector("#status-badge").textContent.includes("Published")', 'published');

  // Reload from the server: order and status persisted.
  await open(`/admin/product-edit.html?id=${productId}`);
  await waitFor('document.querySelector("#status-badge")?.textContent.includes("Published") && document.querySelectorAll("#img-list figure").length === 2', 'edit page loads product by id');
  check((await evaluate('document.querySelector("#img-list figure figcaption").textContent')).includes('back'), 'order persisted');

  // ---------- public site shows it ----------
  await open('/products.html');
  await waitFor('document.body.innerText.includes("MOCK Turmeric Powder")', 'catalogue lists product');
  await shot('catalogue');
  await shot('catalogue', true);
  await open('/product.html?slug=mock-turmeric-powder');
  await waitFor('document.querySelector("h1")?.textContent.includes("MOCK Turmeric Powder")', 'product detail');
  await waitFor('[...document.images].some((i) => i.src.includes("/uploads/products/") && i.complete && i.naturalWidth > 0)', 'uploaded image rendered');
  await shot('product');
  await shot('product', true);

  // ---------- photo rules while published ----------
  await open(`/admin/product-edit.html?id=${productId}`);
  await waitFor('document.querySelectorAll("#img-list figure").length === 2', 'editor reloaded');
  await clickText('#img-list button', 'Remove');
  await confirmOk();
  await waitFor('document.querySelectorAll("#img-list figure").length === 1', 'removed one photo');
  await clickText('#img-list button', 'Remove');
  await confirmOk();
  await waitFor(toastHas('must keep at least one image'), 'last photo of published product protected');
  check(await evaluate('document.querySelectorAll("#img-list figure").length') === 1, 'photo still there');

  // ---------- lists and sign-out ----------
  await open('/admin/');
  await waitFor('document.querySelector("[data-stat=published]")?.textContent === "1"', 'dashboard counts');
  await open('/admin/products.html');
  await waitFor('document.body.innerText.includes("MOCK Turmeric Powder")', 'admin product list');
  await click('[data-logout]');
  await waitFor('location.pathname === "/admin/login.html"', 'signed out');
  await open('/admin/products.html');
  await waitFor('location.pathname === "/admin/login.html"', 'session gone after logout');

  check(!requests.some((p) => p.startsWith('/mock/')), 'live mode never loads mock files');
  check(problems.length === 0, `no browser errors:\n  ${problems.join('\n  ')}`);
  console.log(`PASS: ${passed} live browser checks (Chrome + PHP + MariaDB spicemama_test).`);
} finally {
  await cleanup();
}
