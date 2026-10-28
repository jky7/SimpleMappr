# Development and runtime reference

Everything runs in Docker; the host needs no PHP or MapScript. Run commands from the project root. See also [Install and run](private-deployment.md) and [Editor build](frontend.md).

## Check the runtime and application

```sh
docker compose exec app scripts/check-runtime
docker compose exec app Tests/smoke/check-http
docker compose exec app vendor/bin/phpunit -c Tests/phpunit.modern.xml
curl --fail http://127.0.0.1:8080/_runtime
```

`check-runtime` renders native PNG, SVG, TIFF and Lambert outputs without loading application code. View them under `/_checks/`, for example `http://127.0.0.1:8080/_checks/native-baseline.png`. What each check covers is in [Feature verification](feature-verification.md).

Run the modern HTTP tests against an app with `SIMPLEMAPPR_URL` unset, because they assume a root deployment.

## Map data

```sh
docker compose exec app scripts/bootstrap-assets --check
docker compose run --rm assets-init
```

`Tests/fixtures/manifest.json` defines `minimal-vector-v1`: five Natural Earth datasets with archive and member SHA256 hashes. Installing them needs no network. Only `assets-init` writes the `map-data` volume, and the app mounts it read-only. Re-running verifies files without rewriting them, and a missing or corrupt file fails with its name.

Optional profiles are listed in `config/asset-profiles/index.json`. After changing the ecoregion source, check the generated style with `scripts/generate-ecoregion-style --check`.

## Runtime image

The Dockerfile pins the Debian base by digest and uses Debian snapshot `20261021T134156Z`, which provides PHP 8.4.27+ and the matching `php-mapscript-ng`. List installed packages with:

```sh
docker compose exec app cat /usr/local/share/simplemappr-packages.tsv
```

- Update the base digest and snapshot together, then rebuild and rerun all checks. The snapshot pins packages, but the image isn't byte-for-byte reproducible.
- PHPPresentation is pinned to upstream commit `67a3b856…` because its stable release needs a vulnerable PhpSpreadsheet. Replace the pin when a fixed release ships.
- Apache serves only the front controller, `/_runtime`, `/_checks/` and public assets. Source, config, vendor and `.git` stay outside the document root. Never point Apache at the checkout root.

## Configuration and request limits

- Guest defaults: `config/defaults.php`. Optional overrides: an ignored `config/conf.php`. A `config/shapefiles.yml` replaces the bundled dataset config.
- Viewports carry `bbox_map`, `projection_map` and `origin_map` together, so custom Lambert origins survive refreshes. Switching to a projection that can't show the current view picks a valid area instead.
- Exports are limited to 5000 px per side and 20 megapixels. Invalid requests return JSON errors.
- Dependency deprecations (11 from GeoPHP) are logged, never written into responses.

## Tests

The modern PHPUnit 13 suite covers coordinate parsing, templates, HTTP previews and downloads, all 11 projections, origin refreshes, request limits and the API. It also runs the original coordinate cases with float tolerances.

Persistence, browser, import-boundary, social sign-in and backup checks are separate scripts listed in [Feature verification](feature-verification.md). The archived database, Selenium and router suites under `Tests/` still need their bootstrap migrated, so a modern-suite pass says nothing about them.

## Owner account

`SIMPLEMAPPR_OWNER_USERNAME` and `SIMPLEMAPPR_OWNER_PASSWORD_HASH` define the single local login. Without them, guest mapping still works. Changing either invalidates owner sessions. `scripts/provision-owner` updates the stable `local:owner` identity and never reassigns other users' maps. To change the password, see [Change the owner password](private-deployment.md#change-the-owner-password).

## Database

The schema version is `20261020000000`. First startup creates it and 4,582 region lookup rows. `scripts/migrate-database` is safe to repeat. The old Phinx migrations in `db/legacy-migrations/` are for reference only; never run them.

### Adopting a legacy database

Load the old dump into the Compose database, then run adoption before normal startup:

```sh
mkdir -p backups
docker compose up -d db
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" -v "$PWD/backups:/backups" database-init scripts/adopt-legacy-database /backups/before-adoption.sql
docker compose up -d --wait
```

Adoption checks known columns, JSON settings, duplicate rows and orphaned owners before changing anything. It writes a new backup (and refuses an existing filename), and it verifies that map JSON bytes are unchanged. Fix any duplicates or orphans it reports, then retry. Old shares stay private until their owner creates a new link. `Tests/fixtures/legacy-sample.sql` is a schema fixture, not real data.

## Render cache

`RenderCache` keys on the whole request (excluding the clock and the random download token), plus the data profile, relief flag, public URL, and the size and mtime of source, config and dataset files. A deploy or dataset change therefore starts a fresh cache. Each hit goes through `TemporaryImage`, so owner and guest privacy is unchanged. Detailed-relief renders that fell back to the overview are not stored.

## Detailed relief internals

- Tiles come from fixed NASA GIBS ASTER endpoints: greyscale JPEGs plus colour-index PNGs (`index-` cache names) whose palette must match `config/aster-gdem-color-index.json`.
- Limits: at most 256 tiles per map, 4 concurrent fetches, source level 12 at most, and a 15-second deadline covering DNS, transfer and cache locks.
- Grey relief applies the lookup curve `0:50,32:50,128:132,144:150,192:225,255:239`. Colour relief maps palette indices to `DetailedRelief::TINTS`, then blends the grey mosaic over it (soft-light 85%, multiply 25%) and draws Natural Earth lakes and rivers on top.
- Calibration fixtures: the 177 km Alps view checks the Po plain median.

The Landsat path (`landsat-` cache names, `DatasetCredits::DETAILED_IMAGERY`) served only the retired Blue Marble layer and can't be reached from the editor.

## Release checks

```sh
scripts/check-browser --url http://127.0.0.1:8080
scripts/check-browser --url http://127.0.0.1:18082 --credentials-file /private/fixture-credentials.json
scripts/check-backup-restore --private-dir /private/new-rehearsal-directory
```

The browser check runs Chromium and Firefox through all eight download formats, reloads, failure recovery, a 375 px layout, keyboard use and Axe WCAG 2.1 AA. Owner tests need a separate mode 0600 credential file; recording starts after login, and traces are sanitized before they're kept. Owner and backup checks create records, so run them only on disposable deployments.

The `Private release checks` workflow runs the minimal-profile native, PHP, import, persistence, browser and operations gates. Optional datasets run only when you set the `optional_assets` input. Publish with the `Publish release` workflow; see [AGENTS.md](../AGENTS.md#private-release-operations).

### Update cadence

Review dependencies monthly, and immediately for a relevant security advisory. Update each pinned item as one change: Debian digest and snapshot, MariaDB digest, Node digest, browser image digest and its exact npm version. Rerun every gate and check for license changes. npm audit doesn't cover the vendored Jcrop, ColorPicker and Tiny Circleslider plugins, so review those by hand.

### Performance budget

Time warm startup and 20 warm renders on the same fixture, machine and image, separately from data downloads. A release fails if the median exceeds both 1.25× the baseline and baseline + 1 s. Restore must finish within 120 s and reopen within 30 s. Optional relief has its own budget.
