# Software and map-data inventory

SimpleMappr keeps its upstream MIT license and copyright notices. Three sources list exactly what ships:

- `composer.lock` and `composer licenses --format=json` describe exact PHP package revisions and declared licenses.
- The frontend manifest includes dependency licenses, retained-plugin notices and hashes. The application and browser `package-lock.json` files pin npm versions and integrity hashes.
- `/usr/local/share/simplemappr-packages.tsv` inside the image lists the exact Debian package versions. Installed `/usr/share/doc/PACKAGE/copyright` files retain their distribution copyright and license notices.

The release workflow saves the Composer license output, npm audits and package inventory with its receipts. Declared licenses are a starting point; check the actual license when you update or redistribute a dependency. Node and Playwright are build and test tools only and aren't in the deployed image.

The PHP stack includes Firebase PHP-JWT (sign-in tokens), GeoPHP, Twig, Phroute, GD, PHPWord, PHPPresentation and PHPUnit. PHPPresentation is pinned to a reviewed upstream commit in `composer.lock` until a compatible stable release ships. Test dependencies are listed because the runtime image also runs the verification commands.

Inter (SIL OFL 1.1) is the only font. The editor bundles the Latin and Latin Extended subsets of `@fontsource-variable/inter`, with its license in the notices file. Rendered maps use Debian's `fonts-inter` Regular and Bold through `mapserver/fonts/fonts.list` (license in `/usr/share/doc/fonts-inter/copyright`).

The editor uses pinned jQuery and jQuery UI. Jcrop, ColorPicker and Tiny Circleslider are vendored with their notices and hashes. npm audit doesn't cover them, and their notices must survive frontend rebuilds.

Natural Earth datasets are public domain. Each profile manifest records the archive URL, version, archive and member hashes, and the Natural Earth terms URL. The minimal inventory is `Tests/fixtures/manifest.json`; optional ones are under `config/asset-profiles/`. Versions come from the archive contents, which can differ from the download site's label (the railroad archive, for example, contains 4.1.0 metadata).

Raster sources, all left byte-for-byte unchanged:

| Layer | Source |
| --- | --- |
| `relief (greyscale)` | Natural Earth HYP 2.0.0, 21600×10800. `config/hyp-greyscale.vrt` reads band 1 as Byte/Gray, the same pixels `gdal_translate -ot Byte -b 1` produces. |
| `relief (greyscale alt)` | Natural Earth Gray Earth 2.0.0, 21600×10800 |
| `relief`, Natural Earth II | Natural Earth 2.0.0, 16200×8100 |
| Blue Marble (retired) | NASA 2002 image, credited to NASA Visible Earth under its [image-use policy](https://science.nasa.gov/earth/faq/). It is still downloaded by `full-map-v1` for provenance, with `config/blue-marble.vrt` supplying WGS84 georeferencing, but it is no longer offered as a layer. |
| Detailed relief (optional) | NASA/METI ASTER GDEM via NASA GIBS, fetched at render time |

Optional archives stay out of Git and the Docker build context. They are fetched only by an explicit host command, and installing from a verified cache makes no network request.

RESOLVE 2017 terrestrial ecoregions are CC BY 4.0. Biodiversity hotspots 2016.1 are CC BY-SA 4.0 and keep their © 2012 Conservation International Foundation credit and disclaimer. The hotspot XML metadata carries older, more restrictive terms, but the 2016.1 README and author deposit declare CC BY-SA 4.0; both files are kept unchanged. `full-map-v1` installs both datasets with credits on every map, the `/data-credits` page, and license metadata in the map services. Ecoregion colours are generated from the source `ECO_ID` and `COLOR` fields. Marine ecoregions are excluded because their terms restrict redistribution and commercial use.
