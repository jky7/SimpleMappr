<?php
namespace SimpleMappr;

use League\Csv\Reader;
use geoPHP\geoPHP;

final class ImportData
{
    public static function parse(string $bytes, string $format = 'auto', string $encoding = 'UTF-8'): array
    {
        if (strlen($bytes) > RemoteImport::MAX_BYTES || $bytes === '') { throw new \InvalidArgumentException('Import a nonempty file no larger than 5 MiB.'); }
        $encoding = strtoupper($encoding);
        if (!in_array($encoding, ['UTF-8','UTF-16LE','UTF-16BE','ISO-8859-1','WINDOWS-1252'], true)) { throw new \InvalidArgumentException('The import encoding is not supported.'); }
        if (str_starts_with($bytes, "\xff\xfe")) { $encoding = 'UTF-16LE'; $bytes = substr($bytes, 2); }
        if (str_starts_with($bytes, "\xfe\xff")) { $encoding = 'UTF-16BE'; $bytes = substr($bytes, 2); }
        if (!mb_check_encoding($bytes, $encoding)) { throw new \InvalidArgumentException('The import contains invalid text encoding.'); }
        $text = mb_convert_encoding($bytes, 'UTF-8', $encoding);
        if (str_starts_with($text, "\xef\xbb\xbf")) { $text = substr($text, 3); }
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (str_contains($text, "\0")) { throw new \InvalidArgumentException('Binary files cannot be imported as map data.'); }
        if ($format === 'auto') {
            if (str_starts_with($text, '{') || str_starts_with($text, '[')) { $format = 'json'; }
            elseif (str_starts_with($text, '<')) { $format = 'xml'; }
            elseif (preg_match('/^(?:POINT|LINESTRING|POLYGON|MULTIPOINT|MULTILINESTRING|MULTIPOLYGON|GEOMETRYCOLLECTION)\s*(?:\(|EMPTY)/i', $text)) { $format = 'wkt'; }
            else { $format = 'csv'; }
        }
        $result = ['coords'=>[], 'wkt'=>[], 'regions'=>[], 'bad_drawings'=>[]];
        if ($format === 'json' || $format === 'geojson' || $format === 'settings') {
            $json = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
            if ($format === 'settings' || !isset($json['type'])) { return ['document'=>SavedMapDocument::decode($text)] + $result; }
            $features = ($json['type'] === 'FeatureCollection') ? ($json['features'] ?? []) : [$json];
            foreach ($features as $index => $feature) {
                if (!is_array($feature)) { $result['bad_drawings'][] = 'Invalid GeoJSON feature '.($index + 1); continue; }
                $title = (string)($feature['properties']['title'] ?? $feature['properties']['name'] ?? 'Imported data');
                try { $candidate = $result; self::geometry($candidate, geoPHP::load(json_encode($feature, JSON_THROW_ON_ERROR), 'json'), $title); $result = $candidate; }
                catch (\Throwable $e) { $result['bad_drawings'][] = 'Invalid GeoJSON feature '.($index + 1); }
            }
        } elseif (in_array($format, ['xml','kml','georss'], true)) {
            $text = preg_replace('/(<\?xml[^>]*encoding\s*=\s*[\"\'])[^\"\']+([\"\'])/i', '$1UTF-8$2', $text);
            $xml = self::xml($text);
            $root = strtolower($xml->documentElement->localName);
            $adapter = $format === 'xml' ? ($root === 'kml' ? 'kml' : 'georss') : $format;
            $xpath = new \DOMXPath($xml);
            $records = $xpath->query($adapter === 'kml' ? '//*[local-name()="Placemark"]' : '//*[local-name()="item" or local-name()="entry"]');
            if ($records->length === 0) {
                try { self::geometry($result, geoPHP::load($xml->saveXML(), $adapter), 'Imported data'); }
                catch (\Throwable $e) { throw new \InvalidArgumentException('The imported XML has no supported geometry.'); }
            } else {
                foreach ($records as $index=>$record) {
                    $title = trim((string)$xpath->evaluate('string(./*[local-name()="'.($adapter === 'kml' ? 'name' : 'title').'"])', $record));
                    if ($title === '') { $title = 'Imported data'; }
                    try {
                        $candidate = $result;
                        self::geometry($candidate, geoPHP::load($record->C14N(), $adapter), $title);
                        $result = $candidate;
                    } catch (\Throwable $e) { $result['bad_drawings'][] = 'Invalid XML geometry '.($index + 1); }
                }
            }
        } elseif ($format === 'wkt') {
            foreach (preg_split('/\R/u', $text) as $row) {
                if (trim($row) === '') { continue; }
                try { self::geometry($result, geoPHP::load($row, 'wkt'), 'Imported data'); }
                catch (\Throwable $e) { $result['bad_drawings'][] = $row; }
            }
        } elseif ($format === 'csv') {
            $result['coords'] = self::csv($text);
        } else { throw new \InvalidArgumentException('The import format is not supported.'); }
        if (!$result['coords'] && !$result['wkt']) { throw new \InvalidArgumentException('No supported coordinates or drawings were found in the import.'); }
        return $result;
    }

