# Claude frontend API guide

Status: documentation/scaffolding only; no live or local PHP API exists yet.
Root `API_CONTRACT.yaml` version 1.0.0 is authoritative. Work only in `frontend/`;
shared contract changes require owner approval and coordinated review.

## Build against the contract

Keep HTTP calls in `assets/js/api-client.js`; use relative `/api/v1` URLs and
`credentials: 'same-origin'`. Mock transport belongs in `frontend/dev/`, explicitly
enabled only in development and excluded from release artifacts. Use visibly
labelled synthetic fixtures, never unapproved business claims or packaging text
as production copy. No frontend implementation is supplied by this foundation.

Public calls: GET `/products`, `/products/{slug}`, `/categories`, prefixed `/api/v1`.
Product detail remains `product.html?slug=...`; encode the slug in API URLs.
`category` is an exact category slug; `q` searches name/short description.
Lists of products return `{data, meta}`; category lists return `{data}`.
Use `meta.total_pages`, not guessed counts. Empty results have total_pages=0.
Images arrive in display order; first is primary. Use URL as supplied, never
construct filenames. Render all descriptions and alt text as text, not HTML.
Public product visibility requires published status AND active category.

## Admin session sequence

1. GET `/admin/csrf` to establish a pre-login session and keep token in memory.
2. POST `/admin/login` with JSON email/password and `X-CSRF-Token`.
3. After success, GET `/admin/csrf` again: successful login rotates the token.
4. GET `/admin/me` when entering protected screens; UI gating complements API auth.
5. Send token on every mutation, including logout and multipart upload.
6. POST `/admin/logout`; on 204 clear token/user state without parsing JSON.

Never read session cookies or store credentials/tokens in localStorage. Session
idle expiry is 30 minutes, absolute expiry 8 hours; show login on authenticated
API 401. A 403 may indicate stale CSRF: fetch current token, explain failure and
let user retry explicitly. Do not automatically replay mutations after network
errors, timeouts, 403 or 5xx: a write may already have succeeded.

## Admin editing and images

GET `/admin/products` is paginated and includes all statuses; optional status,
category, q filters. GET `/admin/products/{id}` loads drafts for editing.
POST `/admin/products` requires name, slug, category_id and always creates draft.
PATCH sends only changed fields; empty patch and unknown properties are rejected.
Slugs are explicit lowercase ASCII words separated by hyphens, unique per table;
backend does not silently generate or change them. A changed slug breaks old URLs
in phase 1; confirm edits in UI. No redirect system is included.

POST `/admin/products/{id}/status` with draft, published or archived. Archive
rather than delete. Publishing needs active category, nonblank short/full
 descriptions, and at least one image whose alt text is nonblank. Published edits
must preserve these conditions. Repeated status succeeds without resetting first
publication time. Category creation defaults inactive; deactivation hides products,
and reactivation restores visibility without changing product status.

Create a product before uploading. POST `/admin/images` using FormData with
product_id, file and optional alt_text. Do NOT set Content-Type manually: browser
supplies multipart boundary. One file <=5,242,880 bytes, JPEG/PNG/WebP, no animation
or SVG, <=4096 on either side and <=4,000,000 pixels. Server may reject earlier.
PATCH `/admin/images/{id}` changes alt_text. DELETE removes the uploaded image;
last image of published product cannot be removed. PUT
`/admin/products/{id}/images/order` supplies `{image_ids:[...]}` containing the
complete current gallery exactly once; first becomes primary. On 409 reload
current product before reconciling order. No repository original is deleted.

## Responses and states

200/201 contain JSON; 204 is empty. Handle 400 malformed requests, 401 session/login,
403 CSRF/permission, 404 missing/unavailable product, 409 duplicate slug or gallery
conflict, 413 size, 415 format, 422 field validation, 429 Retry-After, 500/503 server
failure. API error shape is `{error:{code,message,fields?}}`. Network/proxy errors
may be non-JSON; provide a safe generic message. Do not display raw server HTML.

Implement loading, empty, field errors, timeout/offline and successful save states.
Abort/debounce superseded catalogue searches so stale results do not overwrite new
ones. Treat response schemas/required fields and bounds in YAML as authoritative.
Keep fixtures for multiple pages, no products, inactive categories, drafts, expired
sessions, CSRF errors, duplicate slugs, upload errors and gallery conflicts.
