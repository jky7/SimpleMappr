# Development and runtime reference

Use the Docker runtime for PHP and MapScript. Run the commands below from the project root.
For installation, credentials, backups, and updates, see [Install and manage SimpleMappr](private-deployment.md).
For the browser build and editor state, see [Build and verify the editor](frontend.md).

## Check the runtime and application

```sh
docker compose exec app scripts/check-runtime
docker compose exec app Tests/smoke/check-http
docker compose exec app vendor/bin/phpunit -c Tests/phpunit.modern.xml
curl --fail http://127.0.0.1:8080/_runtime
```

Open `http://127.0.0.1:8080/_checks/native-baseline.png` for the independent native fixture.
The native check also creates SVG, TIFF, Lambert PNG, and `report.json` files under `/_checks/`.
See [Feature verification](feature-verification.md) for coverage and known limits.

## Verify the bundled data

```sh
docker compose exec app scripts/bootstrap-assets --check
docker compose run --rm assets-init
```

`Tests/fixtures/manifest.json` defines the `minimal-vector-v1` profile. It records archive and member SHA256 hashes for the five bundled Natural Earth datasets. Initialization makes no network requests. It installs physical layers under `mapserver/maps/10m_physical` and cultural layers under `mapserver/maps/10m_cultural/10m_cultural`.

Only `assets-init` writes the `map-data` volume. The app mounts that volume read-only. Native outputs use the separate `runtime-output` volume. Repeated initialization verifies existing files without rewriting them. Missing or corrupt components produce the exact filename and a failing exit code.

The minimal profile contains land, lakes, country map units, province polygons, and province boundaries. Optional extended-vector and Gray Earth relief profiles add verified roads, rivers, oceans, labels and shaded relief. See [private deployment and recovery](private-deployment.md) for installation, backup/restore, owner rotation and release checks.

## Rebuild the runtime

The Dockerfile pins the Debian base image by digest and uses the Debian and Debian Security snapshots dated `20261021T134156Z`. The build requires PHP 8.4.27 or newer, which fixes [CVE-2026-109618 and CVE-2026-109619](https://www.php.net/archive/2026.php#2026-10-21-5). Apache mod_php and PHP CLI load the distribution's matching `php-mapscript-ng` package. `check-runtime` loads that binding directly. Composer installs the locked application dependencies during the image build. PHPPresentation is pinned to upstream commit `67a3b85673a7e05c7347e0c3970f9afb2338e246` because its stable release requires a vulnerable PhpSpreadsheet major. Replace that pin once a compatible stable release ships.

To inspect installed package versions, run:

```sh
docker compose exec app cat /usr/local/share/simplemappr-packages.tsv
```

Update the base digest and snapshot deliberately when applying runtime security updates. A rebuild needs Docker Hub and Debian snapshot access. The snapshot pins package selection, but the Docker image is not guaranteed to be byte-for-byte reproducible.

The Apache document root contains only the application front controller. Public assets have a separate alias. Generated maps, uploads and asset caches use separate writable volumes. PHP execution under public assets is denied. `/_runtime` runs a small native render through Apache without loading legacy application configuration. The endpoint exposes versions, binding readiness, and the profile name. It does not expose PHP configuration. `/_checks/` serves only named native output files and the report. Source, configuration, fixtures, vendor files, and Git metadata are outside the document root.

`docker compose down` retains database, session, data and output volumes. `docker compose down --volumes` deletes that stored state.

## Configuration and current limits

`config/defaults.php` provides guest defaults. Set `SIMPLEMAPPR_URL` for an explicit external origin or keep the default relative URLs for localhost. An optional ignored `config/conf.php` can define overrides. The checked-in minimal dataset configuration is used unless `config/shapefiles.yml` exists. Do not expose the repository root through Apache.

Requests keep the original map document fields. Viewports additionally carry `origin_map` with `projection_map` and `bbox_map`, so custom Lambert origins survive repeated refreshes. Changing projections selects a valid geographic area when the current view falls outside that projection. Export dimensions are limited to 5000 pixels per side and 20 megapixels. Invalid requests return JSON errors and preserve the next valid render.

The modern PHPUnit suite covers coordinate parsing, all templates, real HTTP previews and downloads, all eleven projections, custom origin refreshes, request limits and basic API points. It also runs the original coordinate conversion cases with explicit floating point tolerances. The archived database, binary comparison, router and Selenium suites remain in `Tests/`. Their old global database/bootstrap assumptions still require migration; the modern persistence and browser checks are separate suites. The modern suite does not claim those suites pass.

Imports, Office exports, WMS/WFS and browser workflows have modern contract checks. Specialist ecology and unverified raster datasets remain unavailable. Dependency deprecations are logged rather than inserted into JSON or binary output.

## Private maps and shared links

Sign in from the editor header. Save from Preview, then reopen the map from My Maps. The database keeps Unicode titles, multiline data, styles and source projection state. A second browser session cannot read a private map by its numeric identifier. An administrator role does not bypass map ownership.

Use **Share** in My Maps to create a public link. **Open share** displays a read-only map without save controls. **Unshare** revokes the token immediately. Numeric map URLs remain private. Settings JSON downloads are distinct from GeoJSON exports; import a settings file from My Maps to restore the complete map configuration. Existing unversioned settings files remain readable.

Authenticated preview, legend and scale images live outside the public directory and expire after one hour; fetching them requires their owner session. Guest previews use random filenames and reject reads after one hour. Expired files are cleaned during subsequent image creation. Sessions use HttpOnly, SameSite=Lax cookies and a two-hour idle timeout. Login attempts are limited per source address. Configure TLS before exposing the instance beyond localhost.

`SIMPLEMAPPR_OWNER_USERNAME` and `SIMPLEMAPPR_OWNER_PASSWORD_HASH` select the one deployment login. Missing owner configuration leaves guest rendering available. Changing either value and recreating the stack invalidates previous authenticated sessions. `scripts/provision-owner` updates the stable `local:owner` identity; it does not reassign imported users' maps. For password changes, use the [owner password rotation procedure](private-deployment.md#rotate-the-local-owner-password).

## Database baseline and legacy adoption

The current schema version is `20261020000000`. First startup creates it and generates 4,582 region lookup rows from the pinned Natural Earth province dataset. `scripts/migrate-database` is repeatable. Historical Phinx migrations are retained under `db/legacy-migrations/` for reference and must not be replayed against an existing database.

Import an old database into a separate MariaDB database first. The checked-in `Tests/fixtures/legacy-sample.sql` is the archived schema fixture, not a backup of the hosted service. Adoption checks known columns, JSON settings, duplicate rows and orphan ownership before making changes. It writes a new SQL backup and checks that original map JSON bytes remain unchanged. Existing numeric shares receive no public token. Resolve reported duplicate or orphan rows deliberately before retrying.

For a database already imported into the configured Compose database, create a local `backups` directory and run the explicit adoption command before normal startup:

```sh
mkdir -p backups
docker compose up -d db
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" -v "$PWD/backups:/backups" database-init scripts/adopt-legacy-database /backups/before-adoption.sql
docker compose up -d --wait
```

The command refuses an existing backup filename. Keep this backup and the deployment `.env` outside version control. A source-code restore cannot recover private map records that are unavailable from the archived hosted service.
