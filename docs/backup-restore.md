# Back up and restore

A backup holds the database, the deployment `.env` and a checksummed manifest. It does **not** hold the Docker image or optional map-data archives, so keep those separately (see [Move to another host](#move-to-another-host)).

## Back up

```sh
mkdir -m700 "$HOME/simplemappr-recovery"
scripts/backup "$HOME/simplemappr-recovery/before-update" \
  --project simplemappr --env-file "$PWD/.env"
```

- The app must be running, and `--env-file` must match it.
- The destination must not exist yet. Keep it outside the checkout.
- The bundle is mode 0700 with 0600 files. It contains database and sign-in provider credentials, so treat it as a secret.
- Backup tags the running image as `simplemappr-backup:sha256-…`. Keep that tag: restore needs that exact image, and rebuilding `simplemappr-runtime:local` can lose the old one. `docker image prune -a` can still remove it.
- The manifest records the checkout commit and whether it was dirty. This is for information only and doesn't prove which source built the image.

If you mount `config/conf.php` or `config/shapefiles.yml` into the app, add them (these are the only two supported):

```sh
scripts/backup "$HOME/simplemappr-recovery/with-overrides" \
  --project simplemappr --env-file "$PWD/.env" \
  --include-override config/conf.php \
  --include-override config/shapefiles.yml
```

Each must be a read-only file mount in the running app. `conf.php` is executable PHP, so review it before restoring.

## Restore to a new project

Restore always creates a new Compose project and never overwrites one. It refuses if the target project already has containers, volumes or a non-empty database. Keep the original running until you've checked the copy.

```sh
scripts/restore "$HOME/simplemappr-recovery/before-update" \
  --project simplemappr-restored --port 18082
```

It prints the new local address. Sign in with the original owner credentials and check saved maps, shares and an export.

What restore does:

1. Checks every file in the bundle against its checksum before touching Docker. Checksums prove the bundle wasn't damaged, not who made it, so only restore bundles you trust.
2. Imports the SQL, verifies the schema, installs map data, and starts the recorded image.
3. Keeps maps (byte-for-byte), owners, citations and share links, active and revoked.
4. Starts with no sessions, previews, uploads or logs. Everyone signs in again.
5. Rewrites an absolute `SIMPLEMAPPR_URL` to the new localhost address, which can change the watermark on rendered maps. If the address changes, sign-in provider credentials are cleared; see [re-enable sign-in](social-login.md#restore-and-re-enable-sign-in).

Optional profiles need the original archive cache. Minimal restores don't.

The restored configuration lives in `PROJECT-deployment/` next to the bundle (`deployment.env`, `restore-compose.json`, and any overrides). Manage the restored project with both Compose files:

```sh
docker compose -p simplemappr-restored \
  --env-file /absolute/PROJECT-deployment/deployment.env \
  -f "$PWD/compose.yaml" -f /absolute/PROJECT-deployment/restore-compose.json \
  down
```

`down --volumes` deletes that project's data. Make sure you're pointing at the copy, not the original.

## Roll back an upgrade

To roll back, restore a backup taken while the older image was running. Before upgrading, back up and keep the old image tag. Check sign-in, a saved map and an export on the restored copy before switching the live site.

## Move to another host

Copy three things alongside the bundle:

1. The app image: `docker save simplemappr-backup:sha256-…`
2. The MariaDB image pinned in `compose.yaml`
3. `.asset-cache`, if you use an optional profile

Load the images on the new host with `docker load` and check that `docker image inspect` finds the image ID in the manifest. Restore refuses a missing or different image.

## Rehearse

`scripts/check-backup-restore --private-dir /new/private/dir` runs backup, restore, rotation and the refusal cases against disposable projects. Never point it at your real deployment.
