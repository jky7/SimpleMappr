# SimpleMappr development

## Runtime

Use the Docker runtime for PHP and MapScript. The host does not need PHP or a compiled MapScript extension.

```sh
docker compose up --build -d --wait
docker compose exec app scripts/check-runtime
docker compose exec app Tests/smoke/check-http
docker compose exec app vendor/bin/phpunit -c Tests/phpunit.modern.xml
docker compose exec app scripts/bootstrap-assets --check
curl --fail http://127.0.0.1:8080/_runtime
```

`SIMPLEMAPPR_PORT` changes the localhost port. P2 serves the guest editor and modern MapScript renderer. Saved maps, authentication and full API/service/import/Office workflows still require later phases.

The runtime uses Debian Apache prefork, mod_php 8.4, PHP CLI 8.4, and `php-mapscript-ng` from one dated Debian snapshot. Do not install MapScript compiled for another PHP ABI. Native map text loads with `msLoadMapFromString($text, $basePath, null)`. `new mapObj($filename)` loads a file.

`Tests/fixtures/manifest.json` is the sole `minimal-vector-v1` asset inventory. It contains all archive member hashes. Keep physical and nested cultural paths compatible with `config/shapefiles.yml.sample`. `assets-init` is the only writer of `map-data`. The app mounts that volume read-only. Missing optional datasets must remain explicit.

`scripts/check-runtime` verifies native PNG, SVG, and TIFF outputs, marker placement, five dataset feature counts, accented labels, and a Lambert projection round trip. It writes `/var/lib/simplemappr/checks/report.json` and map files to the `runtime-output` volume. The native checks do not load Composer or application classes.

Apache exposes the restricted front controller document root, `/_runtime`, and allowlisted files under `/_checks/`. Retain this restricted document root. Never publish the checkout root.

## Runtime updates

The Dockerfile pins the Debian base digest and package snapshot. Keep `/usr/local/share/simplemappr-packages.tsv` available in the image. Update the digest and snapshot together, rebuild, and run both HTTP and CLI checks.

## Application port

The canonical request is the original map document. Keep `bbox_map`, `projection_map` and `origin_map` together as source viewport state. Rendered responses include `rendered_origin`; the browser echoes it into `origin_map`. Native projection changes require explicit extent transformation. Never mutate the shared projection registry per request.

Use `config/defaults.php` for checked-in guest defaults and optional ignored `config/conf.php` overrides. The minimal dataset YAML is shared by the editor and renderer. The native health endpoint remains independent of Composer. Keep errors logged and binary responses clean.

The modern suite uses PHPUnit 13. The original coordinate suite is included with numeric tolerances. Other archived tests remain available but their database and Selenium bootstrap is pending migration. Do not delete their coverage or claim that a modern-suite pass covers persistence.
