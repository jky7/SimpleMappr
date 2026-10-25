<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApplicationHttpTest extends TestCase
{
    private function request(string $path, ?array $document = null, array $headers = []): array
    {
        $base = rtrim(getenv('SIMPLEMAPPR_TEST_URL') ?: 'http://127.0.0.1', '/');
        $curl = curl_init($base.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
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

    #[DataProvider('standalonePages')]
    public function testEveryStandalonePageLoadsTheSharedStylesheetAndHeader(string $path, int $status): void
    {
        [$code, $type, $body] = $this->request($path);
        self::assertSame($status, $code, $path);
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/public/build/pages-css\.[0-9a-f]{12}\.css">#', $body, $path);
        self::assertStringContainsString('<h1 id="site-title"', $body, $path);
        self::assertStringNotContainsString('style="max-width', $body, 'layout belongs in the stylesheet, not inline');
        preg_match('#href="(/public/build/pages-css\.[0-9a-f]{12}\.css)"#', $body, $match);
        [$cssStatus, $cssType] = $this->request($match[1]);
        self::assertSame(200, $cssStatus);
        self::assertStringStartsWith('text/css', $cssType);
    }

    public static function standalonePages(): array
    {
        return [['/login', 200], ['/data-credits', 200], ['/no-such-page', 404]];
    }

    public function testTabFragmentsStayBareForTheEditorAndSendDirectVisitsToTheEditorTab(): void
    {
        $base = rtrim(getenv('SIMPLEMAPPR_TEST_URL') ?: 'http://127.0.0.1', '/');
        foreach (['about', 'help', 'feedback', 'apidoc'] as $tab) {
            [$status, , $fragment] = $this->request('/'.$tab, null, ['X-Requested-With: XMLHttpRequest']);
            self::assertSame(200, $status, $tab);
            self::assertStringNotContainsString('<html', $fragment, $tab);
            $curl = curl_init($base.'/'.$tab.'?locale=fr_FR');
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
            curl_exec($curl);
            self::assertSame(303, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $tab);
            self::assertSame($base.'/?locale=fr_FR#tabs='.$tab, curl_getinfo($curl, CURLINFO_REDIRECT_URL), $tab);
        }
        [$status, , $editor] = $this->request('/?locale=fr_FR');
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/public/build/editor-css\.[0-9a-f]{12}\.css">#', $editor);
        foreach (['help', 'about', 'feedback', 'apidoc'] as $tab) {
            self::assertStringContainsString('href="'.$tab.'?locale=fr_FR"', $editor, 'the editor tab the redirect targets exists');
        }
    }

    public function testOwnerLoginHasItsOwnUnlistedNoindexPageAndLoginOffersOnlyProviders(): void
    {
        $base = rtrim(getenv('SIMPLEMAPPR_TEST_URL') ?: 'http://127.0.0.1', '/');
        $ownerPath = getenv('SIMPLEMAPPR_OWNER_LOGIN_PATH') ?: '/owner';
        [$status, , $login] = $this->request('/login');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('name="username"', $login);
        self::assertStringNotContainsString('name="password"', $login);
        foreach (['/login', '/'] as $path) {
            [, , $page] = $this->request($path);
            self::assertStringNotContainsString($ownerPath.'"', $page, $path.' must not link to the owner login');
        }
        [$status] = $this->request('/admin');
        self::assertSame(404, $status, 'the old /admin path is gone');
        $curl = curl_init($base.$ownerPath);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
        $raw = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $body = substr($raw, curl_getinfo($curl, CURLINFO_HEADER_SIZE));
        if ($code === 404) {
            self::assertStringNotContainsString('name="password"', $body, 'without a configured owner the owner path is an ordinary 404');
            self::markTestSkipped('No local owner is configured in this deployment.');
        }
        self::assertSame(200, $code);
        self::assertStringContainsString('name="username"', $body);
        self::assertStringContainsString('name="password"', $body);
        self::assertStringContainsString('action="/session"', $body);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow, noarchive">', $body);
        self::assertMatchesRegularExpression('/^X-Robots-Tag: noindex, nofollow\r$/mi', $raw);
        self::assertMatchesRegularExpression('/^Cache-Control: .*no-store/mi', $raw);
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/public/build/pages-css\.[0-9a-f]{12}\.css">#', $body);
    }

    public function testAboutShowsCurrentUpdatesWithTheOldOnesCollapsedAndTranslated(): void
    {
        foreach (['en_US' => ["What's new", 'Map data', 'History'], 'fr_FR' => ['Nouveautés', 'Données de la carte', 'Histoire']] as $locale => $words) {
            [$status, , $body] = $this->request('/about?locale='.$locale, null, ['X-Requested-With: XMLHttpRequest']);
            self::assertSame(200, $status, $locale);
            foreach ($words as $word) { self::assertStringContainsString(htmlspecialchars($word, ENT_QUOTES), $body, $locale); }
            self::assertMatchesRegularExpression('#<details class="update-history">\s*<summary>[^<]+</summary>#', $body, 'old updates sit behind the History toggle');
            self::assertLessThan(strpos($body, '<details class="update-history">'), strpos($body, $locale === 'fr_FR' ? '24 octobre 2026' : 'October 24, 2026'), 'the 2026 entries come before the toggle');
            self::assertGreaterThan(strpos($body, '<details class="update-history">'), strpos($body, $locale === 'fr_FR' ? '8 mai, 2018' : 'May 8, 2018'), 'the 2018 entries are inside the toggle');
            self::assertStringNotContainsString('In the Wild', $body);
            self::assertStringNotContainsString('Trouvés ailleurs', $body);
            self::assertStringContainsString('/data-credits"', $body);
            self::assertStringContainsString('Kass Youssef, Joseph. 2026. SimpleMappr (version 4.0)', $body);
            self::assertStringContainsString($locale === 'fr_FR' ? '[Logiciel], d&#039;après SimpleMappr de David P. Shorthouse (2010). ' : '[Computer software], based on SimpleMappr by David P. Shorthouse (2010). ', $body, 'the original author is named in the same citation');
            self::assertStringContainsString($locale === 'fr_FR' ? 'L&#039;application originale :' : 'The original application:', $body);
            self::assertStringContainsString('Shorthouse, David P. 2010. SimpleMappr, an online tool to produce publication-quality point maps. https://www.simplemappr.net.', $body);
            $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
            // The page is dated in Router's America/Toronto zone, not this process's UTC.
            $now = new DateTimeImmutable('now', new DateTimeZone('America/Toronto'));
            $accessed = $locale === 'fr_FR' ? 'Consulté le '.$now->format('j').' '.$months[$now->format('n') - 1].' '.$now->format('Y').'.' : 'Accessed '.$now->format('F j, Y').'.';
            self::assertStringContainsString($accessed, $body, 'the access date is written in the page language');
            self::assertMatchesRegularExpression('#\(2010\)\. https?://[^ ]+\. #', $body, 'the citation names this instance by its own address');
        }
    }

    public function testViewRectZoomsAroundTheChosenPointInBothDirectionsAndRejectsBadViews(): void
    {
        $render = function (string $view): array {
            // A 2:1 box on the 2:1 canvas, so the reported box is the visible view (no sideways padding).
            [$status, , $body] = $this->request('/application.json', array_replace($this->document(), ['coords' => [], 'bbox_map' => '-100,30,-60,50', 'view_rect' => $view]));
            self::assertSame(200, $status, $view.' '.$body);
            return array_map('floatval', explode(',', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['rendered_bbox']));
        };
        $still = $render('');
        $width = $still[2] - $still[0];
        $height = $still[3] - $still[1];
        $centre = fn (array $b) => [($b[0] + $b[2]) / 2, ($b[1] + $b[3]) / 2];
        $in = $render('150,75,450,225');
        self::assertEqualsWithDelta($width / 2, $in[2] - $in[0], 0.3, '2x in halves the width');
        self::assertEqualsWithDelta($centre($still)[0], $centre($in)[0], 0.3, 'around the centre the centre stays');
        $out = $render('-300,-150,900,450');
        self::assertEqualsWithDelta($width * 2, $out[2] - $out[0], 0.6, '2x out doubles the width');
        self::assertEqualsWithDelta($centre($still)[1], $centre($out)[1], 0.6);
        $corner = $render('0,0,300,150');
        self::assertEqualsWithDelta($still[0], $corner[0], 0.3, 'zooming in at the top-left corner keeps its longitude');
        self::assertEqualsWithDelta($still[3], $corner[3], 0.3, 'and its latitude');
        $outCorner = $render('0,0,1200,600');
        self::assertEqualsWithDelta($still[0], $outCorner[0], 0.6, 'zooming out at the corner keeps it too');
        self::assertEqualsWithDelta($still[3], $outCorner[3], 0.6);
        foreach (['1,2,3', 'x,0,10,10', '300,150,0,0', '0,0,5,2', '-100000,0,100000,300', '0,0,600,300,1'] as $bad) {
            [$status, , $body] = $this->request('/application.json', array_replace($this->document(), ['view_rect' => $bad]));
            self::assertSame(400, $status, $bad);
            self::assertNotEmpty(json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']);
        }
        foreach (['pan' => '5,0', 'bbox_rubberband' => '10,10,10,10'] as $field => $value) {
            [$status] = $this->request('/application.json', array_replace($this->document(), ['view_rect' => '150,75,450,225', $field => $value]));
            self::assertSame(400, $status, 'view_rect with '.$field);
        }
    }

    public function testEveryZoomReportsTheVisibleViewEvenWhenTheBoxDidNotFitTheCanvas(): void
    {
        $render = function (array $changes): array {
            // 40 by 25 degrees on a 2:1 canvas: the server pads the view sideways to fit.
            [$status, , $body] = $this->request('/application.json', array_replace($this->document(), ['coords' => [], 'bbox_map' => '-100,30,-60,55'], $changes));
            self::assertSame(200, $status, $body);
            return array_map('floatval', explode(',', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['rendered_bbox']));
        };
        self::assertSame([-100.0, 30.0, -60.0, 55.0], $render([]), 'without a zoom the requested box is reported as before');
        foreach (['wheel view' => ['view_rect' => '150,75,450,225'], 'zoom in button' => ['zoom_in' => 1], 'zoom out button' => ['zoom_out' => 1],
                  'double-click' => ['bbox_rubberband' => '300,150,300,150'], 'selection' => ['bbox_rubberband' => '100,50,500,250']] as $label => $zoom) {
            $box = $render($zoom);
            self::assertEqualsWithDelta(2.0, ($box[2] - $box[0]) / ($box[3] - $box[1]), 0.02, $label.' reports the 2:1 view the canvas shows');
            $again = $render(['bbox_map' => implode(',', $box)]);
            foreach ($box as $i => $value) { self::assertEqualsWithDelta($value, $again[$i], 0.05 * ($box[2] - $box[0]) / 600 * 10, $label.' is stable when echoed back'); }
        }
    }

    public function testSiteTitleLinksToTheEditorRootOnTheEditorAndErrorPages(): void
    {
        foreach (['/', '/no-such-page'] as $path) {
            [, , $body] = $this->request($path);
            self::assertStringContainsString('<h1 id="site-title" itemprop="name"><a href="/">SimpleMapp<span>r</span></a></h1>', $body, $path);
        }
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

    public function testPixelOffsetPanMovesTheViewTheOppositeWayAndBadOffsetsAreRejected(): void
    {
        $render = function (array $changes): array {
            [$status, , $body] = $this->request('/application.json', array_replace($this->document(), ['coords' => [], 'bbox_map' => '-100,30,-60,55'], $changes));
            self::assertSame(200, $status, $body);
            return array_map('floatval', explode(',', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['rendered_bbox']));
        };
        $still = $render([]);
        $dragged = $render(['pan' => '60,0']);
        $width = $still[2] - $still[0];
        // Dragging the map 60 of 600 pixels to the right shows ground further west.
        self::assertEqualsWithDelta($still[0] - $width / 10, $dragged[0], 0.2);
        self::assertEqualsWithDelta($still[1], $dragged[1], 0.2);
        self::assertLessThan($still[1] - 0.5, $render(['pan' => '0,-30'])[1], 'dragging the map up pulls ground from the south into view');
        self::assertGreaterThan($still[1] + 0.5, $render(['pan' => '0,30'])[1], 'dragging the map down pulls ground from the north into view');
        self::assertEqualsWithDelta($still[0], $render(['pan' => 'right'])[0] - 0.05 * $width, 0.3);
        foreach (['1,2,3', '1.5,2', 'x,1', '999999,0', '--1,0'] as $pan) {
            [$status, $type, $body] = $this->request('/application.json', array_replace($this->document(), ['pan' => $pan]));
            self::assertSame(400, $status, $pan);
            self::assertNotEmpty(json_decode($body, true, 512, JSON_THROW_ON_ERROR)['error']);
        }
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
