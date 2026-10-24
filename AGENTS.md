# SimpleMappr development

## Runtime

Use the Docker runtime for PHP and MapScript. The host does not need PHP or a compiled MapScript extension.

```sh
docker build -t simplemappr-runtime:local .
scripts/configure-local-owner
docker compose up --build -d --wait
docker compose exec app scripts/check-runtime
docker compose exec app Tests/smoke/check-http
docker compose exec app vendor/bin/phpunit -c Tests/phpunit.modern.xml
docker compose exec app scripts/bootstrap-assets --check
curl --fail http://127.0.0.1:8080/_runtime
```

`SIMPLEMAPPR_PORT` changes the localhost port. The editor supports local owner login, private saved maps, revocable sharing, imports, publication exports, and WMS/WFS. Existing deployments keep their ignored .env; do not rerun configure-local-owner over an existing file.

The runtime uses Debian Apache prefork, mod_php 8.4, PHP CLI 8.4, and `php-mapscript-ng` from one dated Debian snapshot. Do not install MapScript compiled for another PHP ABI. Native map text loads with `msLoadMapFromString($text, $basePath, null)`. `new mapObj($filename)` loads a file.

`Tests/fixtures/manifest.json` is the sole `minimal-vector-v1` asset inventory. It contains all archive member hashes. Keep physical and nested cultural paths compatible with `config/shapefiles.yml.sample`. `assets-init` is the only writer of `map-data`. The app mounts that volume read-only. Missing optional datasets must remain explicit.

`scripts/check-runtime` verifies native PNG, SVG, and TIFF outputs, marker placement, five dataset feature counts, accented labels, and a Lambert projection round trip. It writes `/var/lib/simplemappr/checks/report.json` and map files to the `runtime-output` volume. The native checks do not load Composer or application classes.

Apache exposes the restricted front controller document root, `/_runtime`, and allowlisted files under `/_checks/`. Retain this restricted document root. Never publish the checkout root.

## Runtime updates

The Dockerfile pins the Debian base digest and package snapshot. Keep `/usr/local/share/simplemappr-packages.tsv` available in the image. Update the digest and snapshot together, rebuild, and run both HTTP and CLI checks.

Use a normal `022` file-creation mask for Git checkout and Docker builds. Docker preserves source permissions; a checkout created under `077` can leave PHP files unreadable to Apache even while native health passes. Keep deployment credentials mode `0600` separately. Before replacing the running app, verify application startup as `www-data`, then check the actual HTTP login page after replacement.

Apache worker limits live in `docker/apache-tuning.conf` (baked into the image); container memory limits and the InnoDB buffer pool are `compose.yaml` defaults overridable from `.env`. Do not raise `MaxRequestWorkers` without checking per-worker memory on the full-map profile. See docs/private-deployment.md.

`RenderCache` stores preview image bytes only and `Application::respond` is the only reader; every hit goes through `TemporaryImage` so owner/guest privacy is unchanged. Anything new that influences a preview render must be part of the request object or `RenderCache::version()`, and volatile request defaults (`file_name`, `download_token`) are excluded from the key. `opcache.validate_timestamps=0` means code changes need a restart.

Inter is the only text font. The editor bundles the pinned `@fontsource-variable/inter` Latin subsets via `public/stylesheets/raw/fonts.css`, so a new language needs its subset added there. Rendered map text uses `fonts-inter` from the image snapshot through `mapserver/fonts/fonts.list` (alias `inter`); do not add Arial or Verdana, and name new MapServer label fonts through that file. Fonts change map pixels, so rerun the render tests after changing one.

Every standalone HTML page extends `views/base.html`, which loads the small `pages-css` bundle (`public/stylesheets/raw/pages.css`) and the simple header by default; only the editor overrides both. Keep layout in that stylesheet, never inline, and give new pages the `.page`/`.card` structure (a template test enforces both). The About, API, Feedback and Help tab fragments are XHR-only: a direct visit gets a 303 to `/?locale=…#tabs=NAME` and the hash restore accepts tab names because numeric positions shift with the signed-in role.

Deploy with `scripts/deploy` on the host rather than editing `SIMPLEMAPPR_IMAGE` by hand; `Tests/operations/test_deploy.py` covers the swap order, template retirement and rollback with a fake docker.

