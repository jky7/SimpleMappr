# SimpleMappr development

Rules and gotchas for working on this repo. Explanations live in `docs/`: [development](docs/development.md), [install and run](docs/private-deployment.md), [backup and restore](docs/backup-restore.md), [editor](docs/frontend.md), [import/export](docs/import-export.md), [social sign-in](docs/social-login.md), [third-party data](docs/third-party.md), [feature verification](docs/feature-verification.md). Update the matching doc when you change behaviour.

## Runtime

Use Docker for PHP and MapScript; the host needs neither. Checks:

```sh
docker compose exec app scripts/check-runtime
docker compose exec app Tests/smoke/check-http
docker compose exec app vendor/bin/phpunit -c Tests/phpunit.modern.xml
docker compose exec app scripts/bootstrap-assets --check
curl --fail http://127.0.0.1:8080/_runtime
```

- Debian Apache prefork, mod_php 8.4, PHP CLI 8.4 and `php-mapscript-ng` come from one dated snapshot. Never install MapScript built for another PHP ABI. Update the base digest and snapshot together and keep `/usr/local/share/simplemappr-packages.tsv` in the image.
- Load map text with `msLoadMapFromString($text, $basePath, null)`; `new mapObj($filename)` loads a file.
- `scripts/check-runtime` and `/_runtime` must not load Composer or application classes.
- Apache serves only the restricted front controller root, `/_runtime` and allowlisted `/_checks/` files. Never publish the checkout root.
- Never rerun `configure-local-owner` over an existing `.env`.
- Check out and build with umask `022`. A `077` checkout leaves PHP unreadable to Apache while native health still passes. Keep credentials mode 0600 separately.
- `opcache.validate_timestamps=0`: code changes need a restart.

## Deploying

- Deploy with `scripts/deploy`, never by editing `SIMPLEMAPPR_IMAGE`. `Tests/operations/test_deploy.py` covers swap order, template retirement and rollback with a fake docker.
- Verify startup as `www-data` before replacing the app, then check the real HTTP login page after.
- Twig templates are cached in the persistent `public/tmp` volume with reload off. When templates change, retire only verified compiled Twig directories and keep preview images. Fresh-container tests don't cover an existing cache, so confirm new controls on the hosted page.
- Don't raise `MaxRequestWorkers` (`docker/apache-tuning.conf`) without measuring per-worker memory on the full-map profile.

## Map data

- `Tests/fixtures/manifest.json` is the only `minimal-vector-v1` inventory and holds every member hash. Keep the physical and nested cultural paths compatible with `config/shapefiles.yml.sample`.
- `assets-init` is the only writer of `map-data`; the app mounts it read-only. Missing optional datasets must stay explicit, never silently absent.
- `AssetInstaller` streams ZIP members (or one bounded raw file), verifies hashes and publishes atomically. Raw-file manifests need identical source and component hashes and byte counts.
- Never modify installed imagery or checksummed DBFs. Use VRTs instead: `hyp-greyscale.vrt`, `blue-marble.vrt`, `hotspots-2016.vrt`, and an OGR SQL view for WFS `mappr_id`.
- Recreate the managed `assets-init` service when switching profiles; a one-off install doesn't update the provenance that backup checks.
- `DatasetCredits` owns license notices and `/data-credits`. Ecological credits are mandatory and scaled, independent of legend and watermark. `GeometryExport` contains only user geometry.
- Layer keys `relief`, `reliefalt`, `reliefgrey` and `reliefgreyalt` are stable. Blue Marble is retired: `SavedMapDocument` drops `blueMarble` on load. Its manifest and rendering helpers, including the Landsat detail path, remain only for provenance.

## Rendering

