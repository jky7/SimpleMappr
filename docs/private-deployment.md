# Private deployment and recovery

The default deployment is a localhost Docker Compose instance with one local owner. Optional [Google and GitHub sign-in](social-login.md) allows people to register separate accounts. The local owner has the administrator role for citation maintenance. Every saved-map read and mutation still checks its owning user. TLS and access controls belong at an operator-managed reverse proxy if the instance is exposed beyond localhost.

## Start and verify

Install Docker with Docker Compose, then open a terminal in the project folder.
For a new installation, run:

```sh
docker build -t simplemappr-runtime:local .
scripts/configure-local-owner
docker compose up --build -d --wait
```

Open `http://127.0.0.1:8080/` for the editor. The setup command asks for an owner username and password, then creates a mode-0600 ignored `.env` with a password hash and random database credentials. It never saves the plaintext password. Add `SIMPLEMAPPR_PORT=8081` to that file if port 8080 is occupied. Docker Compose binds to localhost.

Keep an existing `.env`; `configure-local-owner` refuses to replace it. The PHP runtime and SWIG MapScript extension come from one pinned Debian snapshot. No PHP installation is needed on the host. Compose defaults to `SIMPLEMAPPR_ENV=production`, localhost port 8080, relative application URLs, and `minimal-vector-v1` data. Supported environment values are `development`, `production`, and `testing`.

`SIMPLEMAPPR_URL` can set a credential-free HTTP(S) base URL or a relative base path. It controls links and the map watermark. Keep its hostname consistent with the URL used in the browser, since owner cookies belong to that host. `scripts/check-config` validates deployment configuration before Apache starts. `/_runtime` independently checks PHP and native MapScript without loading Composer; it reports the selected asset profile, not an application feature or database health claim.

MariaDB has no published port. The app mounts map data read-only. Only `assets-init` installs map data; `database-init` owns schema/provisioning and region lookup generation. Apache serves a restricted front controller document root. Never replace it with the checkout root.

