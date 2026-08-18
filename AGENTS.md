# AGENTS.md — myhome-eagle-sync

WordPress plugin that imports real-estate listings from the **EagleAgent API v3** into
MyHome (myhome_listing posts), mapping API fields onto MyHome custom fields that
already exist on the site. No fields are ever auto-created.

## Project layout

| File                        | Responsibility |
|-----------------------------|----------------|
| `myhome-eagle-sync.php`     | Plugin bootstrap; constants `MES_OPTION_*`, `MES_TRANSIENT_TOKEN`, `MES_VERSION`; loads classes |
| `includes/class-eagle-api-client.php`  | OAuth token + GraphQL calls; `fetch_properties_page()`, `count_properties()` |
| `includes/class-eagle-sync-engine.php` | Batch loop, state machine, upsert logic, field/image/agent writers |
| `includes/class-eagle-field-manager.php` | Auto-maps Eagle keys → existing MyHome field IDs (read-only) |
| `includes/class-eagle-ajax.php` | `mes_import_batch` AJAX handler (`set_time_limit(120)`, guards `Throwable`) |
| `includes/class-eagle-settings.php` | Admin page: credentials form, sync card, status line + spinner |
| `includes/class-eagle-logger.php` | Rotating log inside the `mes_log` option (newest first, max 200 entries) |
| `assets/js/sync.js`          | Click → poll loop → status `N/total imported` → reload on done |
| `assets/css/sync.css`        | Spinner + status styles |

## Eagle API v3 endpoints

### 1. Token — `POST https://www.eagleagent.com.au/api/v3/token`
- Auth: header `Authorization: Bearer <clientId>:<clientSecret>` (both from the
  settings page; secret stored encrypted with `AUTH_KEY`/`AUTH_SALT`, AES-256-CBC).
- Body: `{}` (JSON, `Content-Type: application/json`).
- Response: `{ data: { token: { token, expiresAt } } }`.
- Token cached in transient `mes_token` until ~60 s before expiry; on HTTP 401 the
  token is discarded and refreshed once automatically.

### 2. GraphQL — `POST https://www.eagleagent.com.au/api/v3/graphql`
- Auth: header `Authorization: Bearer <token>`; also sends `Origin:
  https://www.eagleagent.com.au`.
- Body: `{ "query": "...", "variables": {...} }`.
- Retries: 2 attempts with backoff on network error, HTTP 429, and HTTP ≥500.
- Returns `data.properties` = `{ totalCount, nodes: [...] }`.
- Called by `Eagle_API_Client` only; engine and AJAX never talk to the API directly.

## API quirks that drive the design (do not "fix")

- **`totalCount` is NOT the account total.** It echoes the requested `limit`
  (verified: limit=1→1, limit=50→50, limit=200→200). Never trust it.
- **Page size is capped at 50 nodes**, even when you ask for more. The engine's
  page-based completion rule still works: a page with `< batch` nodes is the last one.
- **Exact total** comes from `count_properties()`: walk pages of 50
  (`nodes { id status }`) until an incomplete page; sum the node counts,
  **excluding `DRAFT`** (the import skips them too). Called once at the
  start of every fresh run (~10 requests for 475 importable listings);
  fallback = previous run's processed count if the first request fails.
- Node IDs are opaque strings (e.g. `"49ad8c1f-..."`), stored in
  `_mes_eagle_property_id` and used to dedupe updates.
- Images are remote HTTP S3 URLs; `media_sideload_image()` sometimes returns
  "Invalid image URL"/"Forbidden" — logged as errors, never fatal.
