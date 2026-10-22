# Private deployment and recovery

The supported deployment is a localhost Docker Compose instance with one local owner. The owner has the administrator role for citation maintenance. Every saved-map read and mutation still checks its owning user. TLS and access controls belong at an operator-managed reverse proxy if the instance is exposed beyond localhost.

## Start and verify

Use the commands in the [README](../README.md). Keep an existing `.env`; `configure-local-owner` refuses to replace it. The PHP runtime and SWIG MapScript extension come from one pinned Debian snapshot. No PHP installation is needed on the host. Compose defaults to `SIMPLEMAPPR_ENV=production`, localhost port 8080, relative application URLs, and `minimal-vector-v1` data. Supported environment values are `development`, `production`, and `testing`.

`SIMPLEMAPPR_URL` can set a credential-free HTTP(S) base URL or a relative base path. It controls links and the map watermark. Keep its hostname consistent with the URL used in the browser, since owner cookies belong to that host. `scripts/check-config` validates deployment configuration before Apache starts. `/_runtime` independently checks PHP and native MapScript without loading Composer; it reports the selected asset profile, not an application feature or database health claim.

MariaDB has no published port. The app mounts map data read-only. Only `assets-init` installs map data; `database-init` owns schema/provisioning and region lookup generation. Apache serves a restricted front controller document root. Never replace it with the checkout root.

## Choose map data

| Profile | Data and controls |
| --- | --- |
| `minimal-vector-v1` | Bundled land, lakes, countries, province polygons and province boundaries; country/province labels. No downloads at startup. |
| `extended-vector-v1` | Minimal plus rivers, oceans, roads, railroads, river/place/physical/marine labels. Seven pinned Natural Earth archives. |
| `gray-relief-v1` | Extended plus `reliefgrey`, using Natural Earth Gray Earth medium-resolution shaded relief. Eight optional archives total, approximately 85 MiB compressed. |

`config/asset-profiles/index.json` selects the configuration and manifests. Each manifest records source URL, data version, redistribution terms, archive bytes/hash and every extracted member hash. The Gray Earth 2.0.0 raster is a documented replacement for the unavailable archived custom high-resolution raster; it is 16200×8100 in WGS84. It is not the old full-color relief. The railroad archive contains version 4.1.0 metadata; manifest versions reflect archive contents.

Fetch optional assets on the host, then select the same profile and an absolute cache directory for Compose:

```sh
scripts/fetch-assets --profile gray-relief-v1
SIMPLEMAPPR_ASSET_PROFILE=gray-relief-v1 \
SIMPLEMAPPR_ASSET_CACHE="$PWD/.asset-cache" \
  docker compose up -d --no-build --wait
```

For subsequent starts, exports, and backups, retain these settings in the deployment's private `.env`, or consistently supply the same explicit environment and `--env-file`. Do not regenerate existing database credentials. `fetch-assets` creates its host cache as the current user, verifies already-cached files, downloads only pinned HTTPS Natural Earth CDN archives, and publishes files only after size/hash verification. A corrupt existing file causes a refusal; investigate it before replacing it.

Minimal installations use an empty named cache volume, so Docker does not create a root-owned host `.asset-cache`. Optional profiles use the explicitly selected host cache. Keep this cache outside images and Git. App startup makes no dataset downloads and refuses missing or corrupt selected data. To inspect installed bytes, versions, sources and excluded controls:

```sh
docker compose exec app scripts/bootstrap-assets --check
docker compose exec app scripts/asset-availability
```

When an optional profile is selected only through shell variables, supply them on these commands too. Full-color relief, alternate relief, Blue Marble, conservation hotspots, terrestrial ecoregions and marine ecoregions remain unavailable: matching assets, mappings or redistribution terms have not been verified. The availability report states each exclusion. A profile change does not delete another profile's files; it selects only the declared controls and validates its own inventory.

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

## Rotate the local owner password

```sh
scripts/rotate-local-owner --env-file "$PWD/.env"
docker compose up -d --no-build --wait --force-recreate database-init app
```

The script prompts without echoing the password and preserves unrelated configuration bytes, including database passwords. It stores only a new bcrypt hash. Reprovisioning retains the stable `local:owner` identity and its map ownership. Previous passwords and authenticated sessions fail after recreation. Keep the new credentials in your own password manager. Do not rerun initial configuration over an established deployment.

## Citations, sessions and cleanup

Administration supports plain-text citation references, author surname, bounded four-digit year, HTTP(S) link and DOI. Invalid input returns a field error without saving arbitrary columns. All mutations require administrator authorization and session CSRF. Citation text is displayed as text; HTML is not interpreted. RSS uses escaped text, stable ordering/GUIDs and absolute same-origin links, with DOI links preferred when present. It sends no third-party hub request.

Private preview/legend/scale files expire after one hour and require the owning session. Guest filenames contain 128 random bits and expired reads fail. Image creation periodically removes only correctly named expired generated files and their matching private metadata. It preserves unrelated files and saved maps. About and error pages do not load remote analytics or badges by default. Map rendering uses local installed data. User-requested remote imports deliberately make validated outbound HTTP requests; see [the import boundary](import-export.md).

## Release checks and updates

The pinned browser container runs Chromium and Firefox, all eight downloads, mixed documents, reload, failed requests/images/downloads and recovery. It checks 375px layout, keyboard controls and WCAG 2 A/AA plus 2.1 A/AA with Axe. Owner UI tests accept a separate mode 0600 synthetic credential file; recording starts after login. Raw traces are staged privately, sanitized with known-secret checks, and published only after sanitization succeeds.

```sh
scripts/check-browser --url http://127.0.0.1:8080
scripts/check-browser --url http://127.0.0.1:18082 --credentials-file /private/fixture-credentials.json
scripts/check-backup-restore --private-dir /private/new-rehearsal-directory
```

Owner and operations checks create synthetic persistent records; run them on disposable deployments. Native and PHP commands are in the README. The GitHub release workflow checks the explicit reviewed PHPPresentation commit pin and runs ordinary Composer validation with its known pin warning visible; it does not claim strict validation passes. It also audits Composer/npm dependencies, checks PHP syntax and platform requirements, exercises the isolated import transport fixture, and rehearses backup/restore/rotation. Required remote checks must actually complete successfully before release. Archived Travis configuration remains until the replacement workflow has demonstrated a successful run.

Review dependencies and image/package updates monthly, and immediately for relevant security advisories. Update the Debian base digest and snapshot together, the matching PHP/MapScript packages, MariaDB digest, Node digest, browser image digest and exact browser npm version as coherent changes. Regenerate locks deliberately; rerun native/PHP/import/HTTP/browser/recovery gates and inspect license changes. Audit retained frontend plugins separately: Jcrop, ColorPicker and Tiny Circleslider are pinned by input hashes and notices, but npm audit does not cover their vendored code. GeoPHP currently emits 11 implicit-nullable notices under PHP8.4; logs retain them while JSON and binary output stay clean.

Record warm startup and 20 warm render timings with the same fixture, machine and pinned image provenance. Separate dataset installation/cold downloads from warm runtime comparisons. A relative slowdown is a release failure when the median exceeds both 1.25×baseline and baseline + 1 second; restore must complete within 120 seconds and reopen within 30 seconds for the declared fixture. Optional relief has a separate render budget because its raster work differs from minimal vectors. Keep decoded-output evidence alongside timings.
