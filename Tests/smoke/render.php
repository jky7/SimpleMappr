<?php
declare(strict_types=1);
require __DIR__.'/runtime.php';

function requireCheck(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

try {
    $report = nativeRuntimeReport();
    $output = '/var/lib/simplemappr/checks';
    requireCheck(is_dir($output) || mkdir($output, 0755, true), 'Cannot create output directory');
    $map = new mapObj(__DIR__.'/native.map');
    $isolated = $map->cloneMap();
    for ($index = 0; $index < $isolated->numlayers; ++$index) {
        $isolated->getLayer($index)->status = MS_OFF;
    }
    $isolated->imagecolor->setRGB(255, 255, 255);
    $empty = $isolated->draw()->getBytes();
    $report['layers'] = [];
    foreach (['land', 'countries', 'lakes', 'provinces', 'province-borders'] as $name) {
        $layer = $map->getLayerByName($name);
        requireCheck($layer !== null, 'Missing layer '.$name);
        $layer->open();
        $count = $layer->getNumFeatures();
        $layer->close();
        requireCheck($count > 0, 'Empty layer '.$name);
        $isolated->getLayerByName($name)->status = MS_ON;
        requireCheck($isolated->draw()->getBytes() !== $empty, 'Layer does not render '.$name);
        $isolated->getLayerByName($name)->status = MS_OFF;
        $report['layers'][$name] = ['features' => $count, 'rendered' => true];
    }
    $report['outputs'] = [];
    foreach (['png', 'svg', 'tif'] as $format) {
        $map->selectOutputFormat($format);
        $file = 'native-baseline.'.$format;
        $map->draw()->save($output.'/'.$file);
        requireCheck(filesize($output.'/'.$file) > 1000, 'Empty output '.$file);
        $report['outputs'][$file] = ['sha256' => hash_file('sha256', $output.'/'.$file), 'bytes' => filesize($output.'/'.$file)];
    }
    $image = imagecreatefrompng($output.'/native-baseline.png');
    requireCheck(imagesx($image) === 800 && imagesy($image) === 400, 'Incorrect PNG dimensions');
    $markerPixels = 0;
    $landPixels = 0;
    for ($y = 0; $y < 400; ++$y) {
        for ($x = 0; $x < 800; ++$x) {
            $rgb = imagecolorat($image, $x, $y) & 0xffffff;
            $red = ($rgb >> 16) & 255;
            $green = ($rgb >> 8) & 255;
            $blue = $rgb & 255;
            if ($red > 160 && $green < 80 && $blue < 90) {
                ++$markerPixels;
                requireCheck($x >= 215 && $x <= 235 && $y >= 94 && $y <= 114, 'Toronto marker is outside its expected coordinates');
            }
            if ($rgb === 0xd2e1cd || $rgb === 0xe1ded2) {
                ++$landPixels;
            }
        }
    }
    requireCheck($markerPixels > 40, 'Toronto marker is absent');
    requireCheck($landPixels > 10000, 'Land layer is absent');
    $report['markerPixels'] = $markerPixels;
    $report['landPixels'] = $landPixels;
    $svg = new DOMDocument();
    requireCheck($svg->load($output.'/native-baseline.svg') && $svg->documentElement->localName === 'svg', 'Invalid SVG document');
    $map->selectOutputFormat('png');
    $withLabel = $map->draw()->getBytes();
    $map->getLayerByName('accent-label')->status = MS_OFF;
    $withoutLabel = $map->draw()->getBytes();
    requireCheck($withLabel !== $withoutLabel, 'Accent label does not render');
    $asciiMap = msLoadMapFromString(str_replace('Montréal', 'Montreal', file_get_contents(__DIR__.'/native.map')), __DIR__, null);
    requireCheck($withLabel !== $asciiMap->draw()->getBytes(), 'Accent glyph does not differ from ASCII label');
    $report['accentLabel'] = 'Montréal rendered with DejaVu Sans';
    $map->getLayerByName('accent-label')->status = MS_ON;
    $source = new projectionObj('+proj=longlat +datum=WGS84');
    $lambert = '+proj=lcc +lat_1=20 +lat_2=60 +lat_0=40 +lon_0=-96 +datum=NAD83 +units=m';
    $target = new projectionObj($lambert);
    $point = new pointObj(-79.3832, 43.6532);
    $point->project($source, $target);
    $point->project($target, $source);
    requireCheck(abs($point->x + 79.3832) < 0.00001 && abs($point->y - 43.6532) < 0.00001, 'Lambert projection round trip failed');
    $map->setProjection($lambert);
    $map->setExtent(-3500000, -2500000, 3500000, 2500000);
    $map->draw()->save($output.'/native-lambert.png');
    requireCheck(hash_file('sha256', $output.'/native-lambert.png') !== $report['outputs']['native-baseline.png']['sha256'], 'Projection output is unchanged');
    $report['outputs']['native-lambert.png'] = ['sha256' => hash_file('sha256', $output.'/native-lambert.png'), 'bytes' => filesize($output.'/native-lambert.png')];
    $report['projectionRoundTrip'] = true;
    file_put_contents($output.'/report.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
