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
namespace SimpleMappr;

/**
 * Default Request for SimpleMappr
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 */
class Request
{
    /**
     * Produce the default attributes for a request object
     *
     * @return object
     */
    public static function defaultAttributes(): object
    {
        $attr = new \stdClass();
        $attr->coords           = [];
        $attr->regions          = [];
        $attr->wkt              = [];
        $attr->output           = 'png';
        $attr->width            = 900;
        $attr->height           = $attr->width/2;
        $attr->projection       = 'epsg:4326';
        $attr->projection_map   = 'epsg:4326';
        $attr->origin           = false;
        $attr->origin_map       = null;
        $attr->bbox_map         = '-180,-90,180,90';
        $attr->bbox_rubberband  = [];
        $attr->pan              = false;
        $attr->layers           = [];
        $attr->graticules       = false;
        $attr->watermark        = false;
        $attr->gridspace        = false;
        $attr->gridlabel        = true;
        $attr->download         = false;
        $attr->crop             = false;
        $attr->options          = [];
        $attr->border_thickness = 1.25;
        $attr->rotation         = 0;
        $attr->zoom_in          = false;
        $attr->zoom_out         = false;
        $attr->download_factor  = 1;
        $attr->file_name        = time();

        return $attr;
    }

    public static function fromDocument(array $values): object
    {
        $defaults = (array)self::defaultAttributes();
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $values) && ($values[$key] === '' || $values[$key] === null)) { unset($values[$key]); }
        }
        $request = (object)array_merge($defaults, $values);
        if (!isset($values['height']) && is_numeric($request->width)) { $request->height = (float)$request->width / 2; }
        if ($request->origin_map === null) { $request->origin_map = $request->origin; }
        $request->graticules = is_array($request->layers) && array_key_exists('grid', $request->layers);
        return $request;
    }

    public static function getRequest()
    {
        $attr = new \stdClass();
        $attr->coords           = Utility::loadParam('coords', []);
        $attr->regions          = Utility::loadParam('regions', []);
        $attr->wkt              = Utility::loadParam('wkt', []);
        $attr->output           = Utility::loadParam('output', 'png');
        $attr->width            = (float)Utility::loadParam('width', 900);
        $attr->height           = (float)Utility::loadParam('height', $attr->width/2);
        $attr->projection       = Utility::loadParam('projection', 'epsg:4326');
        $attr->projection_map   = Utility::loadParam('projection_map', 'epsg:4326');
        $attr->origin           = Utility::loadParam('origin', null);
        $attr->origin_map       = Utility::loadParam('origin_map', $attr->origin);
        $attr->bbox_map         = Utility::loadParam('bbox_map', '-180,-90,180,90');
        $attr->bbox_rubberband  = Utility::loadParam('bbox_rubberband', []);
        $attr->pan              = Utility::loadParam('pan', false);
        $attr->layers           = Utility::loadParam('layers', []);
        $attr->graticules       = is_array($attr->layers) && array_key_exists('grid', $attr->layers);
        $attr->watermark        = Utility::loadParam('watermark', false);
        $attr->gridspace        = Utility::loadParam('gridspace', false);
        $attr->hide_gridlabel   = Utility::loadParam('hide_gridlabel', false);
        $attr->download         = Utility::loadParam('download', false);
        $attr->crop             = Utility::loadParam('crop', false);
        $attr->options          = Utility::loadParam('options', []);
        $attr->border_thickness = (float)Utility::loadParam('border_thickness', 1.25);
        $attr->rotation         = (int)Utility::loadParam('rotation', 0);
        $attr->zoom_in          = Utility::loadParam('zoom_in', false);
        $attr->zoom_out         = Utility::loadParam('zoom_out', false);
        $attr->download_factor  = (int)Utility::loadParam('download_factor', 1);
        $attr->file_name        = Utility::loadParam('file_name', time());
        $attr->download_token   = Utility::loadParam('download_token', md5(time()));
        setcookie("fileDownloadToken", $attr->download_token, time()+3600, "/");

        return $attr;
    }
    public static function validateMapDocument($document): void
    {
        foreach (['width', 'height'] as $key) {
            $value = $document->{$key};
            if (!is_numeric($value) || !is_finite((float)$value) || $value < 2 || $value > 5000) {
                throw new \InvalidArgumentException('Map width and height must be between 2 and 5000 pixels.');
            }
        }
        $factor = ($document->download || in_array($document->output, ['pptx', 'docx'], true)) ? $document->download_factor : 1;
        if ($factor < 1 || $factor > 5 || $document->width * $factor > 5000
            || $document->height * $factor > 5000 || $document->width * $document->height * $factor * $factor > 20000000
        ) {
            throw new \InvalidArgumentException('The requested export exceeds the 5000 pixel or 20 megapixel limit.');
        }
        foreach (['projection', 'projection_map'] as $key) {
            if (!is_string($document->{$key}) || !isset(\SimpleMappr\Constants\AcceptedProjections::$projections[$document->{$key}])) {
                throw new \InvalidArgumentException('The selected projection is not supported.');
            }
        }
        foreach (['coords', 'regions', 'wkt', 'layers', 'options'] as $key) {
            if (!is_array($document->{$key})) {
                throw new \InvalidArgumentException('Map layers must be submitted as arrays.');
            }
        }
        foreach (['coords', 'regions', 'wkt'] as $key) {
            if (count($document->{$key}) > MAXNUMTEXTAREA) {
                throw new \InvalidArgumentException('Too many map layers were submitted.');
            }
            foreach ($document->{$key} as &$layer) {
                if (!is_array($layer)) { throw new \InvalidArgumentException('Invalid map layer.'); }
                $layer += ['title' => '', 'data' => '', 'color' => '', 'size' => 8, 'shape' => 'circle'];
                if (!is_numeric($layer['size']) || $layer['size'] < 1 || $layer['size'] > 200) { throw new \InvalidArgumentException('Marker size must be between 1 and 200.'); }
                if (!in_array($layer['shape'], \SimpleMappr\Constants\AcceptedMarkers::shapes(), true)) { throw new \InvalidArgumentException('The selected marker shape is not supported.'); }
                foreach (['title', 'data', 'color', 'shape'] as $field) {
                    if (!is_scalar($layer[$field])) { throw new \InvalidArgumentException('Invalid map layer value.'); }
                }
            }
            unset($layer);
            $document->{$key} = array_values($document->{$key});
        }
        foreach (['origin', 'origin_map'] as $field) {
            $value = $document->{$field};
            if ($value !== null && $value !== false && (!is_numeric($value) || !is_finite((float)$value) || abs($value) > 180)) {
                throw new \InvalidArgumentException('The projection origin must be a longitude between -180 and 180.');
            }
        }
        if ($document->pan && !in_array($document->pan, ['up', 'down', 'left', 'right'], true)) {
            throw new \InvalidArgumentException('The pan direction is not supported.');
        }
        foreach (['zoom_in', 'zoom_out', 'crop', 'download', 'watermark', 'rotation', 'border_thickness'] as $field) {
            if (!is_scalar($document->{$field}) && $document->{$field} !== null) {
                throw new \InvalidArgumentException('Invalid map control value.');
            }
        }
        foreach ($document->options as $option) {
            if (!is_scalar($option)) { throw new \InvalidArgumentException('Invalid export option.'); }
        }
        if ($document->bbox_rubberband) {
            $selection = is_string($document->bbox_rubberband) ? explode(',', $document->bbox_rubberband) : [];
            if (count($selection) !== 4) { throw new \InvalidArgumentException('Invalid map selection.'); }
            foreach ($selection as $index => $value) {
                $max = $index % 2 === 0 ? $document->width : $document->height;
                if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > $max) {
                    throw new \InvalidArgumentException('The selection must stay inside the map canvas.');
                }
            }
            if ($document->crop && (abs($selection[0] - $selection[2]) < 2 || abs($selection[1] - $selection[3]) < 2)) {
                throw new \InvalidArgumentException('The crop must be at least two pixels wide and high.');
            }
        }
        $bounds = is_string($document->bbox_map) ? explode(',', $document->bbox_map) : [];
        if (count($bounds) !== 4 || count(array_filter($bounds, static fn($value) => is_numeric($value) && is_finite((float)$value))) !== 4
            || (float)$bounds[0] >= (float)$bounds[2] || (float)$bounds[1] >= (float)$bounds[3]
        ) {
            throw new \InvalidArgumentException('Map bounds must contain four finite coordinates with positive width and height.');
        }
    }

}
