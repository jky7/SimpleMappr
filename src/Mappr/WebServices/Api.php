<?php

/**
 * SimpleMappr - create point maps for publications and presentations
 *
 * PHP Version >= 5.6
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 *
 * MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 */
namespace SimpleMappr\Mappr\WebServices;

use SimpleMappr\RemoteImport;
use SimpleMappr\ImportData;
use SimpleMappr\Request;
use SimpleMappr\SavedMapDocument;
use SimpleMappr\TemporaryImage;
use SimpleMappr\Header;
use SimpleMappr\Utility;
use SimpleMappr\Constants\AcceptedOutputs;
use SimpleMappr\Mappr\Mappr;

/**
 * API handler for SimpleMappr
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 */
class Api extends Mappr
{
    private array $rejectedDrawings = [];

    public function getRequest()
    {
        $input = array_change_key_case($_REQUEST, CASE_LOWER);
        $scalar = static function (string $key, mixed $default = '') use ($input): mixed {
            $value = $input[$key] ?? $default;
            if (!is_scalar($value) && $value !== null) { throw new \InvalidArgumentException('Invalid API parameter '.$key.'.'); }
            if (is_string($value) && strlen($value) > RemoteImport::MAX_BYTES) { throw new \InvalidArgumentException('The API parameter exceeds 5 MiB.'); }
            return $value;
        };
        $import = [];
        $bytes = null;
        if (isset($_FILES['file'])) {
            $file = $_FILES['file'];
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
                || ($file['size'] ?? 0) > RemoteImport::MAX_BYTES
            ) { throw new \InvalidArgumentException('Upload a file no larger than 5 MiB.'); }
            $bytes = file_get_contents($file['tmp_name'], false, null, 0, RemoteImport::MAX_BYTES + 1);
        } elseif ($scalar('url', $scalar('file', '')) !== '') {
            $bytes = (new RemoteImport())->fetch((string)$scalar('url', $scalar('file', '')));
        } elseif (isset($input['data'])) { $bytes = (string)$scalar('data'); }
        if ($bytes !== null) {
            $import = ImportData::parse($bytes, strtolower((string)$scalar('input_format', 'auto')), (string)$scalar('encoding', 'UTF-8'));
            $this->rejectedDrawings = $import['bad_drawings'];
        }
        $values = isset($import['document']) ? SavedMapDocument::renderingFields($import['document']) : [];
        foreach (['coords','wkt','regions'] as $group) { if (!empty($import[$group])) { $values[$group] = $import[$group]; } }
        foreach (['width','height','rotation','origin','graticules','hide_gridlabel'] as $key) {
            if (array_key_exists($key, $input)) { $values[$key] = $scalar($key); }
        }
        $values['output'] = strtolower((string)$scalar('output', $values['output'] ?? 'png'));
        if (!in_array($values['output'], AcceptedOutputs::outputList(), true)) { throw new \InvalidArgumentException('The API output format is not supported.'); }
        $values['projection'] = strtolower((string)$scalar('projection', $values['projection'] ?? 'epsg:4326'));
        if (array_key_exists('bbox', $input)) { $values['bbox_map'] = $scalar('bbox'); $values['projection_map'] = 'epsg:4326'; $values['origin_map'] = null; }
        if (array_key_exists('layers', $input)) {
            $values['layers'] = ['countries'=>'on'];
            foreach (explode(',', (string)$scalar('layers')) as $name) { if (trim($name) !== '') { $values['layers'][trim($name)] = 'on'; } }
        } else { $values['layers'] ??= ['countries'=>'on']; }
        $values['watermark'] = filter_var($scalar('watermark', true), FILTER_VALIDATE_BOOLEAN);
        $values['download'] = true;
        $values['options'] = $values['options'] ?? [];
        foreach (['legend','border','scalebar'] as $key) {
            if (array_key_exists($key, $input)) { $values['options'][$key] = ($key === 'legend' && is_array($input[$key])) || filter_var($scalar($key), FILTER_VALIDATE_BOOLEAN); }
        }
        $values['border_thickness'] = $scalar('thickness', $values['border_thickness'] ?? 1.25);
        $values['gridspace'] = $scalar('spacing', $values['gridspace'] ?? false);
        $list = static function (string $key) use ($input): array {
            $value = $input[$key] ?? [];
            return is_array($value) ? array_values($value) : [$value];
        };
        foreach ($list('points') as $key => $data) {
            if (!is_string($data)) { throw new \InvalidArgumentException('Invalid API point group.'); }
            $row = ['title'=>is_array($input['legend'] ?? null) ? ($list('legend')[$key] ?? '') : '', 'data'=>str_replace(['\\r','\\n'], ["\r","\n"], $data)];
            $values['coords'][] = $row;
        }
        $values['coords'] ??= [];
        foreach ($values['coords'] as $key=>&$row) {
            if (is_array($input['legend'] ?? null) && isset($list('legend')[$key])) { $row['title'] = $list('legend')[$key]; }
            foreach (['shape','size','color','shadow'] as $style) {
                if (isset($list($style)[$key])) { $row[$style] = $list($style)[$key]; }
            }
            $row += ['color'=>'0 0 0','size'=>10];
            if (is_array($row['color'])) { $row['color'] = implode(' ', $row['color']); }
            if (is_string($row['color'])) { $row['color'] = str_replace(',', ' ', $row['color']); }
        }
        unset($row);
        foreach ($list('wkt') as $row) {
            $row = is_array($row) ? $row : ['data'=>$row];
            if (isset($row['color']) && is_array($row['color'])) { $row['color'] = implode(' ', $row['color']); }
            if (isset($row['color']) && is_string($row['color'])) { $row['color'] = str_replace(',', ' ', $row['color']); }
            $values['wkt'][] = $row;
        }
        if (isset($input['shade'])) {
            if (!is_array($input['shade'])) { throw new \InvalidArgumentException('Invalid region style.'); }
            $shade = $input['shade'];
            $values['regions'][] = ['data'=>$shade['places'] ?? '', 'title'=>$shade['title'] ?? '', 'color'=>str_replace(',', ' ', (string)($shade['color'] ?? '120 120 120'))];
        }
        $zoom = (int)$scalar('zoom', 0);
        if ($zoom >= 1 && $zoom <= 10) { $values = $this->zoomToPoints($values, $zoom); }
        $request = Request::fromDocument($values);
        $outline = $input['outlinecolor'] ?? '';
        if (is_array($outline)) { $outline = implode(' ', $outline); }
        if (!is_string($outline) || strlen($outline) > 32) { throw new \InvalidArgumentException('Invalid marker outline color.'); }
        $request->outlinecolor = str_replace(',', ' ', $outline);
        $request->graticules = filter_var($scalar('graticules', $request->graticules), FILTER_VALIDATE_BOOLEAN);
        if ($request->graticules) { $request->layers['grid'] = 'on'; } else { unset($request->layers['grid']); }
        $request->ping = filter_var($scalar('ping', false), FILTER_VALIDATE_BOOLEAN);
        $request->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        return $request;
    }

    private function zoomToPoints(array $values, int $zoom): array
    {
        $x=$y=$z=0; $count=0;
        foreach ($values['coords'] as $group) {
            if (!is_string($group['data'] ?? null)) { continue; }
            foreach (preg_split('/\R/u', $group['data']) as $row) {
                [$lat,$lon] = Utility::makeCoordinates($row);
                if (!is_numeric($lat) || !is_numeric($lon) || !Utility::onEarth((object)['x'=>$lon,'y'=>$lat])) { continue; }
                $lat=deg2rad($lat); $lon=deg2rad($lon);
                $x+=cos($lat)*cos($lon); $y+=cos($lat)*sin($lon); $z+=sin($lat); $count++;
            }
        }
        if ($count === 0) { return $values; }
        $lon=rad2deg(atan2($y,$x)); $lat=rad2deg(atan2($z,sqrt($x*$x+$y*$y)));
        $halfWidth=90/$zoom; $halfHeight=45/$zoom;
        $values['bbox_map']=implode(',', [max(-180,$lon-$halfWidth),max(-90,$lat-$halfHeight),min(180,$lon+$halfWidth),min(90,$lat+$halfHeight)]);
        $values['projection_map']='epsg:4326'; $values['origin_map']=null;
        return $values;
    }

    public function createOutput()
    {
        if ($this->request->ping) { Header::setHeader('json'); return json_encode(['status'=>'ok']); }
        if ($this->request->method === 'GET') {
            Header::setHeader($this->request->output);
            return \SimpleMappr\RenderedImage::bytes($this->image, $this->map_obj, $this->request->output);
        }
        $image = TemporaryImage::write($this->image, $this->map_obj, $this->request->output === 'pnga' ? 'png' : $this->request->output);
        $expiry = time() + 3600;
        Header::setHeader('json');
        http_response_code(303);
        header('Location: '.$image['url']);
        header('Expires: '.gmdate('D, d M Y H:i:s', $expiry).' GMT');
        return json_encode(['imageURL'=>$image['url'],'expiry'=>date('c', $expiry), 'bad_points'=>$this->bad_points, 'bad_drawings'=>array_merge($this->bad_drawings, $this->rejectedDrawings)], JSON_UNESCAPED_UNICODE);
    }
}
