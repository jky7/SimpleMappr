# Editor build and behaviour

The Docker image builds the frontend in a pinned Node 26.12.0 stage, so the host doesn't need Node. The PHP image ships only the generated assets.

```sh
docker compose up --build -d --wait
docker compose exec app vendor/bin/phpunit -c Tests/phpunit.modern.xml
docker compose exec app Tests/smoke/check-http
```

To work on the frontend directly (Node 26.12.0):

```sh
npm ci
npm run build
npm test
npm run check:build
npm audit --omit=dev
```

The build writes `public/build/manifest.json` with content-hashed JS, CSS, images and license notices. PHP serves those paths in every environment and never writes assets. A missing manifest gives an explicit "rebuild" error.

## Dependencies

- jQuery 3.7.1 and jQuery UI 1.14.2 are exact npm dependencies. Only the UI modules the editor uses are bundled.
- Jcrop 0.9.12, ColorPicker and Tiny Circleslider are vendored. Their hashes and license headers are in the manifest. npm audit doesn't cover them, so treat them as maintenance debt.
- Inter is the only font. Its Latin subsets come from `@fontsource-variable/inter` via `public/stylesheets/raw/fonts.css`, so a new language needs its subset added there.

## Map document and browser storage

`map-document.js` mirrors `SavedMapDocument` (schema version 1):

- Point, WKT and region groups stay independent.
- `bbox_map`, `projection_map` and `origin_map` travel together. An origin of zero is valid.
- Layer names are case-sensitive, and explicit `false` flags are kept.
- An empty PHP dictionary may arrive as `[]` and is accepted. Any other array in place of a dictionary is rejected.

`editor-storage.js` keeps the current input, undo history and crop state per owner or guest:

- It never stores CSRF tokens, passwords, sessions or download fields.
- Only a signed-in owner can migrate old unscoped `do-*`/`undo-*` history. Guests never adopt it.
- When storage is full, the oldest undo entries are dropped first. If storage is unavailable, state is kept in memory.

## Importing

The editor's importer (`POST /import`) loads CSV, TSV, WKT, GeoJSON, KML, GeoRSS or a settings file into the editor without saving anything. Files are limited to 5 MiB (2 MB for settings) and the browser's MIME type is ignored. Current input is replaced only after a valid response. The My Maps importer is separate and saves the map privately. Server-side rules are in [import-export.md](import-export.md).

## Standalone pages

Every page except the editor extends `views/base.html`, which provides the small `pages.css` bundle and the simple header. Keep layout in that stylesheet and use the `.page`/`.card` structure; `TemplateTest` fails inline styles or a missing stylesheet.

About, API, Feedback and Help are tab fragments loaded by XHR. Opening one directly redirects to `/?locale=…#tabs=NAME`.

## Map interaction

**Requests**: a new preview cancels the previous one, and stale responses are ignored. Errors keep the input and release the loading state. Exports use same-origin fetch with CSRF and a 90-second deadline, and a failed export can be retried.

**Keyboard**: Tab follows normal order. Enter previews from a single-line field and adds a newline in a textarea. Map shortcuts work while the preview has focus and never intercept editing controls or open dialogs.

**Drag to pan** (mouse or pen):
- The image slides at once; on release one render is sent with `pan=dx,dy` in image pixels.
- Movement under 4 px counts as a click, so double-click zoom still works. Escape cancels.
- Touch and the zoom, crop and query tools never pan.
- Dragging again before the render arrives adds to the pending offset.
- A failed render snaps the image back.

**Wheel to zoom**:
- Each notch zooms 1.4× around the pointer, up to 1.4⁶ (~7.5×) per gesture.
- One render is sent 300 ms after the wheel stops, as `view_rect`: the part of the last rendered image that should fill the map.
- Pixel-mode deltas are rounded to whole 100-unit notches. Line-mode events count one notch each. Ctrl+wheel pinch keeps its raw units. See the comments near `deltaMode` in `simplemappr.js` for browser quirks.

Pan and zoom share one pending view, so mixing them never jumps. The server rejects `view_rect` combined with `pan` or a selection. The arrows, zoom buttons and double-click clear it.

**Narrow screens**: the toolbar wraps, settings move below the map, and the map scrolls inside its frame at full size so crop coordinates stay correct.

## Tests

`npm test` (DOM tests) covers document migration, owner/guest storage isolation, raw rows, saved-map loading, cancellation, stale responses, failed images and uploads, keyboard defaults and download limits. Native tests check that disabled layers render the same as omitted ones. Real browser interaction and accessibility are covered by `scripts/check-browser` ([development.md](development.md#release-checks)).