Production Twig templates are cached in the persistent `public/tmp` volume with automatic reload disabled. Retire only verified compiled Twig cache directories when deploying changed templates; preserve preview images and other temporary files. A fresh-container test does not cover upgrades with an existing template cache. Confirm the new controls appear on the hosted page after deployment.

## Application port

The canonical request is the original map document. Keep `bbox_map`, `projection_map` and `origin_map` together as source viewport state. Rendered responses include `rendered_origin`; the browser echoes it into `origin_map`. Native projection changes require explicit extent transformation. Never mutate the shared projection registry per request.

Sub-path deployments set `SIMPLEMAPPR_URL` to an absolute URL with a path (for example `https://example.org/simplemappr`) behind a prefix-stripping proxy. In-page links, asset URLs, redirects and `MAPPR_MAPS_URL` use only its path (`SiteUrl::path()`, `{{ base }}` in templates); browser requests use `baseUrl`. Never hardcode a root-relative URL. The HTTP tests in the modern suite assume a root deployment, so run them against an app started with `SIMPLEMAPPR_URL` unset.

Use `config/defaults.php` for checked-in guest defaults and optional ignored `config/conf.php` overrides. The minimal dataset YAML is shared by the editor and renderer. The native health endpoint remains independent of Composer. Keep errors logged and binary responses clean.

The modern suite uses PHPUnit 13. The original coordinate suite is included with numeric tolerances. Other archived tests remain available but their database and Selenium bootstrap is pending migration. Do not delete their coverage or claim that a modern-suite pass covers persistence.

## Private persistence

MariaDB 11.8.9 is pinned by digest and has no published port. Compose requires random database credentials in ignored `.env`; scripts/configure-local-owner writes that file mode 0600 and stores only a password hash. Never log deployment credentials. The `database-init` service is the schema/provision/lookup writer. Database and session state use separate volumes. The configured local deployment owner is provisioned as administrator for private citation administration; this role never bypasses map ownership.

`scripts/migrate-database` creates an empty baseline or verifies `20261020000000`. It refuses implicit adoption. `scripts/adopt-legacy-database /absolute/new-backup.sql` validates known legacy schemas, backs up, and preserves original map JSON bytes. Historical migrations under `db/legacy-migrations` must not run. Old shares remain private until the owner explicitly creates a token.

All saved-map operations include the server-side uid. Admin roles never bypass map ownership. Mutations require session CSRF. SavedMapDocument owns the versioned settings allowlist, including case-sensitive layer names, bbox_map, projection_map and origin_map. Settings JSON is distinct from GeoJSON. Private preview images use TemporaryImage outside Apache public assets and an expiring owner-checked route. Guest image names contain 128 random bits.

## Social sign-in

Google OIDC and GitHub OAuth are optional and allow open registration. `SocialLoginConfig` owns fixed provider endpoints and credential validation without Composer or network access. `SocialLogin` owns state, PKCE, bounded HTTPS exchange, Google nonce and signed claims, and the authenticated GitHub public profile. GitHub uses explicit empty scope and `GET https://api.github.com/user` with a bearer header. Its positive integer `id` forms `oauth:github:` identities; Google retains the exact `oidc:google:` issuer/subject hash. Account names and emails never select ownership. `Session::loginSocial` resolves the verified identity through User and establishes the session privately. New accounts are ordinary users. Never link by email or assign the local owner's UID. Provider/issuer/subject hashes fit the existing unique identifier without a schema migration; provider email and access/refresh tokens are not stored.

The local owner signs in at `OwnerLogin::path()` (`views/owner.html`; default `/owner`, set `SIMPLEMAPPR_OWNER_LOGIN_PATH` in `.env` to move it), not on `/login`, which offers only the configured providers. The path is not linked anywhere, is `noindex` (meta and `X-Robots-Tag`) and is an ordinary 404 unless an owner is configured; do not list it in a robots.txt. A malformed or reserved value stops startup in `scripts/check-config`, and backups keep and check it. Failed owner logins are throttled to 10 per 15 minutes per client address as the app sees it, which behind a reverse proxy is the proxy.

