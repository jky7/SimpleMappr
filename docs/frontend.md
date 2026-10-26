# Build and verify the editor

The Docker image builds the frontend in a digest-pinned Node 26.12.0 stage. The PHP runtime contains only the generated assets. The host does not need Node.

```sh
docker compose up --build -d --wait
docker compose exec app vendor/bin/phpunit -c Tests/phpunit.modern.xml
docker compose exec app Tests/smoke/check-http
```

For local frontend development with Node 26.12.0 available, use the locked inputs.

```sh
npm ci
npm run build
npm test
npm run check:build
npm audit --omit=dev
```

The build writes `public/build/manifest.json` and content-hashed JavaScript, CSS, images and license notices. Generated assets and `node_modules` are ignored. Development and production serve the same manifest paths. A missing manifest produces an explicit rebuild error. PHP never writes or concatenates browser assets at request time. Changing source bytes changes the asset request path.

jQuery 3.7.1 and jQuery UI 1.14.2 are exact npm dependencies with integrity hashes in the lock. The build includes only the UI modules needed by existing widgets. It preserves the local Jcrop 0.9.12, ColorPicker and Tiny Circleslider sources, records their SHA256 values in the manifest and includes their license headers in the generated notices. These three plugins remain maintenance debt. The npm audit result does not cover them. Obsolete loaders, analytics, social scripts, cookie polling, hash/history plugins, jStorage, keyboard interception and the unused rich-text editor are removed.

## Editor documents and browser state

`map-document.js` encodes the same version-one scalar, group and option shape as `SavedMapDocument`. Point, WKT and region arrays remain independent. The viewport source stays together as `bbox_map`, `projection_map` and `origin_map`. Zero is a valid origin. Case-sensitive layer names and explicit false group flags survive normalization. Empty PHP dictionaries encoded as `[]` are accepted; nonempty arrays in place of option dictionaries are rejected.

`editor-storage.js` keeps current input, bounded undo history and crop state under a server-provided owner or guest scope. It never stores CSRF, passwords, sessions or download transport fields. Guest state never adopts unscoped legacy history. An authenticated owner can migrate recognized `do-*` and `undo-*` documents from legacy jStorage. Other origin storage is left alone. Quota pressure evicts oldest undo history until the current document can be persisted. Unavailable storage or a current document that cannot fit uses an in-memory fallback. Clearing browser storage removes locally retained drafts.

The general importer loads CSV, TSV, WKT, GeoJSON, KML, GeoRSS or settings into the editor through `POST /import`. It checks the extension and five-MiB ceiling without trusting browser MIME. The server validates text encoding and content. Settings remain limited to two MB. Existing input is replaced only after a valid response and document normalization. Loading settings this way does not save a database record. The separate My Maps settings importer explicitly imports into private persistence.

## Standalone pages

Sign in, data credits, shared maps and the error pages share `pages.css` (its own content-hashed bundle with the Inter font-face) and the simple dark header, so they match the editor without loading jQuery UI. `base.html` supplies the stylesheet and header by default and `TemplateTest` fails a page that overrides the stylesheet block without one or styles itself inline. The About, API, Feedback and Help fragments are tab panels loaded over XHR; opened directly they redirect to the editor on that tab (`#tabs=help`), and the hash restore accepts tab names as well as positions.

## Failure recovery and keyboard use

A preview cancels its predecessor and ignores stale responses. HTTP, JSON and image errors release loading state and keep input. Exports use same-origin fetch with session CSRF and a 90-second deadline. A failed export restores the download controls and leaves the map available to retry.

Tab follows normal focus order. Enter in a single-line map input previews; Enter in a textarea creates a newline. Map shortcuts apply while the preview region has focus. Dragging the preview with a mouse or pen slides the existing image and renders once on release by sending `pan=dx,dy` in image pixels (the server recentres the view the opposite way, like the arrows). Movement under four pixels stays a click so double-click zoom works, Escape cancels, touch pointers and active zoom/crop/query tools never pan, and a failed render snaps the image back and clears the pending pan. Releasing leaves the image slid and clipped to its frame until the render arrives, so it cannot spill over the toolbar. A second drag in that time continues from where the image sits and sends the total offset from the last rendered view (the pending request is replaced), and a render that lands mid-drag re-bases the drag onto the new view instead of resetting it. Scrolling over the map zooms 1.4x per notch around the pointer: the image scales at once in the same whole steps as the final render, and one render is sent 300 ms after the wheel stops, as `view_rect` (the rectangle of the last rendered view, in image pixels, that should fill the map; it may extend past the canvas when zooming out). Chromium mouse deltas in multiples of 120 pixels are normalized to 100 units per notch; Ctrl+wheel pinch deltas keep their original units. Notches and trackpad pinch steps add up (up to 1.4⁶, about 7.53x); less than one notch in total puts the image back. Pan and wheel share one pending view (a shift and a scale), so mixing them while a render is pending never jumps; a pure shift still sends `pan`. The server rejects `view_rect` together with `pan` or a selection, and the arrows, zoom buttons and double-click clear it. They do not intercept editing controls or active dialogs. The retained jQuery UI dialogs manage focus containment and return. At narrow widths the toolbar wraps, settings move below the canvas and the unscaled map stays inside a scrolling preview, preserving crop coordinates.

The local DOM suite verifies document migration, owner/guest storage isolation, raw-row preservation, saved-map load, mixed flags, cancellation, stale responses, failed images, upload cancellation and errors, keyboard defaults and bounded downloads. Native/HTTP tests verify disabled map layers produce the same pixels as omitted layers and enabled layers change the rendered map. Real browser interaction and accessibility scans remain separate checks.
