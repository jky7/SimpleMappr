# Software and map-data inventory

SimpleMappr retains its upstream MIT license and copyright notices. The release inventory has three sources of truth:

- `composer.lock` and `composer licenses --format=json` describe exact PHP package revisions and declared licenses.
- The frontend manifest includes dependency licenses, retained-plugin notices and hashes. The application and browser `package-lock.json` files pin npm versions and integrity hashes.
- `/usr/local/share/simplemappr-packages.tsv` inside the image lists the exact Debian package versions. Installed `/usr/share/doc/PACKAGE/copyright` files retain their distribution copyright and license notices.

The GitHub release workflow saves Composer license output, npm security audits and the installed package inventory with its sanitized receipts. License declarations do not replace reviewing a dependency's actual license when updating or redistributing it. Node and Playwright are build/test dependencies; the deployed Apache/PHP image does not install a host Node runtime.

The PHP stack includes Firebase PHP-JWT for social sign-in token verification, GeoPHP, Twig, Phroute, GD/image support, PHPWord, PHPPresentation and PHPUnit for verification. PHPPresentation remains pinned to the reviewed upstream revision recorded in `composer.lock`; a compatible maintained stable release should replace that pin when available. The software inventory includes test dependencies because the current runtime image also supports the documented verification commands.

The editor uses pinned jQuery and jQueryUI. Jcrop, ColorPicker and Tiny Circleslider are retained vendored plugins with preserved notices and build-input hashes. They are outside npm audit's package coverage. Do not remove their notices when regenerating the frontend.

Natural Earth datasets are public domain. Each installed profile manifest records the original archive URL, version, archive/member hashes and the Natural Earth terms URL. The bundled minimum inventory is `Tests/fixtures/manifest.json`; optional inventories are under `config/asset-profiles/`. Gray Earth is a medium-resolution replacement rather than the archived custom high-resolution raster. Color shaded relief and Natural Earth II use version 2.0.0. NASA Blue Marble uses the original 2002 image, credited to NASA Visible Earth under its [image-use policy](https://science.nasa.gov/earth/faq/). Its source TIFF stays unchanged; a checked-in VRT provides global WGS84 georeferencing.

Optional archives are deliberately excluded from the Docker build context and Git. Fetching them requires an explicit host command. Installing from a retained verified cache performs no network request. Source provenance and version metadata come from the pinned archive contents, which may differ from a current download site's display label.

The available RESOLVE 2017 terrestrial ecoregions require CC BY 4.0 attribution. The 2016.1 biodiversity hotspots require CC BY-SA 4.0 attribution and share-alike treatment. The full-map profile installs both with mandatory rendered credits, a public data-credit page, and service license metadata. The hotspot source retains its supplied © 2012 Conservation International Foundation credit and disclaimer. Its XML contains older restrictive terms; the installed 2016.1 README and author deposit explicitly declare CC BY-SA 4.0. Both metadata files remain unchanged. Marine ecoregions have redistribution and noncommercial restrictions and are excluded.