Sessions use separate local/provider credential fingerprints. Keep SameSite=Lax; Google and GitHub return query callbacks. Provider callbacks and redirects preserve the validated `SIMPLEMAPPR_URL` prefix, including `https://jky.io/simplemappr`. Explicit HTTPS deployment origins set Secure cookies behind TLS termination without trusting forwarded headers. Apache access logs omit query strings and referrers. Preserve the protected provider configuration in backups; recovery at a changed origin clears active provider pairs until callbacks are reconfigured. The local owner remains the recovery login. Real provider consent requires operator credentials; isolated HTTPS fixtures do not prove console configuration.

## Imports and services

`ImportData` parses bounded uploaded or remote bytes, with explicit text encoding, XML entity rejection, and row errors. Import parsing never unlinks a caller-supplied path. Multipart uploads must pass `is_uploaded_file`. `RemoteImport` allows public HTTP/HTTPS destinations on standard ports, validates every DNS answer and redirect, pins the selected address, verifies TLS, and disables inherited proxies. Its 15-second deadline includes a separate, terminable DNS process. Limits apply to decoded bodies at 5 MiB and response headers at 64 KiB. Do not add a private-host override to production.

`GeometryExport` owns GeoJSON and KML geometry. Settings remain a separate `SavedMapDocument` contract. `RenderedImage` handles GDAL TIFF through an owned temporary file because native `getBytes()` does not support that driver. Native polygon opacity uses the MapScript style parser; assigning an unknown SWIG property can silently do nothing.

`OgcService` bounds both KVP and XML requests. WMS supports 1.1.1 and 1.3.0 with explicit EPSG:4326 axis-order tests. WFS supports 1.0.0, 1.1.0, and 2.0.0, including zero count and a server maximum of 1000. WFS reads an OGR SQL view exposing `FID AS mappr_id` for stable XML IDs without changing checksummed DBF assets. Advertise only tested service CRS values. Keep MapServer error responses as XML and ordinary API errors as JSON.

The P4 contract tests are in the modern suite. `Tests/smoke/check-import-boundary` runs from the host with Docker access and creates an isolated HTTPS fixture network. It checks actual transport limits, certificate name validation, redirects, and 20 decoded import-to-map samples. The detailed endpoint contract is in `docs/import-export.md`. PHP 8.4 currently logs 11 implicit-nullable deprecations from the locked GeoPHP dependency. They must not appear in binary response bodies.

## Frontend build and state

The Docker Node stage runs `npm ci`, `npm run build`, `npm test` and `npm run check:build`. PHP serves the content-hashed `public/build/manifest.json` in every environment and never writes public assets. Keep lock integrity and the Node image digest pinned. Generated frontend license notices and retained Jcrop/ColorPicker/Tiny Circleslider input hashes are part of the manifest. These retained plugins are not covered by npm audit.

The browser document follows `SavedMapDocument` schema version one. Empty PHP dictionaries can arrive as `[]`; accept only the empty array as a dictionary. Preserve independent groups, raw invalid rows, case-sensitive and underscore layer names, explicit false flags, and the complete viewport source tuple. Browser current/history/crop state is scoped to the server-provided owner or guest namespace. Only authenticated owners may migrate recognized unscoped legacy history. Never store CSRF or transport fields. The general importer loads transient editable settings; the My Maps importer writes private persistence.

## Private release operations

`config/asset-profiles/index.json` selects the minimal, extended-vector, Gray Earth or full-map profile and its exact manifests. `scripts/fetch-assets` creates a user-owned host cache; optional Compose deployments explicitly select its absolute path. Minimal uses a named empty cache volume. AssetInstaller streams ZIP members or a bounded single raw file, verifies hashes and atomically publishes them. Raw file manifests require identical source and component hashes and byte counts. Blue Marble uses the original NASA TIFF through `config/blue-marble.vrt`, which supplies global WGS84 georeferencing without modifying the image. The native health endpoint reports the selected profile without loading Composer. Raster layers skip vector-only connection and encoding setup. Full-map ecological layers retain mandatory scaled credits independent of optional legends/watermarks. DatasetCredits owns citation/license notices and `/data-credits`; GeometryExport contains only user geometry. Hotspot native filters and the OGR VRT select 36 hotspot-area records from 53 immutable source records. The VRT preserves source FIDs before WFS client filters. Generate RESOLVE styles with `scripts/generate-ecoregion-style --check` against the pinned cache. Recreate managed `assets-init` with the new profile/cache mount when switching profiles; one-off installation does not update the provenance required by backup.

