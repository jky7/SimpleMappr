<?php
namespace SimpleMappr;

use geoPHP\geoPHP;

final class GeometryExport
{
    public static function features(array $document): array
    {
        $request = Request::fromDocument($document);
        Request::validateMapDocument($request);
        $features = [];
        $rejected = [];
        foreach ($request->coords as $group => $layer) {
            foreach (preg_split('/\R/u', $layer['data']) as $row) {
                if (trim($row) === '') { continue; }
                [$lat, $lon] = Utility::makeCoordinates($row);
                if (!is_numeric($lat) || !is_numeric($lon) || !Utility::onEarth((object)['x'=>$lon,'y'=>$lat])) { $rejected[] = $row; continue; }
                $features[] = ['type'=>'Feature','geometry'=>['type'=>'Point','coordinates'=>[(float)$lon,(float)$lat]], 'properties'=>['title'=>(string)$layer['title'], 'color'=>(string)$layer['color'], 'group'=>'point-'.$group,'size'=>$layer['size'],'shape'=>$layer['shape']]];
            }
        }
        foreach ($request->wkt as $group=>$layer) {
            foreach (preg_split('/\R/u', $layer['data']) as $row) {
                if (trim($row) === '') { continue; }
                try {
                    $geometry = geoPHP::load($row, 'wkt');
                    if (!$geometry || $geometry->isEmpty()) { throw new \InvalidArgumentException('Empty geometry'); }
                    $json = json_decode($geometry->out('json'), true, 64, JSON_THROW_ON_ERROR);
                    $features[] = ['type'=>'Feature','geometry'=>$json,'properties'=>['title'=>(string)$layer['title'],'color'=>(string)$layer['color'],'group'=>'drawing-'.$group,'border'=>filter_var($layer['border'] ?? false,FILTER_VALIDATE_BOOLEAN)]];
                } catch (\Throwable $e) { $rejected[] = $row; }
            }
        }
        return ['type'=>'FeatureCollection','features'=>$features,'rejected_rows'=>$rejected];
    }

    public static function kml(array $document): string
    {
        $collection = self::features($document);
        $writer = new \XMLWriter(); $writer->openMemory(); $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNS(null, 'kml', 'http://www.opengis.net/kml/2.2');
        $writer->startElement('Document');
        $writer->writeElement('name', 'SimpleMappr: '.(string)($document['file_name'] ?? 'Map'));
        if ($collection['rejected_rows']) { $writer->writeElement('description', 'Rejected rows: '.implode('; ', $collection['rejected_rows'])); }
        foreach ($collection['features'] as $index=>$feature) {
            $writer->startElement('Placemark'); $writer->writeAttribute('id', 'feature-'.$index);
            $writer->writeElement('name', $feature['properties']['title']);
            $rgb = preg_split('/\s+/', trim($feature['properties']['color']));
            if (count($rgb) !== 3 || count(array_filter($rgb, 'is_numeric')) !== 3) { $rgb = [0,0,0]; }
            $color = substr(Utility::rgb2hex($rgb[2],$rgb[1],$rgb[0]),1);
            $type = $feature['geometry']['type'];
            $writer->startElement('Style');
            if (in_array($type,['Point','MultiPoint'],true)) {
                $writer->startElement('IconStyle');
                $writer->writeElement('color','ff'.$color);
                $writer->writeElement('scale',(string)max(0.2,min(3,($feature['properties']['size'] ?? 10)/10)));
                $writer->startElement('Icon'); $writer->writeElement('href','https://maps.google.com/mapfiles/kml/paddle/wht-blank.png'); $writer->endElement();
                $writer->endElement();
            } else {
                $writer->startElement('LineStyle'); $writer->writeElement('color','ff'.$color); $writer->writeElement('width','2'); $writer->endElement();
                if (in_array($type,['Polygon','MultiPolygon'],true)) {
                    $writer->startElement('PolyStyle'); $writer->writeElement('color','bf'.$color); $writer->writeElement('outline',!empty($feature['properties']['border']) ? '1' : '0'); $writer->endElement();
                }
            }
            $writer->endElement();
            $geometry = geoPHP::load(json_encode($feature['geometry'], JSON_THROW_ON_ERROR), 'json');
            $writer->writeRaw($geometry->out('kml'));
            $writer->endElement();
        }
        $writer->endElement(); $writer->endElement(); $writer->endDocument();
        return $writer->outputMemory();
    }
}