After startup, run the [runtime and application checks](development.md#check-the-runtime-and-application).

## Choose map data

| Profile | Data and controls |
| --- | --- |
| `minimal-vector-v1` | Bundled land, lakes, countries, province polygons and province boundaries; country/province labels. No downloads at startup. |
| `extended-vector-v1` | Minimal plus rivers, oceans, roads, railroads, river/place/physical/marine labels. Seven pinned Natural Earth archives. |
| `gray-relief-v1` | Extended plus `reliefgrey`, using Natural Earth Gray Earth medium-resolution shaded relief. Eight optional archives total, approximately 85 MiB compressed. |
| `full-map-v1` | Gray relief plus color shaded relief, Natural Earth II, NASA Blue Marble, lake names, RESOLVE Ecoregions 2017 and Biodiversity Hotspots 2016.1 with labels. Thirteen optional source files, approximately 829 MiB downloaded and 1.39 GiB installed. |

`config/asset-profiles/index.json` selects the configuration and manifests. Each manifest records source URL, data version, redistribution terms, archive bytes/hash and every extracted member hash. The Gray Earth 2.0.0 raster is a documented replacement for the unavailable archived custom high-resolution raster; it is 16200×8100 in WGS84. The railroad archive contains version 4.1.0 metadata; manifest versions reflect archive contents.

Color relief and Natural Earth II use the public-domain Natural Earth 2.0.0 rasters at 16200×8100. Blue Marble uses the original NASA 2002 RGB image at 21600×10800. The pinned TIFF has no georeferencing. `config/blue-marble.vrt` supplies its documented global WGS84 extent without changing the source bytes.

Fetch optional assets on the host, then select the same profile and an absolute cache directory for Compose:

```sh
scripts/fetch-assets --profile full-map-v1
SIMPLEMAPPR_ASSET_PROFILE=full-map-v1 \
SIMPLEMAPPR_ASSET_CACHE="$PWD/.asset-cache" \
  docker compose up -d --no-build --wait
```

For subsequent starts, exports, and backups, retain these settings in the deployment's private `.env`, or consistently supply the same explicit environment and `--env-file`. Do not regenerate existing database credentials. `fetch-assets` creates its host cache as the current user, verifies already-cached files, downloads only pinned HTTPS Natural Earth, NASA, RESOLVE and Zenodo sources, and publishes files only after size/hash verification. A corrupt existing file causes a refusal; investigate it before replacing it.

Minimal installations use an empty named cache volume, so Docker does not create a root-owned host `.asset-cache`. Optional profiles use the explicitly selected host cache. Keep this cache outside images and Git. App startup makes no dataset downloads and refuses missing or corrupt selected data. To inspect installed bytes, versions, sources and excluded controls:

```sh
docker compose exec app scripts/bootstrap-assets --check
docker compose exec app scripts/asset-availability
```

When an optional profile is selected only through shell variables, supply them on these commands too. Marine ecoregions remain unavailable because source terms restrict redistribution and commercial use. The availability report states each exclusion. A profile change does not delete another profile's files; it selects only the declared controls and validates its own inventory.

RESOLVE ecoregions use CC BY 4.0 and hotspots use CC BY-SA 4.0. Their selected map layers always include readable credits in rendered images, SVG and Office exports, independently of optional legends and watermarks. Credits scale with high-resolution downloads. The public `/data-credits` page lists creators, source links, licenses and display changes. Retain those credits when publishing maps. These background licenses do not automatically license independent user coordinates and drawings. GeoJSON and KML exports contain only user geometry.

Ecological renders need at least 160 logical pixels of width and enough height for the wrapped credits, at 13 pixels per line plus 24 pixels of padding. Publication images reserve another 36 logical pixels below the credits for an embedded scalebar, or 12 for a watermark alone. High-resolution downloads scale both requirements by `download_factor`. A request that cannot fit the credits fails with a useful error rather than clipping the notice. WMS failures remain XML. Both ecological backgrounds fit a 256×256 WMS image.

The hotspot source archive contains 53 records. Both its polygon and label layers select the 36 `hotspot area` records and exclude 17 `outer limit` records. WFS uses `config/hotspots-2016.vrt` for this subset before client filtering, preserves source feature IDs, and adds `mappr_license` and `mappr_source` fields. Service capabilities and HTTP Link headers identify the data credits independently of requested properties. Ecoregion colors are generated from the pinned `ECO_ID` and `COLOR` fields. Run `scripts/generate-ecoregion-style --check` against the retained archive to verify the checked-in style.

When changing profiles, recreate the managed `assets-init` service with the selected profile and cache mount before backing up. A one-off asset install does not update the managed service's cache provenance, and backup refuses that mismatch.

## Back up a deployment

Choose a new destination outside the checkout, uploads and public directories. The destination must not exist. The parent directory must already exist and should be private.

```sh
mkdir -m700 "$HOME/simplemappr-recovery"
scripts/backup "$HOME/simplemappr-recovery/before-update" \
  --project simplemappr --env-file "$PWD/.env"
```

The source app must be running and its credentials must match the supplied mode 0600 configuration. The command writes a mode 0700 bundle with mode 0600 members: a streamed transactional SQL dump, deployment configuration, and a manifest published last. It records schema, source project, exact image ID, selected profile, member sizes and SHA256 hashes. `checkoutSha` and `checkoutDirty` describe the working checkout; they do not assert that dirty source produced the recorded image. An image revision is recorded only when its own image label declares it.

Backup automatically retains the inspected running image under `simplemappr-backup:sha256-IMAGEHEX`. Keep that tag: rebuilding the mutable `simplemappr-runtime:local` tag can discard old image metadata even while a container continues running. Docker pruning can still remove unused retained images if explicitly requested. A bundle contains deployment data, not Docker image layers or optional map-data archives.

To move recovery material to another Docker host, retain both pinned images and the optional archive cache. Save the application image by its retained tag and the exact MariaDB image declared by `compose.yaml`, then transfer and load them with Docker's `save`/`load` commands. Verify that `docker image inspect` resolves the manifest's recorded application ID after loading. Keep the export beside the protected recovery material, rather than under uploads. An immutable local ID alone is not a portable image archive. The restore command refuses an unavailable or mismatched image.

For explicitly mounted configuration overrides, add the relevant fixed option:

```sh
scripts/backup "$HOME/simplemappr-recovery/with-overrides" \
  --project simplemappr --env-file "$PWD/.env" \
  --include-override config/conf.php \
  --include-override config/shapefiles.yml
```

Only these two override paths are supported. Each must be an actual read-only file bind mount in the running app; backup reads that mount's bytes. Declare all overrides deliberately. Ordinary images exclude these ignored files. Custom PHP configuration is executable trusted operator code and must be reviewed before use.

## Restore to an empty clone

Restore creates a different Compose project. There is no overwrite option. Containers, networks or volumes already associated with the target cause refusal before import; an existing database must be empty. Keep the original deployment until the clone has passed review.

```sh
scripts/restore "$HOME/simplemappr-recovery/before-update" \
  --project simplemappr-restored --port 18082
```

The command prints the target port and protected deployment-directory path. Open the returned local target with the original owner credentials and check saved maps, shares and exports. Use a consistent hostname for private requests. Restore preserves relative URL settings; an existing absolute origin is rewritten for the local target. A rewritten watermark URL can change rendered pixels while preserving map data.

Restore validates all bundle members and checksums before contacting the database. SHA256 checks prove integrity, not authenticity: restore only operator-trusted bundles and SQL. The SQL client disables client shell metacommands, but SQL remains executable database input. It starts the pinned MariaDB image, imports SQL before schema initialization, verifies the supported schema and a deterministic redump, initializes reproducible assets, then starts the recorded application image. It preserves original map JSON bytes, owners, citations and active/revoked tokens. It creates fresh session/output volumes; it does not revive source login sessions, transient previews, uploads, logs, compiled CSS/JS caches or arbitrary files. Uploads are import inputs, not the canonical saved-map document. Optional-profile restore requires the preserved verified archive cache. Minimal restores keep the named empty cache default.

The default protected deployment directory is beside the bundle, named `PROJECT-deployment`. It contains `deployment.env` and `restore-compose.json`. Overrides are read-only bind-mounted from this directory. Their restored files are readable by Apache inside the container; the host directory remains mode 0700 and secrets/configuration files not mounted for Apache remain mode 0600.

Use both generated Compose files for later management:

```sh
docker compose -p simplemappr-restored \
  --env-file /absolute/PROJECT-deployment/deployment.env \
  -f "$PWD/compose.yaml" -f /absolute/PROJECT-deployment/restore-compose.json \
  down
```

`down` keeps stored state. `down --volumes` deletes that project's database and other volumes. Never apply it to the original deployment while validating recovery.

Rollback means restoring a backup created while the earlier compatible image was actually running. Keep that earlier image and its corresponding backup before upgrading. A bundle's image identity cannot be silently changed to select an older runtime. Test login, reopened map state and an export in the isolated earlier-image clone before changing an active deployment.

## Sign in as the local owner

The owner signs in on its own page, not on `/login`. The default is `/owner`; set `SIMPLEMAPPR_OWNER_LOGIN_PATH` in `.env` to move it, for example `SIMPLEMAPPR_OWNER_LOGIN_PATH=/owner-4f9c2a1b` (one lowercase segment of 3 to 63 letters, digits or hyphens). Because this repository is public, pick your own value rather than keeping the default; scanners probe names like `/admin` and `/login`. Recreate the app after changing it (`docker compose up -d`) and bookmark the result, which sits under the deployment prefix, for example `https://jky.io/simplemappr/owner-4f9c2a1b`. A malformed or built-in name stops the app starting with a clear message, and backups preserve the value and check it against the running app.

Nothing links to the page, it asks search engines not to index it, and it is a plain 404 unless an owner is configured (the old `/admin` is a 404 too). The public `/login` page offers only Google and GitHub, so an owner-only deployment shows a short message there. It is also the recovery login if a provider is misconfigured. Failed attempts are limited to 10 per 15 minutes per client address as the app sees it; behind a reverse proxy that is the proxy, so a burst of bad attempts from anywhere locks the form for everyone for 15 minutes. A hard-to-guess path keeps scanners from filling that budget.

## Rotate the local owner password

```sh
scripts/rotate-local-owner --env-file "$PWD/.env"
docker compose up -d --no-build --wait --force-recreate database-init app
```

The script prompts without echoing the password and preserves unrelated configuration bytes, including database passwords. It stores only a new bcrypt hash. Reprovisioning retains the stable `local:owner` identity and its map ownership. Previous passwords and authenticated sessions fail after recreation. Keep the new credentials in your own password manager. Do not rerun initial configuration over an established deployment.

## Citations, sessions and cleanup

Administration supports plain-text citation references, author surname, bounded four-digit year, HTTP(S) link and DOI. Invalid input returns a field error without saving arbitrary columns. All mutations require administrator authorization and session CSRF. Citation text is displayed as text; HTML is not interpreted. RSS uses escaped text, stable ordering/GUIDs and absolute same-origin links, with DOI links preferred when present. It sends no third-party hub request.

Private preview/legend/scale files expire after one hour and require the owning session. Guest filenames contain 128 random bits and expired reads fail. Image creation periodically removes only correctly named expired generated files and their matching private metadata. It preserves unrelated files and saved maps. About and error pages do not load remote analytics or badges by default. Map rendering uses local installed data. User-requested remote imports deliberately make validated outbound HTTP requests; see [the import boundary](import-export.md).

## Capacity tuning

The image caps Apache at 12 prefork workers (`docker/apache-tuning.conf`), recycles each after 200 requests and disables KeepAlive, so a burst of renders queues instead of exhausting memory and idle proxy connections do not pin workers. `compose.yaml` limits the app to 2500 MB and MariaDB to 768 MB with a 256 MB InnoDB buffer pool. These suit a 2 vCPU / 4 GB host; a measured test peaked at 8 workers, the largest near 240 MB. Override per host in the ignored `.env` with `SIMPLEMAPPR_APP_MEMORY`, `SIMPLEMAPPR_DB_MEMORY` and `SIMPLEMAPPR_DB_BUFFER_POOL`. Changing worker limits needs an image rebuild. A reverse proxy in front should enable response compression (for Caddy, `encode zstd gzip`). A small swap file with low `vm.swappiness` is a safety net, not a substitute for the limits.

## Caching

Apache marks `/public/build/` assets (content-hashed) `immutable` for a year and `/public/images/` for a day with background revalidation. Guests may reuse `/about` and `/data-credits` for five minutes (`Vary: Cookie`); signed-in responses stay `no-store`. `opcache.validate_timestamps=0` assumes the image is immutable, so every deploy restarts Apache. Do not bind-mount edited PHP into a running container and expect it to load without a restart.

`RenderCache` keeps finished JSON previews (map, legend, scalebar) in the disposable `render-cache` volume at `/var/cache/simplemappr-render`. The key is the whole request (minus the clock and random download token) plus the dataset profile, relief flag, public URL, and the size/mtime of source, config and dataset files, so a deploy or dataset change starts a fresh cache. A hit copies the cached bytes into a new `TemporaryImage`, so guest and owner previews keep their own random names and access checks. Downloads, SVG and TIFF are never cached, and a detailed-relief render that fell back to the overview is not stored. Entries expire after 24 hours and the cache is trimmed to 256 MiB by least recent use. Responses carry `X-Render-Cache: hit|miss`. Set `SIMPLEMAPPR_RENDER_CACHE=0` to disable it. The volume is optional and not backed up.

## Deploying updates

`scripts/deploy` on the server pulls the release branch (fast-forward only), builds the image tagged with the commit, checks that it starts as `www-data`, recreates the app, runs HTTP checks inside the container and rolls back to the previous image if they fail. The site stays up during the build. When files under `views/` changed it stops the app first and moves the compiled Twig directories aside, so no request can recompile a stale template and OPcache never holds one. Anything it cannot verify as compiled Twig is left alone, and preview images are never touched.

```sh
scripts/deploy              # on the server
scripts/deploy --dry-run    # pull, build and verify only; the running site is untouched
scripts/deploy --force      # redeploy the commit that is already running
```

From a workstation, run it over ssh after pulling there, for example `ssh HOST 'cd simplemappr && git pull -q --ff-only && scripts/deploy --no-pull'`; keep any wrapper for that outside the repository. Each run keeps the previous `.env` (mode 0600) in `~/.local/state/simplemappr-deploy`; only the image line is rewritten and no credential is printed. Old image tags and `.twig-before-deploy-*` directories are not pruned automatically.

## Release checks and updates

The pinned browser container runs Chromium and Firefox, all eight downloads, mixed documents, reload, failed requests/images/downloads and recovery. It checks 375px layout, keyboard controls and WCAG 2 A/AA plus 2.1 A/AA with Axe. Owner UI tests accept a separate mode 0600 synthetic credential file; recording starts after login. Raw traces are staged privately, sanitized with known-secret checks, and published only after sanitization succeeds.

```sh
scripts/check-browser --url http://127.0.0.1:8080
scripts/check-browser --url http://127.0.0.1:18082 --credentials-file /private/fixture-credentials.json
scripts/check-backup-restore --private-dir /private/new-rehearsal-directory
```

Owner and operations checks create synthetic persistent records; run them on disposable deployments. Native and PHP commands are in [Development and runtime reference](development.md#check-the-runtime-and-application). The GitHub release workflow checks the explicit reviewed PHPPresentation commit pin and runs ordinary Composer validation with its known pin warning visible; it does not claim strict validation passes. It also audits Composer/npm dependencies, checks PHP syntax and platform requirements, exercises the isolated import transport fixture, and rehearses backup/restore/rotation. Required remote checks must actually complete successfully before release. The obsolete Travis setup is retained under `docs/legacy-ci/` after the replacement workflow passed [its first complete run](https://github.com/jky7/SimpleMappr/actions/runs/39718169668).

Review dependencies and image/package updates monthly, and immediately for relevant security advisories. Update the Debian base digest and snapshot together, the matching PHP/MapScript packages, MariaDB digest, Node digest, browser image digest and exact browser npm version as coherent changes. Regenerate locks deliberately; rerun native/PHP/import/HTTP/browser/recovery gates and inspect license changes. Audit retained frontend plugins separately: Jcrop, ColorPicker and Tiny Circleslider are pinned by input hashes and notices, but npm audit does not cover their vendored code. GeoPHP currently emits 11 implicit-nullable notices under PHP8.4; logs retain them while JSON and binary output stay clean.

Record warm startup and 20 warm render timings with the same fixture, machine and pinned image provenance. Separate dataset installation/cold downloads from warm runtime comparisons. A relative slowdown is a release failure when the median exceeds both 1.25×baseline and baseline + 1 second; restore must complete within 120 seconds and reopen within 30 seconds for the declared fixture. Optional relief has a separate render budget because its raster work differs from minimal vectors. Keep decoded-output evidence alongside timings.

### Detailed grey relief

Set `SIMPLEMAPPR_DETAILED_RELIEF=1` in the protected deployment `.env` and recreate the app to enable detailed terrain automatically on close grey-relief views. The existing `reliefgrey` setting and saved maps keep working. The Gray Earth overview remains visible at wider scales and where ASTER has no coverage. Other backgrounds keep their original resolution. SVG retains its existing vector-only behavior.

The app fetches public NASA GIBS ASTER GDEM greyscale shaded-relief tiles for views spanning at most 600 km in Web Mercator. The finest source spacing is approximately 38 metres per pixel at the equator. The source covers land between 83 degrees south and north and can contain elevation artifacts. Increasing download dimensions selects a finer source level when available, up to level 12. Further enlargement cannot create terrain detail beyond that source.

Only public tile indices leave the server. Coordinates, labels, account details and map documents are never sent to NASA. The separate `relief-cache` Docker volume holds at most 1 GiB of reusable tiles. The cache worker validates bounded 256-pixel RGB JPEGs, fetches at most four simultaneously, and atomically publishes under a lock. Each map uses at most 256 tiles. A 15-second parent deadline also bounds DNS, transfers and cache contention. Missing source data produces an explicit temporary-unavailability error rather than an unnoticed resolution downgrade. Empty source tiles and the native land mask preserve the overview beneath ocean and uncovered areas.

The cache is disposable and excluded from protected backups. Recovery preserves the feature flag and downloads tiles again as needed, so detailed relief requires internet access after recovery. The app keeps the installed asset volume read-only. NASA/METI ASTER and NASA EOSDIS GIBS credits appear on maps using the detailed source and on the public data credits page.

Lambert views join the global background across the antimeridian through temporary VRT metadata. Source imagery stays unchanged, and the wrapper also applies to raster and Office downloads and supported WMS Lambert maps. SVG retains its vector-only behavior.

A separate limitation remains for geographic close views crossing the antimeridian. They currently omit the part outside the canonical longitude range of -180 to 180 degrees, including when detailed relief is enabled.
