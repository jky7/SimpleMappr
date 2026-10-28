# Import, export, and service contracts

## Imports

`POST /import` accepts one multipart `file` and returns editor groups as JSON. The response contains `coords`, `wkt`, `regions`, and `bad_drawings`. A settings document adds `document`. Invalid geometry rows appear in `bad_drawings`; valid rows remain usable. A rejected feature never adds part of its geometry to the accepted groups.

`GET /api` and `POST /api` accept the original point and style parameters, uploaded `file`, remote `url`, or pasted `data`. Import options are:

| Parameter | Values |
| --- | --- |
| `input_format` | `auto`, `csv`, `wkt`, `geojson`, `kml`, `georss`, `settings` |
| `encoding` | `UTF-8`, `UTF-16LE`, `UTF-16BE`, `ISO-8859-1`, `WINDOWS-1252` |

Automatic detection accepts JSON, XML, WKT, and CSV. A UTF-16 byte order mark takes precedence over the declared encoding. Invalid byte sequences, NUL bytes, XML document types, and entities are rejected. CR, LF, and CRLF line endings are accepted. CSV supports quoted fields, doubled quotes, tab-separated named columns, and comma-separated `title,latitude,longitude` rows. Geometry imports accept at most ten groups in total. Settings and editor documents allow ten groups per data type.

Remote imports accept HTTP on port 80 and HTTPS on port 443. URLs cannot contain credentials or fragments. Every DNS answer and redirect destination must be globally routable. Redirects cannot downgrade HTTPS. The client verifies TLS certificates and pins its connection to a validated address. The maximum is three redirects, 5 MiB of decoded body bytes, 64 KiB of response headers, and 15 seconds including DNS. Loopback URLs and local paths are rejected. Local example files require upload.

## Render responses and downloads

`GET /api` returns the selected image bytes. `ping=true` returns `{"status":"ok"}`. `POST /api` returns HTTP 303 with a `Location` header and JSON containing `imageURL`, `expiry`, `bad_points`, and `bad_drawings`. Clients that follow redirects receive the image. Authenticated previews remain owner checked; guest previews use random names and expire.

The API accepts `output=png`, `pnga`, `jpg`, `tif`, or `svg`. `pnga` returns an RGBA PNG. Dimensions are between 2 and 5000 pixels, with a 20-megapixel maximum. `bbox` uses longitude,latitude coordinates regardless of output projection. API point input preserves `points[]`, `legend[]`, marker styles, `shade`, midpoint `zoom`, and graticules.

The editor export endpoints accept the map form:

| Endpoint | Response |
| --- | --- |
| `POST /application` with `download=1` | PNG, transparent PNG, JPEG, TIFF, or SVG bytes |
| `POST /kml` | KML XML with points, drawings, titles, and styles |
| `POST /geojson` | GeoJSON features and `rejected_rows` |
| `POST /docx` | DOCX with an embedded map image |
| `POST /pptx` | PPTX with an embedded map image |

SimpleMappr settings JSON preserves editor settings and group styles. GeoJSON exports geometry and selected feature properties. Importing GeoJSON does not restore a settings document.

Invalid ordinary requests return HTTP 400 JSON with an `error` field. Image and Office responses contain only their declared format. Download filenames are sanitized before they go in headers.

### Dataset credits

Maps showing ecoregions or hotspots always include wrapped credit lines. These need at least 160 px of width and 13 px per line plus 24 px of padding, plus another 36 px for an embedded scale bar (or 12 px for a watermark alone). The requirements scale with `download_factor`. A request too small for the credits fails with an error rather than clipping them. Both layers fit a 256×256 WMS image.

## WMS

`/wms` supports WMS 1.1.1 and 1.3.0. Capabilities advertise the available layers, local service URLs, and tested CRS values `EPSG:4326`, `EPSG:3857`, `EPSG:3395`, and `EPSG:3112`.

`GetMap` accepts KVP parameters and the supported XML form with named layers, default styles, a bounding box, output size, format, and transparency. Remote styles are disabled. The same dimension and pixel limits apply to XML and KVP requests.

WMS 1.1.1 `SRS=EPSG:4326` uses longitude,latitude BBOX order. WMS 1.3.0 `CRS=EPSG:4326` uses latitude,longitude. WMS 1.3.0 `CRS=CRS:84` uses longitude,latitude. Invalid requests return an XML service exception with HTTP 400.

## WFS

`/wfs` supports `GetCapabilities`, `DescribeFeatureType`, and `GetFeature` for WFS 1.0.0, 1.1.0, and 2.0.0. Raster layers are excluded. Feature IDs (`mappr_id`) come from an OGR view of the source FID, so they're stable without changing the checksummed files.

Hotspots are served through `config/hotspots-2016.vrt`. It selects the 36 `hotspot area` records from the 53 in the source (dropping 17 `outer limit` records) before any client filter, and adds `mappr_license` and `mappr_source` fields. Capabilities and HTTP `Link` headers point to the data credits.

`MAXFEATURES` limits older WFS versions. `COUNT` limits WFS 2.0. The maximum response is 1000 features. A zero count returns no features. The same bounds apply to XML POST requests. Errors return XML with HTTP 400.

These endpoints expose only the basemap datasets, never users' saved maps.
