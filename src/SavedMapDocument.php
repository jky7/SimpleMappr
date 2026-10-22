<?php
namespace SimpleMappr;

final class SavedMapDocument
{
    private const SCALARS = ['projection', 'projection_map', 'bbox_map', 'bbox_rubberband', 'rotation', 'origin', 'origin_map', 'border_thickness', 'grid_space', 'gridspace', 'hide_gridlabel', 'download_factor', 'download_filetype', 'width', 'height', 'output', 'file_name'];
    private const GROUPS = ['coords' => ['title', 'data', 'color', 'shadow', 'shape', 'size'], 'regions' => ['title', 'data', 'color', 'border', 'hatch'], 'wkt' => ['title', 'data', 'color', 'border', 'hatch']];

    public static function decode(string $json): array
    {
        if (strlen($json) > 2000000) { throw new \InvalidArgumentException('Map settings exceed 2 MB.'); }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (isset($data['map']) && is_array($data['map'])) { $data = $data['map']; }
        if (!is_array($data) || array_is_list($data) || isset($data['type']) || isset($data['features'])) {
            throw new \InvalidArgumentException('Import a SimpleMappr settings document, not GeoJSON.');
        }
        if (isset($data['schema_version']) && $data['schema_version'] !== 1) {
            throw new \InvalidArgumentException('Unsupported map settings version.');
        }
        if (!array_intersect(array_keys($data), array_merge(self::SCALARS, array_keys(self::GROUPS), ['layers']))) {
            throw new \InvalidArgumentException('No map settings were found.');
        }
        return self::fromForm($data);
    }

    public static function fromForm(array $data): array
    {
        $document = ['schema_version' => 1];
        foreach (self::SCALARS as $key) {
            if (array_key_exists($key, $data)) {
                $value = $key === 'bbox_rubberband' && $data[$key] === [] ? '' : $data[$key];
                $document[$key] = self::scalar($value, 2048);
            }
        }
        $title = self::scalar($data['save']['title'] ?? '', 255);
        $document['save'] = ['title' => trim((string)$title)];
        foreach (self::GROUPS as $group => $fields) {
            $document[$group] = [];
            if (!isset($data[$group])) { continue; }
            if (is_array($data[$group]) && array_key_exists('data', $data[$group])) { $data[$group] = [$data[$group]]; }
            if (!is_array($data[$group]) || count($data[$group]) > MAXNUMTEXTAREA) {
                throw new \InvalidArgumentException('Too many map data groups.');
            }
            foreach ($data[$group] as $row) {
                if (!is_array($row)) { throw new \InvalidArgumentException('Invalid map data group.'); }
                $normalized = [];
                foreach ($fields as $field) {
                    if (array_key_exists($field, $row)) { $normalized[$field] = self::scalar($row[$field], $field === 'data' ? 500000 : 1024); }
                }
                $normalized += ['title' => '', 'data' => ''];
                $document[$group][] = $normalized;
            }
        }
        $layerKeys = array_merge(['grid'], array_keys(\SimpleMappr\Mappr\Mappr::getShapefileConfig()));
        foreach (['layers', 'options'] as $group) {
            $document[$group] = [];
            if (!isset($data[$group])) { continue; }
            if (!is_array($data[$group]) || count($data[$group]) > 60) { throw new \InvalidArgumentException('Invalid map options.'); }
            foreach ($data[$group] as $key => $value) {
                if (!in_array($key, $group === 'layers' ? $layerKeys : ['border','legend','scalebar','scalelinethickness'], true)) { throw new \InvalidArgumentException('Invalid map option name.'); }
                $document[$group][$key] = self::scalar($value, 32);
            }
        }
        if (strlen(self::encode($document)) > 2000000) { throw new \InvalidArgumentException('Map settings exceed 2 MB.'); }
        return $document;
    }

    public static function validateForWrite(array $document): void
    {
        $request = Request::fromDocument(self::renderingFields($document));
        Request::validateMapDocument($request);
    }

    public static function encode(array $document): string
    {
        return json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function renderingFields(array $document): array
    {
        unset($document['schema_version'], $document['save']);
        return $document;
    }

    private static function scalar(mixed $value, int $max): mixed
    {
        if (!is_scalar($value) && $value !== null) { throw new \InvalidArgumentException('Invalid map setting.'); }
        if (mb_strlen((string)$value) > $max) { throw new \InvalidArgumentException('Map setting is too long.'); }
        return $value;
    }
}
