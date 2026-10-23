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
namespace SimpleMappr\Mappr\Application;

use \ForceUTF8\Encoding;
use SimpleMappr\Utility;
use SimpleMappr\Mappr\Mappr;

/**
 * Query handler for SimpleMappr
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 */
class Query extends Mappr
{
    /**
     * Regions on shapefile that match a query
     *
     * @var array $data
     */
    public $data = [];

    /**
     * Implement getRequest method
     *
     * @return object $this
     */
    public function getRequest()
    {
        $attr = new \stdClass();
        $attr->download         = false;
        $attr->options          = [];
        $attr->border_thickness = 1.25;
        $attr->width            = (float)Utility::loadParam('width', 900);
        $attr->height           = (float)Utility::loadParam('height', $attr->width/2);
        $attr->image_size       = [$attr->width, $attr->height];
        $attr->output           = Utility::loadParam('output', 'png');
        $attr->projection       = Utility::loadParam('projection', 'epsg:4326');
        $attr->projection_map   = Utility::loadParam('projection_map', 'epsg:4326');
        $attr->origin           = Utility::loadParam('origin', null);
        $attr->bbox_map         = Utility::loadParam('bbox', '-180,-90,180,90');
        $attr->layers           = Utility::loadParam('layers', []);
        $attr->graticules       = Utility::loadParam('graticules', false);
        $attr->bbox_query       = Utility::loadParam('bbox_query', '0,0,0,0');
        $attr->queryLayer       = Utility::loadParam('qlayer', 'countries');

        return $attr;
    }

    /**
     * Query a layer
     *
     * @return object $this
     */
    public function queryLayer()
    {
        $bbox_query = is_string($this->request->bbox_query) ? explode(',', $this->request->bbox_query) : [];
        if (count($bbox_query) !== 4) { throw new \InvalidArgumentException('Invalid region selection.'); }
        foreach ($bbox_query as $index => $value) {
            $maximum = $index % 2 === 0 ? $this->request->width : $this->request->height;
            if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > $maximum) {
                throw new \InvalidArgumentException('The region selection must stay inside the map canvas.');
            }
        }
        $pointSelection = (float)$bbox_query[0] === (float)$bbox_query[2] && (float)$bbox_query[1] === (float)$bbox_query[3];
        if (!$pointSelection && ($bbox_query[0] >= $bbox_query[2] || $bbox_query[1] >= $bbox_query[3])) {
            throw new \InvalidArgumentException('The region selection must be a point or a box with positive width and height.');
        }

        if (!array_key_exists($this->request->queryLayer, $this->shapes)) {
            $this->request->queryLayer = 'countries';
        }

        //lower-left coordinate
        $ll_point = new \stdClass();
        $ll_point->x = $bbox_query[0];
        $ll_point->y = $bbox_query[3];
        $ll_coord = $this->pix2Geo($ll_point);

        //upper-right coordinate
        $ur_point = new \stdClass();
        $ur_point->x = $bbox_query[2];
        $ur_point->y = $bbox_query[1];
        $ur_coord = $this->pix2Geo($ur_point);

        $layer = new \layerObj($this->map_obj);
        $layer->name = "stateprovinces_polygon_query";
        $layer->data = $this->shapes[$this->request->queryLayer]['path'];
        $layer->type = $this->shapes[$this->request->queryLayer]['type'];
        $layer->template = "template.html";
        $layer->setProjection(parent::getProjection($this->default_projection));

        if ($pointSelection) {
            $layer->tolerance = 0;
            $point = new \pointObj();
            $point->setXY($ll_coord->x, $ll_coord->y);
            $return = $layer->queryByPoint($this->map_obj, $point, MS_SINGLE, 0);
        } else {
            $rect = new \rectObj($ll_coord->x, $ll_coord->y, $ur_coord->x, $ur_coord->y);
            $return = $layer->queryByRect($this->map_obj, $rect);
        }
        if ($return == MS_SUCCESS) {
            if ($layer->getNumResults() > 0) {
                $layer->open();
                $items = [];
                $count = $layer->getNumResults();
                for ($i = 0; $i < $count; $i++) {
                    $shape = $layer->getShape($layer->getResult($i));
                    $values = [];
                    for ($item = 0; $item < $layer->numitems; $item++) {
                        $values[strtolower($layer->getItem($item))] = $shape->getValue($item);
                    }
                    if ($this->request->queryLayer == 'stateprovinces_polygon') {
                        $hasc = explode(".", $values['code_hasc']);
                        if (isset($values['adm0_a3']) && isset($hasc[1])) {
                            $items[$values['adm0_a3']][$hasc[1]] = [];
                        }
                    } else {
                        //DigitalEarth ne_10m_admin_0_map_units is inconsistent
                        $this->data[] = Encoding::fixUTF8($values['geounit']);
                    }
                }
                if ($this->request->queryLayer == 'stateprovinces_polygon') {
                    foreach ($items as $key => $value) {
                        $this->data[] = $key . "[" . implode(" ", array_keys($value)) . "]";
                    }
                }
                $layer->close();
            }
        }

        return $this;
    }

    /**
     * Implement createOutput method
     *
     * @return void
     */
    public function createOutput()
    {
    }
}
