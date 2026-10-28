# Feature and verification map

Which check covers each feature. Commands are in [development.md](development.md#check-the-runtime-and-application) and [release checks](development.md#release-checks).

| Workflow | Repeatable evidence |
| --- | --- |
| Native PNG/SVG/TIFF, projections, accents and bundled feature counts | `scripts/check-runtime`; restricted HTTP smoke check |
| Point groups, WKT, shaded regions, labels, crop/rotation/source viewport, explicit false options | Modern PHP request/document tests; Node document tests; Chromium/Firefox mixed/reload contract |
| Owner login, CSRF, private maps, settings import/export, revocable sharing | `Tests/smoke/check-persistence`; owner browser contract; protected operations rehearsal |
| Google signed claims, GitHub authenticated public profile, provider sessions, private account ownership | Modern social-login tests; `Tests/smoke/check-social-login` isolated HTTPS provider fixture |
| CSV/TSV/TXT/WKT/GeoJSON/JSON/KML/XML/RSS, explicit encoding and row errors | Modern import suite; browser MIME-empty upload; isolated HTTPS import transport fixture |
| Remote import DNS/redirect/TLS/decoded-byte/deadline limits | `Tests/smoke/check-import-boundary`; modern RemoteImport tests |
| PNG/transparent PNG/JPEG/SVG/TIFF/KML/DOCX/PPTX downloads | Both browser engines; signatures, Office ZIP CRC/XML/embedded map dimensions, SVG/KML namespaces, PNG alpha |
| WMS 1.1.1/1.3.0 and WFS 1.0.0/1.1.0/2.0.0 | Modern HTTP OGC tests, including axis order, XML bounds, zero count and maximum features |
| Extended Natural Earth layers, colour relief, Blue Marble retirement | Checksummed profiles; `Tests/browser/profiles.mjs` individual/combined/reloaded controls, selected with `SIMPLEMAPPR_TEST_PROFILE`; separate optional release lane |
| Covered global backgrounds skipped without changing selections or visible pixels | `Tests/modern/CoveredBackgroundTest.php`; full-map previews/downloads, detailed terrain, WMS client/group order and capabilities; transparent overlays retained |
| Citation create/edit/delete, unsafe URL recovery, text display and RSS | Citation modern suite; administrator browser contract; actual owner UI acceptance |
| Database recovery, original JSON/share/citation bytes, new sessions, owner rotation | `scripts/check-backup-restore`; operator review of the isolated restored instance |
| Keyboard, 375px layout, WCAG 2 A/AA and 2.1 A/AA | Pinned Chromium/Firefox keyboard and Axe contract; manual UI review |
| Failed preview/image/download recovery, input retention | Browser request-fault contract |
| Temporary preview expiration and eligible cleanup | Actual isolated image-creation cleanup fixture; immutable saved-map byte comparison |
| Dependency reproducibility, platform requirements and security audits | Pinned image/package snapshots, Composer/npm locks and release CI |

## Known gaps

- **Live Ctrl+X** is untested because browser automation can't deliver it. Its handler passes the Node event test.
- The archived Selenium, database and router suites under `Tests/` don't run yet.
- Optional dataset profiles run only in the manually requested release lane, separately from PR checks.
- Real Google and GitHub sign-in needs operator credentials. Fixtures prove the app flow, not your provider registration; follow [social sign-in](social-login.md#5-test-it-for-real) before going public.
- Safari and real touch devices aren't tested.
- Records from the retired hosted service can't be recovered without an actual database backup.

## Evidence

CI results count only from a successful run linked in the release PR. Browser traces and screenshots are kept as acceptance evidence after credentials and live share URLs are scrubbed. The private release record covers fresh install, restore to an empty target, minimal and relief profiles, recreation, rollback to an earlier image, a private port, expiry, administration and offline use.
