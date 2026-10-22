# Feature and verification map

The Docker runtime uses PHP 8.4, MapServer 8.4 and SWIG MapScript. The old PHP extension is replaced by that binding; application requests, owner persistence and the editor use the modern runtime.

| Workflow | Repeatable evidence |
| --- | --- |
| Native PNG/SVG/TIFF, projections, accents and bundled feature counts | `scripts/check-runtime`; restricted HTTP smoke check |
| Point groups, WKT, shaded regions, labels, crop/rotation/source viewport, explicit false options | Modern PHP request/document tests; Node document tests; Chromium/Firefox mixed/reload contract |
| Owner login, CSRF, private maps, settings import/export, revocable sharing | `Tests/smoke/check-persistence`; owner browser contract; protected operations rehearsal |
| CSV/TSV/TXT/WKT/GeoJSON/JSON/KML/XML/RSS, explicit encoding and row errors | Modern import suite; browser MIME-empty upload; isolated HTTPS import transport fixture |
| Remote import DNS/redirect/TLS/decoded-byte/deadline limits | `Tests/smoke/check-import-boundary`; modern RemoteImport tests |
| PNG/transparent PNG/JPEG/SVG/TIFF/KML/DOCX/PPTX downloads | Both browser engines; signatures, Office ZIP CRC/XML/embedded map dimensions, SVG/KML namespaces, PNG alpha |
| WMS 1.1.1/1.3.0 and WFS 1.0.0/1.1.0/2.0.0 | Modern HTTP OGC tests, including axis order, XML bounds, zero count and maximum features |
| Extended Natural Earth layers and Gray Earth relief | Checksummed profiles; `Tests/browser/profiles.mjs` individual/combined/reloaded controls; separate optional release lane |
| Citation create/edit/delete, unsafe URL recovery, text display and RSS | Citation modern suite; administrator browser contract; actual owner UI acceptance |
| Database recovery, original JSON/share/citation bytes, new sessions, owner rotation | `scripts/check-backup-restore`; operator review of the isolated restored instance |
| Keyboard, 375px layout, WCAG 2 A/AA and 2.1 A/AA | Pinned Chromium/Firefox keyboard and Axe contract; manual UI review |
| Failed preview/image/download recovery, input retention | Browser request-fault contract |
| Temporary preview expiration and eligible cleanup | Actual isolated image-creation cleanup fixture; immutable saved-map byte comparison |
| Dependency reproducibility, platform requirements and security audits | Pinned image/package snapshots, Composer/npm locks and release CI |

Settings JSON is a versioned editor document, distinct from exported GeoJSON geometry. Importing settings in the editor creates editable transient state; the My Maps importer writes private persistence. Administrator status never grants access to another owner's private maps.

General legacy Selenium/database/bootstrap tests remain archived under `Tests/`; a modern-suite pass does not claim that their original infrastructure runs. Specialist ecological data and unverified raster products remain explicitly excluded by the profile registry. No legacy public hosted records can be recovered without an actual database backup.

The private release record includes real fresh-install, empty-target restore, minimal/relief, recreation, earlier-image rollback, private-port, expiry, administrator and offline lanes. Browser screenshots/traces are acceptance evidence; sanitization removes credentials and live capability URLs. Internal Office media basenames are inert ZIP members, not reusable private preview URLs. Real output files stay valid for format verification.

Remote CI is authoritative only after an actual successful run linked from the release PR. Optional dataset downloads are a manually requested release lane. Keep its evidence separate from minimal-only PR checks.