- **Image-heavy listings (~100 MB) used to 503.** A single request downloaded
  a listing's *entire* gallery; on the Hostinger box (1 CPU core, S3 throttled
  to ~140 KB/s per connection) that request ran for minutes of wall-clock and
  LiteSpeed/LVE killed it with a 503 — silently, because the kill is at the web
  server and PHP's own timer doesn't count download wait (memory is a non-issue:
  `memory_limit` is 1536M). Fixed on two axes:
  1. **Resume per image.** The engine keeps `offset` on a listing until every
     image is imported or exhausted; the 1s poll loop drives it. Dedup key =
     eagle image id, else `url:<md5>` (stored in `_mes_eagle_image_id`).
     **Images are never skipped**: a failed download is retried on the next
     poll until it succeeds, so a listing stays pending until its gallery is
     complete (failures are visible in the log as repeated download errors).
  2. **Time-boxed streaming downloads** (`stream_download_image`): each request
     pulls only a slice, and a large image is continued across polls with an
     HTTP Range request. curl's timeout is capped to the slice, so no request
     ever runs long enough to be killed. Partial files live in
     `uploads/mes-tmp/<md5>.part` until complete, then sideload.
  3. **No image processing** (project decision): the downloaded file is
     sideloaded untouched — no downscaling, no WordPress thumbnail-size
     generation, no image editor. Only minimal metadata (width/height/path)
     is recorded. An import therefore never spends CPU/memory on resizing,
     which is what used to get requests killed at ~180s on the live host.
  4. **Dedup key set early**: `_mes_eagle_image_id` is written immediately
     after `wp_insert_attachment`, so a request killed mid-import never
     re-downloads the same image; the retry finds the orphan and reuses it.
  Budgets are filters: `mes_images_per_request` [1], `mes_bytes_per_request`
  [20 MB], `mes_request_time_budget` [20s]. The `_mes_eagle_image_attempts`
  counter is diagnostic only (failure counts per image, reset at each fresh
  run) — it never skips an image.
  Fatal errors that bypass try/catch (OOM, PHP timeout) are now captured by a
  `register_shutdown_function` in `Eagle_Ajax` and written to the log.
  Other smoothing: the busy lease is 60s (not 180s), the JS poll interval is
  1s (not 300ms), and while a listing is stalled the engine reuses the last
  fetched page from state instead of re-hitting the Eagle GraphQL API every
  poll. `.part` files older than 24h are swept at the start of every fresh run.
- `totalCount`-style pagination also affects the count walk: always `limit=50`.

## GraphQL query (what each listing node can contain)

`properties(limit, offset, listingType, status)` → `nodes` with:

- Identity/address: `id reaId headline description formattedAddress streetNo street
  unit municipality country lotNo latitude longitude`
- Listing info: `price advertisedPrice showPrice saleOrLease listingType status
  propertyType daysOnMarket featured thumbnailSquare videoUrl onlineTour1Url
  onlineTour2Url bookInspectionLink brochureTitle keyLocation keyNumber alarmCode
  smsCode internalNotes agencyReference externalPropertyTree`
- Dates/stats: `auctionDatetime listingExpiryDate letDate soldDate rentalDateAvailable
  soldPrice soldDisplay offMarketAt createdAt updatedAt activeAt withdrawnAt
  numberOfWebsiteViews numEnquiries numOffers`
- Sub-objects: `customFields { key name fieldType value allowedValues }`,
  `images { id position url }`, `floorplans { id position url }`,
  `agents { id name title email phone mobile office { id name } }`,
  `office { id name }`, `vendors { contact { id fullName company mobilePhone } }`,
  `address { formattedFullAddress }`
- `listingDetails` is a union fragment (`ResidentialSale`, `ResidentialRental`,
  `Commercial`, `Rural`, `Land`, `Business`) providing type-specific fields:
  bedrooms/bathrooms/ensuites/toilets/livingAreas/garageSpaces/carportSpaces/
  openCarSpaces/houseSizes/houseSizeUnits/propertyType/establishmentOrDevelopment/
  energyEfficiencyRating/rentalPerWeek/rentalPerMonth/bond/holidayRental/
  commercialPropertyType/commercialListingType/floorArea(+Units)/leaseExpiryDate/
  leaseTerm/outgoings/tax/tenancy/zoning/occupancyTitle/parkingComments/warehouseArea/
  officeArea/psmPaMin/psmPaMax/returnOnInvestment/totalCarSpaces/carryingCapacity/
  fencing/irrigation/annualRainfall/soilTypes/councilRates/improvements/crossOver/
  frontage/leftDepth/rightDepth/rearDepth/businessName/saleOrTender/tenderDate/terms.

