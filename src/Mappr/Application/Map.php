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

use SimpleMappr\SavedMapDocument;
use SimpleMappr\Utility;
use SimpleMappr\Constants\AcceptedOutputs;
use SimpleMappr\Controller\Kml;
use SimpleMappr\Mappr\Mappr;

/**
 * Map handler for SimpleMappr
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 */
class Map extends Mappr
{
    private array $_record;

    /**
     * File extension for the output
     *
     * @var string $_extension
     */
    private $_extension;

    /**
     * Constructor
     *
     * @param array  $record    Authorized saved-map document
     * @param string $extension File extension
     */
    public function __construct(array $record, $extension)
    {
        $this->_record = $record;
        $this->_extension = ($extension) ? $extension : "png";
        parent::__construct();
    }

    /**
     * Implement getRequest method
     *
     * @return object $this
     */
    public function getRequest()
    {
        $request = \SimpleMappr\Request::fromDocument(SavedMapDocument::renderingFields($this->_record['map']));
        if (!$request->border_thickness) { $request->border_thickness = 1.25; }
        if ($request->bbox_rubberband) { $request->crop = true; }
        if ($request->bbox_map === '0,0,0,0') { $request->bbox_map = '-180,-90,180,90'; }
        $storedWidth = $request->width;
        $storedHeight = $request->height;
        $request->width = (float)Utility::loadParam('width', $storedWidth);
        $request->height = (float)Utility::loadParam('height', isset($_GET['width']) ? $request->width * $storedHeight / $storedWidth : $storedHeight);
        if (array_key_exists('legend', $_GET)) { $request->options['legend'] = filter_var($_GET['legend'], FILTER_VALIDATE_BOOLEAN); }
        $request->options['legend'] ??= true;
        $request->watermark = true;
        $request->output = $this->_extension;
        return $request;
    }

    /**
     * Override the method in the parent class
     *
     * @return object $this
     */
    public function execute()
    {
        if (in_array($this->_extension, AcceptedOutputs::outputList())) {
            parent::execute();
        }
        return $this;
    }

    /**
     * Override the method in the parent class
     *
     * @return void
     */
    public function addGraticules()
    {
        if ($this->request->graticules) {
            $layer = new \layerObj($this->map_obj);
            $layer->name = 'grid';
            $layer->type = MS_LAYER_LINE;
            $layer->status = MS_ON;
            $layer->setProjection(parent::getProjection($this->default_projection));

            $class = new \classObj($layer);

            $label = new \labelObj();
            if (!empty($this->request->hide_gridlabel)) {
                $label->color->setRGB(255, 255, 255);
            } else {
                $label->encoding = "UTF-8";
                $label->font = "inter";
                $label->size = 10;
                $label->position = MS_UC;
                $label->color->setRGB(30, 30, 30);
            }
            if (empty($this->request->hide_gridlabel)) { $class->addLabel($label); }

            $style = new \styleObj($class);
            $style->color->setRGB(200, 200, 200);

            $minx = $this->map_obj->extent->minx;
            $maxx = $this->map_obj->extent->maxx;

            $maxarcs = abs($maxx-$minx)/24;

            if ($maxarcs >= 5) {
                $labelformat = "DD";
            }
            if ($maxarcs < 5) {
                $labelformat = "DDMM";
            }
            if ($maxarcs <= 1) {
                $labelformat = "DDMMSS";
            }

            $maxinterval = ($this->request->gridspace) ? $this->request->gridspace : $maxarcs;
            $maxsubdivide = 2;

            $this->configureGraticule($layer, $labelformat, $maxarcs, $maxinterval, $maxsubdivide);
        }
    }

    /**
     * Override method in the parent class
     *
     * @return void
     */
    public function addScalebar()
    {
        if ($this->_extension == 'png' || $this->_extension == 'jpg') {
            $this->map_obj->scalebar->style = 0;
            $this->map_obj->scalebar->intervals = 3;
            $this->map_obj->scalebar->height = 8;
            $this->map_obj->scalebar->width = 200;
            $this->map_obj->scalebar->color->setRGB(30, 30, 30);
            $this->map_obj->scalebar->backgroundcolor->setRGB(255, 255, 255);
            $this->map_obj->scalebar->outlinecolor->setRGB(0, 0, 0);
            $this->map_obj->scalebar->units = MS_KILOMETERS;
            $this->map_obj->scalebar->label->encoding = "UTF-8";
            $this->map_obj->scalebar->label->font = "inter";
            $this->map_obj->scalebar->label->size = 8;
            $this->map_obj->scalebar->label->color->setRGB(0, 0, 0);
            $this->map_obj->scalebar->status = MS_EMBED;
            $this->map_obj->scalebar->position = MS_LR;
            $this->map_obj->drawScalebar();
        }
    }

    /**
     * Override method in the parent class
     *
     * @return void
     */
    public function addLegend()
    {
        if ($this->request->options['legend']) {
            if ($this->request->output == 'png' || $this->request->output == 'jpg') {
                $this->map_obj->legend->keysizex = 20;
                $this->map_obj->legend->keysizey = 20;
                $this->map_obj->legend->keyspacingx = 5;
                $this->map_obj->legend->keyspacingy = 5;
                $this->map_obj->legend->postlabelcache = 1;
                $this->map_obj->legend->label->font = "inter";
                $this->map_obj->legend->label->encoding = "UTF-8";
                $this->map_obj->legend->label->position = 1;
                $this->map_obj->legend->label->size = 10;
                $this->map_obj->legend->label->color->setRGB(0, 0, 0);
                $this->map_obj->legend->status = MS_EMBED;
                $this->map_obj->legend->position = MS_UR;
                $this->map_obj->drawLegend();
            }
        }
    }

    /**
     * Implement createOutput method
     *
     * @return void
     */
    public function createOutput()
    {
        switch ($this->_extension) {
        case 'jpg':
        case 'png':
        case 'svg':
            echo $this->image->getBytes();
            break;

        case 'json':
            return json_encode(\SimpleMappr\GeometryExport::features(SavedMapDocument::renderingFields($this->_record['map'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        case 'kml':
            return (new Kml)->create(["file_name" => $this->_record['title'], "coords" => $this->request->coords, "wkt" => $this->request->wkt]);
            break;

        default:
            $this->_setNotFound();
        }
    }

    /**
     * Set default not found image and 404
     *
     * @return void
     */
    private function _setNotFound()
    {
        http_response_code(404);
        switch ($this->_extension) {
        case 'jpg':
        case 'png':
            header("Content-Type: image/png");
            $im = imagecreatefrompng($_SERVER["DOCUMENT_ROOT"] . "/public/images/404.png");
            imagepng($im);
            imagedestroy($im);
            break;

        case 'json':
            header("Content-Type: application/json");
            echo json_encode(["error" => "not found"]);
            break;

        default:
            readfile($_SERVER["DOCUMENT_ROOT"].'/error/404.html');
        }
    }

}