- `Mappr::visibleBackgroundLayers` skips global backgrounds hidden by a later opaque one. Keep the document's selections and derive `renderLayers` after the priority sort; only that list reaches native layers, Lambert wrapping, detailed tiles and credits. Transparent or regional layers never hide others. WMS keeps the full catalog in capabilities and prunes only GetMap lists, in client order. Run `CoveredBackgroundTest`'s optional cases with full-map and detailed relief on.
- `RenderCache` stores preview bytes only, and `Application::respond` is its only reader; every hit goes through `TemporaryImage`. Anything new that affects a preview render must be in the request object or `RenderCache::version()`. `file_name` and `download_token` stay out of the key.
- Inter is the only font: editor via `public/stylesheets/raw/fonts.css`, maps via `mapserver/fonts/fonts.list` (alias `inter`). Never add Arial or Verdana. Fonts change pixels, so rerun the render tests.
- Native polygon opacity goes through the MapScript style parser. Assigning an unknown SWIG property silently does nothing.
- `RenderedImage` writes GDAL TIFF through an owned temp file because `getBytes()` doesn't support that driver.
- Raster layers skip the vector-only connection and encoding setup.
- `moveLayerUp` changes drawing order, not `layerObj->index`.

### Detailed relief

- It is opt-in with `SIMPLEMAPPR_DETAILED_RELIEF=1`. The cutoff is when the preview scale bar ends at ≤200 km, as measured by `PreviewScale` on the native 200 px, three-interval bar. Never treat it as the viewport span.
- `ReliefTileCache` is the only writer of the disposable `relief-cache` volume. Never give GDAL an unbounded remote source or write detail data into `map-data`. Limits are in [development.md](docs/development.md#detailed-relief-internals).
- Calibrate tone against low-relief land patches, not the whole image histogram (the 177 km Alps fixture checks the Po plain). Keep blue-green lowlands and brown/cream uplands close to the Natural Earth overview.
- Clip land polygons to the mosaic's geographic coverage before projecting the mask, or Lambert views lose land. Split clip rectangles at the antimeridian and keep coastline and water holes.
- Internal raster and land-mask layers never appear in document state or service capabilities.
- Relief caches aren't backed up; the feature flag is validated and kept on recovery.
- Keep WMS normalized bbox, CRS and axis order separate from its default world map, and include the native aspect-adjusted extent.

### Lambert seams

- Lambert output joins longitudes without `+over`, so the seam is at `lon_0`+180 while MapServer cuts only at ±180. `wrapLambertLayers` slits unlabelled shapefile polygon and line layers along that meridian through an OGR VRT `SrcRegion`.
- `WrappedRasterSource` creates private per-request three-world VRTs for global WGS84 rasters and sets `lon_wrap` to the actual central meridian. It runs after the final projection and before detailed terrain. WMS uses its actual GetMap CRS; capabilities, WFS and SVG don't wrap.
- Never rewrite imagery or force whole-image loading to close a seam.
- Geographic close-ups crossing ±180° omit the non-canonical half with or without detailed relief. Compare feature-on and feature-off renders before blaming the tile cache or clipping.

## Application

- The original map document is the canonical request. Keep `bbox_map`, `projection_map` and `origin_map` together. Responses include `rendered_origin`, which the browser echoes into `origin_map`. Projection changes need explicit extent transformation. Never mutate the shared projection registry per request.
- Sub-path deployments: `SIMPLEMAPPR_URL` has a path behind a prefix-stripping proxy. Links, assets, redirects and `MAPPR_MAPS_URL` use `SiteUrl::path()` / `{{ base }}`; browser requests use `baseUrl`. Never hardcode a root-relative URL. Run the modern HTTP tests with `SIMPLEMAPPR_URL` unset.
- Guest defaults: `config/defaults.php`; overrides: ignored `config/conf.php`.
- Errors are logged; binary responses stay clean. The 11 GeoPHP deprecations must never reach a response body.
- Every standalone page extends `views/base.html` with the `.page`/`.card` structure and no inline layout (`TemplateTest` enforces this). Tab fragments are XHR-only; a direct visit gets a 303 to `/?locale=…#tabs=NAME`, and the hash restore takes names because positions shift by role.
- The modern suite is PHPUnit 13. Don't delete the archived suites, and never claim a modern pass covers persistence.

## Persistence and accounts

- MariaDB 11.8.9 is pinned by digest with no published port. Never log deployment credentials. `database-init` is the only schema, provision and lookup writer.
- `scripts/migrate-database` creates or verifies `20261020000000` and refuses implicit adoption. Never run `db/legacy-migrations`. Adopted shares stay private until the owner creates a token.
- Every saved-map operation uses the server-side uid. No role bypasses map ownership. Mutations require session CSRF.
- `SavedMapDocument` owns the versioned settings allowlist (case-sensitive layer names, viewport tuple). Settings JSON is not GeoJSON.
- Private previews use `TemporaryImage` outside public assets behind an expiring owner-checked route. Guest names carry 128 random bits.

### Social sign-in

- `SocialLoginConfig` owns fixed endpoints and credential validation, with no Composer and no network. `SocialLogin` owns state, PKCE, the bounded exchange, Google nonce and claims, and the GitHub profile.
- GitHub sends an explicit empty scope and calls `GET https://api.github.com/user` with a bearer header. Identities are `oauth:github:<id>` and `oidc:google:<issuer/subject hash>`.
- Never select ownership by name or email, never link accounts by email, and never assign the owner's UID. Store no provider email or tokens. New accounts are ordinary users.
- The owner login is at `OwnerLogin::path()` (`SIMPLEMAPPR_OWNER_LOGIN_PATH`, default `/owner`). It is unlinked, `noindex`, and a 404 without an owner. Never list it in `robots.txt`. `check-config` rejects bad values, and backups keep the value.
- Local and provider sessions use separate credential fingerprints. Database and session state use separate volumes.
- Keep SameSite=Lax because providers return query callbacks. Callbacks keep the `SIMPLEMAPPR_URL` prefix. Secure cookies come from the configured HTTPS origin, never from forwarded headers. Apache logs omit query strings and referrers.

## Imports and services

- `ImportData` parses bounded bytes with explicit encoding, rejects XML entities, and never unlinks a caller's path. Multipart uploads must pass `is_uploaded_file`.
- `RemoteImport` validates every DNS answer and redirect, pins the address, verifies TLS and ignores inherited proxies. Its 15 s deadline includes a killable DNS process; bodies are capped at 5 MiB decoded and headers at 64 KiB. Never add a private-host override in production.
- `OgcService` bounds KVP and XML requests. Advertise only tested CRS values. MapServer errors stay XML; API errors stay JSON.
- `Tests/smoke/check-import-boundary` runs from the host and checks real transport limits.

## Frontend

- Keep the Node digest and lock integrity pinned. PHP serves `public/build/manifest.json` and never writes assets.
- Vendored Jcrop, ColorPicker and Tiny Circleslider keep their hashes and notices; npm audit doesn't cover them.
- Browser documents follow schema v1. Accept `[]` only as an empty dictionary. Preserve raw invalid rows, underscore layer names and explicit `false`.
- Browser state is scoped to the owner or guest namespace. Only signed-in owners migrate legacy history. Never store CSRF or transport fields.

## Private release operations

- Publish with the manual `Publish release` workflow (enter a version such as `4.1`). It refuses unless `Private release checks` passed on master, then `scripts/set-release-version` updates `CITATION.cff` and the About citation, commits, tags `vVERSION` and creates the release; Zenodo archives it. The all-versions DOI `10.5281/zenodo.23626206` never changes. Deploy afterwards.
- Optional-dataset gates need the `optional_assets` workflow input.
- `scripts/backup` retains the running image under a `simplemappr-backup:` tag. `scripts/restore` only creates new projects and verifies the bundle before touching Docker.
- `Tests/browser` pins the Playwright image digest and its exact npm version. Owner credentials stay in a separate 0600 file and login is never traced. Keep private fixtures out of build output and receipts.
- Never run `check-browser` owner checks or `check-backup-restore` against the operator's main deployment.