`scripts/backup` records the running immutable image ID and retains it under a derived simplemappr-backup tag. Checkout revision/dirty state is informational. Preserve/export the image and optional archive cache separately from protected data bundles. `scripts/restore` accepts a new project only, verifies fixed bundle members before Docker, imports SQL before initialization and starts fresh sessions. Explicit config overrides are captured from actual read-only app bind mounts. Restored override files are readable inside the container from a host mode 0700 directory.

`Tests/browser` pins the official Playwright image digest and matching exact npm version. `scripts/check-browser` stages traces privately and sanitizes before copying receipts. Owner checks require a separate mode 0600 disposable credential JSON; login is excluded from tracing. Keep private fixtures outside build/receipts. `scripts/check-backup-restore --private-dir NEWDIR` owns only its disposable projects and exercises rotation, integrity refusals, original document bytes and exports. Never use these mutation checks on the operator's main deployment.

The release workflow runs minimal native/PHP/import/persistence/browser/operations gates. Optional dataset downloads require the workflow_dispatch optional_assets input. The obsolete Travis setup is archived under docs/legacy-ci after a successful replacement Actions run. Deployment/recovery policy, update cadence and feature boundaries are in docs/private-deployment.md, docs/feature-verification.md and docs/third-party.md.

Detailed grey and colour relief is opt-in with `SIMPLEMAPPR_DETAILED_RELIEF=1`. Colour relief adds GIBS `ASTER_GDEM_Color_Index` palette PNGs (`index-` cache names); `config/aster-gdem-color-index.json` is the published key, and validation requires each land tile's palette to match it exactly. A VRT `<LUT>` maps palette indices to `DetailedRelief::TINTS`, then the grey mosaic is composited above it (soft-light 85%, multiply 25%) with vector lakes and rivers. `moveLayerUp` changes drawing order, not `layerObj->index`. Blue Marble detail uses GIBS Global WELD Landsat (`landsat-` cache names, black is transparent) clipped to land, over `DetailedRelief::WATER` polygons (clip rectangles minus land) with Natural Earth lakes in the same colour; it is credited separately as `DatasetCredits::DETAILED_IMAGERY`. `DetailedRelief` plans bounded EPSG:3857 tile mosaics from the final projected viewport. `ReliefTileCache` is the sole writer of the separate disposable `relief-cache` volume. Its CLI worker uses fixed NASA GIBS ASTER endpoints, at most 256 validated 256x256 RGB JPEG tiles, four concurrent transfers, a 1 GiB LRU budget, locks and atomic publication. The parent enforces a monotonic 15-second deadline including the worker and bounded shared-lock copies into private per-render storage. Never give GDAL an unbounded remote WMS source or write detail data into map-data. Internal raster and land-mask layers are absent from document state and service capabilities. Keep WMS normalized bbox/CRS/axis order separate from its default world map; include the native aspect-adjusted extent. Optional caches are not backed up; the feature flag is validated and retained on recovery.

Clip the native land polygons to the tile mosaic's geographic coverage before projecting the inline relief mask. Projecting whole global land polygons can silently remove land coverage in Lambert views. Split geographic clip rectangles at the antimeridian and preserve the original land dataset's coastline and water holes.

Lambert output projections join equivalent longitudes without `+over`. `WrappedRasterSource` supplies private request-owned three-world VRTs for global WGS84 backgrounds and sets each raster layer's `lon_wrap` to the actual Lambert central meridian. It preserves source pixels, band metadata and alpha, bounds metadata preparation, and removes its own VRTs after rendering. App preparation runs after the final projection and before detailed terrain. WMS preparation uses its version-specific actual GetMap CRS; capabilities, WFS and SVG do not create raster wrappers. Never rewrite installed imagery or force whole-image raster loading to close a seam.

Wrapped tile indices do not fix the separate geographic-view longitude-wrap limitation. Close views crossing ±180 degrees omit the noncanonical half with detailed relief either enabled or disabled. Compare feature-on and feature-off renders before attributing that gap to the tile cache or land clipping.