(Full text lives in `Eagle_API_Client::QUERY`.)

## Field auto-mapping (never creates fields)

`Eagle_Field_Manager::get_field_map()` scans existing `myhome_field` posts and
resolves each Eagle key to a field ID, preferring fields already in use by listings.
`SLUG_ALIASES` maps Eagle names to MyHome slugs (e.g. `houseSizes`→`property-size`,
`garageSpaces`→`vehicle-spaces`, `listingType`→`offer-type`). Resolved on this site:

| Eagle key                | Field ID |
|--------------------------|----------|
| gallery / floorplans     | 145      |
| location                 | 153      |
| bedrooms                 | 5462     |
| bathrooms                | 5463     |
| price                    | 130      |
| garageSpaces, totalCarSpaces | 15544 |
| houseSizes               | 340      |
| propertyType             | 14       |
| listingType / saleOrLease| 5495     |
| videoUrl                 | 345      |
| onlineTour1Url / 2Url    | 3411     |

Field types handled: `price` (per-currency array, AUD only), `number` (raw float
string), `location` (`lat/lng/address`), `embed` (`{url, embed:''}`), `taxonomy`
(terms ensured via `term_exists`/`wp_insert_term`), everything else scalar/JSON.
The `status` key is deliberately never written as a field.

## Sync flow & state machine

- Single AJAX action `mes_import_batch` (nonce, `set_time_limit(120)`). JS polls it
  every 1000 ms; each call = one batch (default **1** listing, filter
  `mes_batch_size`, page capped at 50).
- Options: `mes_sync_state` (phase/offset/total/processed/created/updated/skipped/
  failed/last_error/started_at), `mes_sync_summary` (finished totals), `mes_log`.
- Fresh run: count listings (see quirks) → log `Sync started … total listings: N` →
  batch loop until a page returns fewer nodes than the batch → done (summary +
  log). Resume: state persists mid-run; clicking again continues from `offset`.
- Listings upsert by `_mes_eagle_property_id`; galleries dedupe via
  `_mes_eagle_image_id` and set the featured image if missing; agents/office stored
  as post meta (`agents` office arrays are reduced to `office.name` string).
- All batches return JSON `{success, data:{phase:'running'|'done'|'error', processed,
  total, offset}}`; UI shows `processed/total imported` + spinner (CSS must keep
  `.mes-spinner[hidden]{display:none}` or it spins forever).
- Status map: ACTIVE/UNDER_OFFER/WITHDRAWN/OFF_MARKET/SOLD/LEASED/DELETED → publish,
  DRAFT → draft.

## Dev / verification workflow

- Stack: podman rootless (`systemctl --user start podman.socket` if the socket is
  missing), `docker compose up -d` in `/mnt/wpdashboard`. Containers:
  `wpdashboard-db-1` (MariaDB `wordpress`/`wpuser`), `wpdashboard-wordpress-1`
  (web root `/var/www/html` = `/mnt/wpdashboard/html`), `wpdashboard-wp-cli-1`.
- Run code inside WP: `podman exec wpdashboard-wordpress-1 php -r 'require
  "/var/www/html/wp-load.php"; …'` (expect `objectcache.critical` noise per request —
  `mu-plugins/object-cache-pro.php` targets a plugin that isn't installed; harmless).
- Lint: `php -l` on edited files, `node --check assets/js/sync.js`.
- Browser-authenticated calls: get the dashboard HTML with a session cookie, extract
  the `MES = {…}` JS block for the nonce, post to `admin-ajax.php` with both auth
  cookies. CLI-generated nonces are rejected (nonce must come from the rendered page).
- DB checks: `podman exec wpdashboard-db-1 mariadb -uwpuser … wordpress` (password in
  the compose file).

## Gotchas

- Credentials: never log `client_id`, `client_secret`, or `token`
  (logger sanitises them).
- PHP default `max_execution_time` is 30 s — image downloads blown past it; hence
  `set_time_limit(120)` per request and small batches.
- Don't precompute the total from `totalCount`; count walk only. The sync can run
  past claimed totals — the progress denominator must come from `count_properties()`.