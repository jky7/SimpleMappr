# Editor control audit — 2026-10-22

This pass exercised the editor in Chromium against an isolated Docker deployment, including a `/simplemappr/` proxy prefix and a disposable owner account. It covers the minimal dataset profile. These results describe the PR build; the hosted site still needs that build deployed.

## Results

| Controls | Interaction checked | Result |
| --- | --- | --- |
| Zoom | Draw a box, click a point, double-click, zoom out, keyboard selection mode | Fixed native rectangle initialization; zoom changes the visible area |
| Fill | Choose color, Apply, single country click, ocean click, box selection | Fixed picker click/Apply handling and zero-size click queries; ocean selects nothing |
| Rotated fill | Click Brazil in a 90° preview; native point and box queries | Fixed selection to use the rendered viewport and native pixel conversion |
| Pan | All four toolbar arrows; keyboard arrows | Visible directional changes |
| Rotation | Mouse drag and keyboard arrows | Map changes; announced slider value follows mouse rotation |
| Crop | Draw, resize handles, width/height, geographic corner edits | Selection updates; geographic coordinates agree within one image pixel |
| Refresh / Rebuild / New | Crop reset, viewport/projection reset, data retention or clearing | Expected resets observed |
| Undo / Redo | Multiple viewports, new branches, expanded preview width | Fixed history to record completed renders and their submitted document |
| Failed history operations | Failed request, failed image, superseded response, edits during a pending render, empty fill | Node regressions pass; Redo survives unsuccessful new branches |
| Preview width | Collapse/expand settings; Undo and Redo across widths | Restores both presentation and history destination |
| Minimal layers / labels | Countries, provinces, lake outline/fill, country/province names | Each control requests and loads a new preview without errors |
| Grid / line thickness / Legend | Fixed/1°/5°/10° spacing, hide labels, keyboard thickness, Legend tab | New previews and legend load |
| Projection / origin | All 11 projection choices; zoomed North America Lambert and change central meridian | Each projection loads a preview; origin edit changes the map |
| Point styles | Shape, size, color, shadow; extra point layer | Both markers and styles render |
| Dynamic groups | Add, render, clear, remove and Clear All for points, drawings and regions | Fixed duplicate accordion IDs; second-layer shape/size now reset |
| Country entry | Autocomplete with ArrowDown then Tab | Fixed obsolete widget API; inserts `Canada, ` |
| General import | Windows-1252 CSV with Montréal; coordinate row diagnostics | Accents survive; valid rows render and invalid raw rows remain editable |
| Required legends | Missing title, dismiss warning, correct title, retry | Preview recovers |
| Downloads | PNG, transparent PNG, JPEG, TIFF, SVG, DOCX, PPTX, KML; 1×/3×/4×/5× transitions | Actual files downloaded; signatures, raster sizes and XML/Office ZIP integrity checked |
| Crop export options | 300×200 crop at 3× with border, proportional lines, legend and scalebar | PNG is 900×600; Cancel leaves the editor usable |
| My Maps | Save validation, load, settings export/import, malformed import recovery, filtering/sorting | Disposable owner operations pass under the path prefix |
| Sharing / Embed | Create, list, open anonymous formats, Embed, revoke | Explicit link works before revocation; revoked page is unavailable |
| Delete | Cancel, then confirm deletion of a disposable map | Cancel retains it; confirm removes only that map |
| Administration | User list sorting/refresh; citation validation/create/edit/delete; cache action | Operations pass; cache action explains versioned assets |
| Help / information | Country table/filter, codes dialog/filter, About, Feedback, API | Fixed removed waypoint dependency; tables and tabs load |
| Keyboard / narrow layout | Tab to textarea, Enter newline, tab arrows/Home; map shortcuts; actual 375×812 viewport and export dialog | Input and navigation checks pass; no page-wide overflow in checked editor state |
| Sign out / draft ownership | Owner draft, sign out, guest view, sign in again | Guest excludes owner draft; owner draft returns |

## Verification and limits

`npm test` passes 53 tests. The image build also runs the deterministic asset check. The modern PHP suite passes 126 tests and 1,335 assertions, with 12 existing dependency deprecations (11 GeoPHP implicit-nullable notices and one ForceUTF8 `utf8_decode()` notice). Native MapScript and restricted HTTP smoke checks pass.

Ctrl+D and Ctrl+S open their dialogs. Arrows, Ctrl+Z/Y/R/B/E/N/L, zoom selection and zoom out were exercised. **Live Ctrl+X remains unverified:** the browser automation does not deliver it even to a minimal keyboard-event page. The application handler passes its Node event test and the crop toolbar works.

This is not an exhaustive combination test. Optional dataset profiles, Safari, real touch devices, external feedback delivery and social-provider login were not exercised in this pass. Native selection tests cover province clicks; the direct browser click receipt is for a country. Failed-response scenarios use the Node harness rather than an injected failure in the live browser.

The local receipts and screenshots are under `/home/jky/.codex/docs/simplemappr-controls/`; credentials and disposable Compose configuration stay in its protected `private/` folder. The audit did not change the operator deployment or publish user maps.
