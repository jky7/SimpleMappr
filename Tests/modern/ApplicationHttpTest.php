<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApplicationHttpTest extends TestCase
{
    private function request(string $path, ?array $document = null): array
    {
        $base = rtrim(getenv('SIMPLEMAPPR_TEST_URL') ?: 'http://127.0.0.1', '/');
        $curl = curl_init($base.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        if ($document !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($document)]);
        }
        $body = curl_exec($curl);
        self::assertNotFalse($body, curl_error($curl));
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        return [$status, $type, $body];
    }

    private function document(): array
    {
        return [
            'width' => 600, 'height' => 300,
            'bbox_map' => '-100,30,-60,55',
            'layers' => ['countries' => 'on'],
            'coords' => [[
                'title' => 'Toronto', 'data' => '43.6532,-79.3832',
                'shape' => 'circle', 'size' => 16, 'color' => '220 20 20',
            ]],
        ];
    }

    public function testEditorAndFrenchTranslationLoad(): void
    {
        [$status, , $body] = $this->request('/');
        self::assertSame(200, $status);
        self::assertStringContainsString('href="#map-points">Point Data</a>', $body);
        self::assertStringContainsString('href="#map-preview">Preview</a>', $body);
        [$status, , $body] = $this->request('/?locale=fr_FR');
        self::assertSame(200, $status);
        self::assertStringContainsString('Aperçu', $body);
    }

    public function testPreviewReturnsSameOriginMapWithCoordinateMarker(): void
    {
        [$status, $type, $body] = $this->request('/application.json', $this->document());
        self::assertSame(200, $status, $body);
        self::assertStringStartsWith('application/json', $type);
        $map = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $map['bad_points']);
        self::assertStringStartsWith('/public/tmp/', $map['mapOutputImage']);
        [$status, , $bytes] = $this->request($map['mapOutputImage']);
        self::assertSame(200, $status);
        $image = imagecreatefromstring($bytes);
        self::assertNotFalse($image);
        self::assertSame(600, imagesx($image));
        self::assertSame(300, imagesy($image));
        $redPixels = 0;
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $color = imagecolorat($image, $x, $y);
                if (($color >> 16 & 255) > 180 && ($color >> 8 & 255) < 80 && ($color & 255) < 80) {
                    $redPixels++;
                }
            }
        }
        self::assertGreaterThan(80, $redPixels, 'The Toronto marker must appear in the rendered image');
        foreach (['legend_url', 'scalebar_url'] as $key) {
            [$status, , $bytes] = $this->request($map[$key]);
            self::assertSame(200, $status);
            self::assertNotFalse(imagecreatefromstring($bytes));
        }
    }

    public function testDownloadReturnsDecodablePng(): void
    {
        [$status, $type, $bytes] = $this->request('/application/', $this->document() + [
            'output' => 'png', 'download' => 1, 'file_name' => 'Toronto',
        ]);
        self::assertSame(200, $status);
        self::assertStringStartsWith('image/png', $type);
        self::assertSame([600, 300], array_slice(getimagesizefromstring($bytes), 0, 2));
    }

    public static function projections(): array
    {
        return [
            'geographic' => ['epsg:4326', '-100,30,-60,55', '43.65,-79.38'],
            'north America' => ['esri:102009', '-130,20,-50,70', '43.65,-79.38'],
            'south America' => ['esri:102015', '-85,-55,-35,15', '-23.55,-46.63'],
            'Europe' => ['esri:102014', '-15,30,40,70', '48.85,2.35'],
            'Asia' => ['esri:102012', '60,10,150,70', '35.68,139.69'],
            'Africa' => ['esri:102024', '-20,-35,55,38', '-1.28,36.82'],
            'Australia' => ['epsg:3112', '110,-45,155,-10', '-33.86,151.20'],
            'north pole' => ['epsg:102017', '-90,60,90,89', '78.22,15.65'],
            'south pole' => ['epsg:102019', '-90,-89,90,-60', '-77.85,166.66'],
            'Robinson' => ['epsg:54030', '-180,-80,180,80', '43.65,-79.38'],
            'Mercator' => ['epsg:3395', '-180,-80,180,80', '43.65,-79.38'],
        ];
    }

    #[DataProvider('projections')]
    public function testEachProjectionRendersFiniteViewport(string $projection, string $bounds, string $point): void
    {
        $document = $this->document();
        $document['projection'] = $projection;
        $document['bbox_map'] = $bounds;
        $document['coords'][0]['data'] = $point;
        [$status, , $body] = $this->request('/application.json', $document);
        self::assertSame(200, $status, $body);
        $map = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($projection, $map['rendered_projection']);
        foreach (explode(',', $map['rendered_bbox']) as $value) {
            self::assertTrue(is_numeric($value) && is_finite((float)$value), $body);
        }
        [$status, , $bytes] = $this->request($map['mapOutputImage']);
        self::assertSame(200, $status);
        self::assertSame([600, 300], array_slice(getimagesizefromstring($bytes), 0, 2));
    }

    public function testInvalidDimensionsReturnUsefulErrorAndNextRenderWorks(): void
    {
        foreach ([['width' => 900000], ['height' => 0], ['download' => 1, 'download_factor' => 100], ['output' => 'docx', 'download_factor' => 100], ['crop' => 1, 'bbox_rubberband' => '0,0,100000,100000']] as $changes) {
            [$status, $type, $body] = $this->request('/application.json', array_replace($this->document(), $changes));
            self::assertSame(400, $status);
            self::assertStringStartsWith('application/json', $type);
            self::assertNotEmpty(json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']);
        }
        [$status] = $this->request('/application.json', $this->document());
        self::assertSame(200, $status);
    }

    public function testMalformedCoordinateIsReportedWithoutBreakingValidPoints(): void
    {
        $document = $this->document();
        $document['coords'][0]['data'] .= "\ninvalid\n91,200";
        [$status, , $body] = $this->request('/application.json', $document);
        self::assertSame(200, $status);
        $map = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('invalid', $map['bad_points']);
        self::assertStringContainsString('91,200', $map['bad_points']);
        [$status, , $bytes] = $this->request($map['mapOutputImage']);
        self::assertSame(200, $status);
        self::assertNotFalse(imagecreatefromstring($bytes));
    }

    public function testUnavailableLayerReturnsUsefulError(): void
    {
        [$status, , $body] = $this->request('/application.json', array_replace($this->document(), ['layers' => ['marine_ecoregions' => 'on']]));
        self::assertSame(400, $status);
        self::assertStringContainsString('not installed', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    public function testSouthPoleSelectionFromWorldExtentCompletes(): void
    {
        [$status, , $body] = $this->request('/application.json', array_replace($this->document(), [
            'projection' => 'epsg:102019', 'bbox_map' => '-180,-90,180,90', 'coords' => [],
        ]));
        self::assertSame(200, $status, $body);
        self::assertSame('epsg:102019', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['rendered_projection']);
    }

    public function testCustomOriginViewportSurvivesRefreshAndOriginChange(): void
    {
        $document = array_replace($this->document(), ['projection' => 'esri:102009', 'origin' => -120, 'bbox_map' => '-130,20,-50,70']);
        $previous = null;
        foreach ([-120, -120, -120, 0, 0, 0] as $origin) {
            $document['origin'] = $origin;
            [$status, , $body] = $this->request('/application.json', $document);
            self::assertSame(200, $status, $body);
            $map = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if ($previous !== null && $document['origin_map'] == $origin) {
                foreach (explode(',', $map['rendered_bbox']) as $index => $value) {
                    self::assertEqualsWithDelta((float)explode(',', $previous)[$index], (float)$value, 0.000001);
                }
            }
            $previous = $map['rendered_bbox'];
            $document['bbox_map'] = $map['rendered_bbox'];
            $document['projection_map'] = $map['rendered_projection'];
            $document['origin_map'] = $map['rendered_origin'];
            self::assertEquals($origin, $map['rendered_origin']);
        }
    }

    public function testSvgAndTiffDownloadsRemainValid(): void
    {
        foreach (['svg', 'tif'] as $format) {
            [$status, , $body] = $this->request('/application', $this->document() + ['output' => $format, 'download' => 1]);
            self::assertSame(200, $status, substr($body, 0, 100));
            if ($format === 'svg') {
                self::assertSame('svg', simplexml_load_string($body)->getName());
            } else {
                self::assertContains(substr($body, 0, 4), ["II*\0", "MM\0*"]);
            }
        }
    }

    public function testSimpleApiPointsStillRender(): void
    {
        [$status, , $body] = $this->request('/api/?points=43.65,-79.38');
        self::assertSame(200, $status);
        self::assertSame('image/png', getimagesizefromstring($body)['mime']);
    }

    public function testPrivateApplicationFilesCannotBeDownloaded(): void
    {
        foreach (['/config/defaults.php', '/composer.lock', '/vendor/autoload.php', '/src/Mappr/Mappr.php'] as $path) {
            [$status] = $this->request($path);
            self::assertContains($status, [403, 404], $path);
        }
    }
}