    public static function xml(string $text): \DOMDocument
    {
        if (str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) { throw new \InvalidArgumentException('Service XML must use UTF-8 encoding.'); }
        if (strlen($text) > RemoteImport::MAX_BYTES || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $text)) { throw new \InvalidArgumentException('External entities and document types are not allowed.'); }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = new \DOMDocument();
            if (!$xml->loadXML($text, LIBXML_NONET)) { throw new \InvalidArgumentException('The XML document is malformed.'); }
            if ($xml->doctype !== null) { throw new \InvalidArgumentException('Document types are not allowed.'); }
            return $xml;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private static function geometry(array &$result, $geometry, string $title): void
    {
        if (!$geometry || $geometry->isEmpty()) { throw new \InvalidArgumentException('Empty geometry.'); }
        $type = $geometry->geometryType();
        if ($type === 'Point') {
            $key = array_search($title, array_column($result['coords'], 'title'), true);
            if ($key === false) { if (count($result['coords']) + count($result['wkt']) >= MAXNUMTEXTAREA) { throw new \InvalidArgumentException('The import has too many data groups.'); } $key = count($result['coords']); $result['coords'][$key] = ['title'=>$title, 'data'=>'']; }
            $result['coords'][$key]['data'] .= ($result['coords'][$key]['data'] === '' ? '' : "\n").$geometry->y().','.$geometry->x();
        } elseif (in_array($type, ['MultiPoint','GeometryCollection'], true)) {
            foreach ($geometry->getComponents() as $part) { self::geometry($result, $part, $title); }
        } else {
            $key = array_search($title, array_column($result['wkt'], 'title'), true);
            if ($key === false) { if (count($result['coords']) + count($result['wkt']) >= MAXNUMTEXTAREA) { throw new \InvalidArgumentException('The import has too many data groups.'); } $key = count($result['wkt']); $result['wkt'][$key] = ['title'=>$title,'data'=>'']; }
            $result['wkt'][$key]['data'] .= ($result['wkt'][$key]['data'] === '' ? '' : "\n").$geometry->out('wkt');
        }
        if (count($result['coords']) + count($result['wkt']) > MAXNUMTEXTAREA) { throw new \InvalidArgumentException('The import has too many data groups.'); }
    }

    private static function csv(string $text): array
    {
        $quoted = false;
        $start = true;
        for ($i=0, $length=strlen($text); $i<$length; $i++) {
            $char = $text[$i];
            if ($quoted) {
                if ($char === '"') {
                    if ($i + 1 < $length && $text[$i+1] === '"') { $i++; }
                    else { $quoted = false; }
                }
            } elseif ($char === '"' && $start) { $quoted = true; $start = false; }
            else { $start = $char === ',' || $char === "\t" || $char === "\n"; }
        }
        if ($quoted) { throw new \InvalidArgumentException('A CSV quoted field is incomplete.'); }
        $csv = Reader::fromString($text);
        $csv->setEscape('');
        $tab = str_contains(strtok($text, "\r\n"), "\t");
        if ($tab) { $csv->setDelimiter("\t"); }
        $headers = $tab ? $csv->nth(0) : [];
        if ($headers && count(array_unique($headers)) !== count($headers)) { throw new \InvalidArgumentException('CSV column titles must be unique.'); }
        $groups = [];
        foreach ($csv->getRecords() as $line => $row) {
            if ($tab && $line === 0) { continue; }
            if ($tab) { $values = array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), '')); }
            elseif (count($row) === 2) { $values = ['Imported data'=>implode(',', $row)]; }
            elseif (count($row) >= 3) { $title = array_shift($row); $values = [$title=>implode(',', $row)]; }
            elseif (count($row) === 1 && trim($row[0]) !== '') { $values = ['Imported data'=>$row[0]]; }
            else { continue; }
            foreach ($values as $title => $value) {
                if (trim($value) === '') { continue; }
                $groups[(string)$title][] = $value;
            }
            if (count($groups) > MAXNUMTEXTAREA || $line > 100000) { throw new \InvalidArgumentException('The CSV exceeds the group or row limit.'); }
        }
        return array_map(static fn($title, $rows) => ['title'=>(string)$title,'data'=>implode("\n", $rows)], array_keys($groups), array_values($groups));
    }
}
