# Install and run SimpleMappr

SimpleMappr runs as a Docker Compose stack on localhost with one local owner account. [Google and GitHub sign-in](social-login.md) can be added so other people get their own private accounts. To expose it beyond localhost, put a reverse proxy with TLS in front of it.

Related guides: [backup and restore](backup-restore.md), [development and checks](development.md).

## Install

```sh
docker build -t simplemappr-runtime:local .
scripts/configure-local-owner
docker compose up --build -d --wait
```

Open `http://127.0.0.1:8080/`, then run the [runtime checks](development.md#check-the-runtime-and-application).

`configure-local-owner` asks for an owner username and password, then writes `.env` (mode 0600) with a password hash and random database credentials. It never stores the plaintext password, and it refuses to overwrite an existing `.env`, so keep that file for the life of the deployment.

Settings in `.env`:

| Variable | Default | Notes |
| --- | --- | --- |
| `SIMPLEMAPPR_PORT` | `8080` | Always bound to localhost. |
| `SIMPLEMAPPR_ENV` | `production` | Or `development`, `testing`. |
| `SIMPLEMAPPR_URL` | relative URLs | Absolute base URL (no credentials) or a path. Sets links and the map watermark. Use the same hostname people type in the browser, since sign-in cookies belong to it. |
| `SIMPLEMAPPR_ASSET_PROFILE` | `minimal-vector-v1` | See [Map data](#map-data). |

`scripts/check-config` validates the configuration before Apache starts and reports errors without printing secrets. `/_runtime` checks PHP and MapScript and reports the data profile. It does not check the database.

## Map data

| Profile | Adds | Download |
| --- | --- | --- |
| `minimal-vector-v1` | Land, lakes, countries, provinces and their labels | Bundled |
| `extended-vector-v1` | Rivers, oceans, roads, railroads, place/physical/marine labels | 7 Natural Earth archives |
| `gray-relief-v1` | `relief (greyscale)` and `relief (greyscale alt)` | 9 archives, ~505 MiB |
| `full-map-v1` | Colour relief, Natural Earth II, lake names, RESOLVE Ecoregions 2017, Biodiversity Hotspots 2016.1 | 13 files, ~829 MiB (1.39 GiB installed) |

Each profile includes the ones above it. `full-map-v1` still downloads the pinned NASA Blue Marble image for provenance, but the Blue Marble layer is retired and no longer offered. Saved maps that selected it open without it.

To use an optional profile, fetch it on the host, then start Compose with the same profile and an absolute cache path:

```sh
scripts/fetch-assets --profile full-map-v1
SIMPLEMAPPR_ASSET_PROFILE=full-map-v1 \
SIMPLEMAPPR_ASSET_CACHE="$PWD/.asset-cache" \
  docker compose up -d --no-build --wait
```

Put both variables in `.env` so later starts, backups and checks use them too. If you pass them only on the command line, pass them on every command.

- `fetch-assets` downloads only pinned HTTPS sources, checks every size and hash, and refuses (rather than replaces) a corrupt cached file.
- The app never downloads map data at startup. It refuses to start if the selected data is missing or corrupt.
- Switching profiles leaves other profiles' files in place. Recreate the `assets-init` service with the new profile before your next backup, or the backup will refuse.
- Upgrading an older greyscale-only install: fetch again first, because both relief profiles now also need the HYP archive.
- Marine ecoregions are never installed because their terms restrict redistribution.

Check what is installed and which controls are unavailable:

```sh
docker compose exec app scripts/bootstrap-assets --check
docker compose exec app scripts/asset-availability
```

Ecoregions (CC BY 4.0) and hotspots (CC BY-SA 4.0) always print their credits on rendered maps, SVG and Office exports, even with the legend off. Keep those credits when you publish a map. `/data-credits` lists every source and license. GeoJSON and KML exports contain only the user's own geometry. Source details are in [third-party data](third-party.md).

## Sign in as the owner

The owner signs in at `/owner`, not `/login`, which offers only Google and GitHub. Because this repository is public, move it to a path of your own:

```dotenv
SIMPLEMAPPR_OWNER_LOGIN_PATH=/owner-4f9c2a1b
```

Use one lowercase segment of 3–63 letters, digits or hyphens, run `docker compose up -d`, and bookmark the result (under your URL prefix, for example `https://example.org/simplemappr/owner-4f9c2a1b`). An invalid value stops startup with a clear message.

The page isn't linked anywhere, asks search engines not to index it, and returns 404 when no owner is configured. It is also your recovery login if a social provider breaks.

Failed logins are limited to 10 per 15 minutes per client address. Behind a reverse proxy every client shares the proxy's address, so a burst of bad attempts locks the form for everyone. A hard-to-guess path keeps scanners from using up that limit.

## Change the owner password

```sh
scripts/rotate-local-owner --env-file "$PWD/.env"
docker compose up -d --no-build --wait --force-recreate database-init app
```

The script prompts without echoing, replaces only the password hash in `.env`, and leaves the database credentials alone. The owner keeps all their maps; old sessions stop working. Never rerun `configure-local-owner` on an existing deployment.

## Deploy an update

Run `scripts/deploy` on the server. It pulls the branch (fast-forward only), builds an image tagged with the commit, checks that it starts, swaps it in, runs HTTP checks and rolls back if they fail. The site stays up during the build.

```sh
scripts/deploy              # deploy
scripts/deploy --dry-run    # pull, build and verify only
scripts/deploy --force      # redeploy the running commit
```

From a workstation: `ssh HOST 'cd simplemappr && git pull -q --ff-only && scripts/deploy --no-pull'`.

- When `views/` changed, it stops the app briefly and moves aside the compiled template cache, leaving preview images untouched.
- The previous `.env` is kept in `~/.local/state/simplemappr-deploy`. Only the image line is rewritten.
- Old image tags and `.twig-before-deploy-*` directories are not pruned.
- PHP code is cached until restart, so always deploy a new image rather than editing files in a running container.

Take a [backup](backup-restore.md) before upgrading so you can roll back.

## Capacity

The defaults suit a 2 vCPU / 4 GB host:

- Apache: 12 workers, each recycled after 200 requests, KeepAlive off (`docker/apache-tuning.conf`; changing it needs a rebuild).
- Memory: app 2500 MB, MariaDB 768 MB with a 256 MB buffer pool. Override in `.env` with `SIMPLEMAPPR_APP_MEMORY`, `SIMPLEMAPPR_DB_MEMORY` and `SIMPLEMAPPR_DB_BUFFER_POOL`.

A measured test peaked at 8 workers of up to ~240 MB each. Enable compression at the proxy (Caddy: `encode zstd gzip`). A small swap file is a safety net, not a replacement for these limits.

## Caching

- Content-hashed assets under `/public/build/` are cached for a year; images under `/public/images/` for a day.
- Guests may reuse `/about` and `/data-credits` for five minutes. Signed-in pages are never cached.
- Map previews are cached for 24 hours in the disposable `render-cache` volume (256 MiB). Responses carry `X-Render-Cache: hit|miss`. Set `SIMPLEMAPPR_RENDER_CACHE=0` to turn it off. Downloads are never cached, and the cache is not backed up.

## Detailed relief

Set `SIMPLEMAPPR_DETAILED_RELIEF=1` in `.env` and recreate the app to add detailed terrain to close-up views of the greyscale and colour relief layers. It switches on when the preview's scale bar ends at 200 km or less. Wider views and areas without data keep the normal overview.

- The app downloads public NASA GIBS ASTER elevation tiles (up to ~38 m per pixel, land between 83°S and 83°N). Only tile numbers leave the server, never coordinates, labels or account details.
- Tiles are cached in the disposable `relief-cache` volume (1 GiB), which is not backed up. A restored deployment downloads them again, so it needs internet access.
- If tiles can't be fetched within 15 seconds, the render fails with a "temporarily unavailable" error rather than silently showing less detail.
- Maps using detailed relief credit NASA/METI ASTER and NASA GIBS.
- SVG output stays vector-only.

Known limit: geographic (non-Lambert) close-up views that cross the ±180° line show only the part between −180° and 180°, with or without detailed relief.

## Privacy and housekeeping

- Signed-in users' previews are private to their session and expire after an hour. Guest previews use random names and also expire. Expired files are cleaned up automatically.
- Pages load no remote analytics or badges. Rendering uses only local data, except for detailed relief tiles and imports from a URL the user supplies (see [import limits](import-export.md#imports)).
- Sessions use HttpOnly, SameSite=Lax cookies and end after two idle hours.
- The owner is an administrator who can edit citations. No role can open another user's private maps.
- `docker compose down` keeps all data. `docker compose down --volumes` deletes it.
