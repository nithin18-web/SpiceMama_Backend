// Adds or updates a ?v=<stamp> on every /assets/ link in the pages and partials, so browsers fetch
// changed CSS, JS and images at once instead of using their 7-day cached copy (pages themselves are
// sent with Cache-Control: no-cache). Run before building a live upload; bump the stamp each release:
//   node tools/stamp-assets.mjs 20261107b
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const stamp = process.argv[2];
if (!/^[A-Za-z0-9-]{4,32}$/.test(stamp ?? '')) {
  console.error('Usage: node tools/stamp-assets.mjs <stamp>   (letters, digits, dashes; e.g. 20261107b)');
  process.exit(1);
}
const root = fileURLToPath(new URL('..', import.meta.url));
const files = [
  ...readdirSync(join(root, 'frontend')).filter((f) => f.endsWith('.html')).map((f) => join(root, 'frontend', f)),
  ...readdirSync(join(root, 'frontend/admin')).filter((f) => f.endsWith('.html')).map((f) => join(root, 'frontend/admin', f)),
  ...readdirSync(join(root, 'tools/partials')).filter((f) => f.endsWith('.html')).map((f) => join(root, 'tools/partials', f)),
];
const link = /((?:href|src)=")(\/assets\/[^"?#\s]+)(?:\?v=[A-Za-z0-9-]*)?(")/g;
let total = 0;
for (const path of files) {
  const before = readFileSync(path, 'utf8');
  let count = 0;
  const after = before.replace(link, (_, a, url, z) => { count++; return `${a}${url}?v=${stamp}${z}`; });
  if (after !== before) { writeFileSync(path, after); }
  total += count;
  if (count) console.log(`${path.slice(root.length)}: ${count} links`);
}
console.log(`Stamped ${total} asset links with ?v=${stamp}`);
