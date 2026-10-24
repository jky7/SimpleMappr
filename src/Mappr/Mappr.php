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
namespace SimpleMappr\Mappr;

use \Symfony\Component\Yaml\Yaml;
use SimpleMappr\Constants\AcceptedMarkers;
use SimpleMappr\Constants\AcceptedOutputs;
use SimpleMappr\Constants\AcceptedProjections;
use SimpleMappr\Utility;
use SimpleMappr\DatasetCredits;

/**
 * Main Mappr class for SimpleMappr
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 */
abstract class Mappr
{
    /**
     * Receive request, required for all extended classes
     *
     * @return void
     */
    abstract public function getRequest();

    /**
     * Create output, required for all extended classes
     *
     * @return void
     */
    abstract public function createOutput();

    /**
     * Path to the shapefile config
     *
     * @var string $shapefile_config
     */
    public static $shapefile_config = ROOT.'/config/shapefiles.minimal.yml';

    /**
     * Path to the font file
     *
     * @var string $font_file
     */
    protected $font_file = ROOT.'/mapserver/fonts/fonts.list';

    /**
     * File system temp path to store files produced
     *
     * @var string $tmp_path
     */
    protected $tmp_path = ROOT.'/public/tmp/';

    /**
     * URL temp path to retrieve files produced
     *
     * @var string $tmp_url
     */
    protected $tmp_url = MAPPR_MAPS_URL;

    /**
     * The base map object
     *
     * @var object $map_obj
     */
    protected $map_obj;

    private ?\SimpleMappr\DetailedRelief $detailedRelief = null;
    private ?\SimpleMappr\WrappedRasterSource $wrappedRasterSource = null;
    protected bool $detailedReliefUsed = false;
    protected ?int $detailedReliefZoom = null;

    /**
     * Default extent when map first loaded
     *
     * @var array $max_extent
     */
    protected $max_extent = [-180,-90,180,90];

    /**
     * Default projection when map first loaded
     *
     * @var string $default_projection
     */
    protected $default_projection = 'epsg:4326';

    /**
     * Image object produced from MapScript
     *
     * @var object $image
     */
    protected $image;

    /**
     * Scale object for scalebar produced from MapScript
     *
     * @var object $scale
     */
    protected $scale;

    /**
     * Legend object produced from MapScript
     *
     * @var object $legend
     */
    protected $legend;

    /**
     * Shapes and their mapfile configurations
     *
     * @var array $shapes
     */
    protected $shapes = [];

    /**
     * Post-draw padding for longitude extent used as a correction factor on front-end
     *
     * @var int $ox_pad
     */
    protected $ox_pad = 0;

    /**
     * Post-draw padding for latitude extent used as a correction factor on front-end
     *
     * @var int $oy_pad
     */
    protected $oy_pad = 0;

    /**
     * URL for legend image if produced
     *
     * @var string $legend_url
     */
    protected $legend_url;

    /**
     * URL for scalebar image if produced
     *
     * @var string $scalebar_url
     */
    protected $scalebar_url;

    /**
     * Holding bin for coordinates outside extent of Earth
     *
     * @var array $bad_points
     */
    protected $bad_points = [];

    /**
     * Holding bin for WKT that fail to render
     *
     * @var array $bad_drawings
     */
    protected $bad_drawings = [];

    /**
     * Placeholder for presence of anything that might need a legend
     *
     * @var bool $_legend_required
     */
    private $_legend_required = false;

    /**
     * Get projection
     *
     * @param string $projection Projection expressed as epsg code
     *
     * @return string PROJ representation of projection
     */
    public static function getProjection($projection)
    {
        if (!array_key_exists($projection, AcceptedProjections::$projections)) {
            $projection = 'epsg:4326';
        }
        return AcceptedProjections::$projections[$projection]['proj'];
    }

    /**
     * Get shapefile config
     *
     * @return array shapefile config
     */
    public static function getShapefileConfig()
    {
        $file = self::getShapefileConfigPath();
        $config_file = file_get_contents($file);
        return self::_tokenizeShapefileConfig(Yaml::parse($config_file));
    }

    public static function getShapefileConfigPath()
    {
        return is_file(ROOT.'/config/shapefiles.yml') ? ROOT.'/config/shapefiles.yml' : \SimpleMappr\AssetProfiles::configuration();
    }

    /**
     * Constructor
     */
    public $request;
    public $image_size;

    public function __construct()
    {
        if (!extension_loaded('MapScript')) {
            throw new \RuntimeException('The map rendering extension is unavailable.');
        }
        $this->request = \SimpleMappr\Request::fromDocument((array)$this->getRequest());
        \SimpleMappr\Request::validateMapDocument($this->request);
        $this->map_obj = msLoadMapFromString("MAP MAXSIZE 5000 END", ROOT, null);
        $this->image_size = [$this->request->width, $this->request->height];
    }

    /**
     * Global call method to coordinate setters, getters.
     *
     * @param string $name      Name of the object.
     * @param array  $arguments Value for the object.
     *
     * @return object $this
     */
    public function __call($name, $arguments)
    {
        $property_prefix = substr($name, 0, 4);
        $property = substr($name, 4);
        if ($property_prefix == 'set_') {
            $this->{$property} = $arguments[0];
        } elseif ($property_prefix == 'add_') { // add to an array property
            array_push($this->{$property}, $arguments[0]);
        } elseif ($property_prefix == 'get_') { //get a property
            return $this->{$property};
        }
        return $this;
    }

    public function prepareServiceMap(): void
    {
        $this->_loadOutputFormats();
        $this->_loadProjection();
        $this->_loadShapes();
        $this->_loadSymbols();
        $this->_setWebConfig();
        $this->_setUnits();
        $this->_setMapColor();
        $this->_setOutputFormat();
        $this->_setMapExtent();
        $this->_setMapSize();
        $this->_addLayers();
    }

    public function execute()
    {
        $this->_loadOutputFormats();
        $this->_loadProjection();
        $this->_loadShapes();
        $this->_loadSymbols();
        $this->_setWebConfig();
        $this->_setResolution();
        $this->_setUnits();
        $this->_setMapColor();
        $this->_setOutputFormat();
        $this->_setMapExtent();
        $this->_setMapSize();
        $this->_setZoom();
        $this->_setPan();
        $this->_setRotation();
        $this->_setCrop();
        $this->_addLayers();
        $this->addRegions();
        $this->addWKT();
        $this->addGraticules();
        $this->addCoordinates();
        $this->_addWatermark();
        $this->_prepareOutput();

        return $this;
    }

    /**
     * Convert image coordinates to map coordinates
     *
     * @param obj $point (x,y) coordinates in pixels
     *
     * @return object $newPoint reprojected point in map coordinates
     */
    public function pix2Geo($point)
    {
        $newPoint = new \stdClass();
        $deltaX = abs($this->map_obj->extent->maxx - $this->map_obj->extent->minx);
        $deltaY = abs($this->map_obj->extent->maxy - $this->map_obj->extent->miny);

        $newPoint->x = $this->map_obj->extent->minx + ($point->x*$deltaX)/(float)$this->image_size[0];
        $newPoint->y = $this->map_obj->extent->miny + (((float)$this->image_size[1] - $point->y)*$deltaY)/(float)$this->image_size[1];
        return $newPoint;
    }

    /**
     * Get all the shapes
     *
     * @return object
     */
    public function getShapes()
    {
        return $this->shapes;
    }

    /**
     * Add all coordinates to the map
     *
     * @return void
     */
    public function addCoordinates()
    {
        $this->bad_points = [];
        if (isset($this->request->coords) && $this->request->coords) {
            //do this in reverse order because the legend will otherwise be presented in reverse order
            $count = count($this->request->coords)-1;
            for ($j=$count; $j>=0; $j--) {
                $title = "";
                $size = 8;
                $shape = "circle";
                $shadow = false;
                $color = [];
                $offset = 2;

                if ($this->request->coords[$j]['title']) {
                    $title = $this->request->coords[$j]['title'];
                }
                if ($this->request->coords[$j]['size']) {
                    $size = $this->request->coords[$j]['size'];
                }
                if ($this->_isResize() && $this->request->download_factor > 1) {
                    $size = $this->request->download_factor*$size;
                    $offset = $this->request->download_factor;
                }
                if (isset($this->request->coords[$j]['shape'])) {
                    $shape = $this->request->coords[$j]['shape'];
                }
                if (!empty($this->request->coords[$j]['shadow']) && filter_var($this->request->coords[$j]['shadow'], FILTER_VALIDATE_BOOLEAN)) {
                    $shadow = true;
                }
                if ($this->request->coords[$j]['color']) {
                    $color = explode(" ", $this->request->coords[$j]['color']);
                    if (count($color) != 3) {
                        $color = [];
                    }
                }

                $data = trim($this->request->coords[$j]['data']);

                if ($data) {
                    $this->_legend_required = true;
                    $layer = new \layerObj($this->map_obj);
                    $layer->name = "layer_".$j;
                    $layer->status = MS_ON;
                    $layer->type = MS_LAYER_POINT;
                    $layer->tolerance = 5;
                    $layer->toleranceunits = 6;
                    $layer->setProjection(self::getProjection($this->default_projection));

                    $class = new \classObj($layer);
                    if ($title != "") {
                        $class->name = trim(stripslashes($title));
                    }

                    if ($shadow) {
                        $bstyle = new \styleObj($class);
                        $bstyle->setSymbolByName($this->map_obj, $shape);
                        $bstyle->size = $size;
                        $bstyle->offsetx = $offset;
                        $bstyle->offsety = $offset;
                        $bstyle->color->setRGB(180, 180, 180);
                    }

                    $style = new \styleObj($class);
                    $style->setSymbolByName($this->map_obj, $shape);
                    $style->size = $size;

                    if (!empty($color)) {
                        if (substr($shape, 0, 4) == 'open') {
                            $style->color->setRGB($color[0], $color[1], $color[2]);
                        } else {
                            $style->color->setRGB($color[0], $color[1], $color[2]);
                            $style->outlinecolor->setRGB(85, 85, 85);
                        }
                    } else {
                        $style->outlinecolor->setRGB(0, 0, 0);
                    }

                    if (!empty($this->request->outlinecolor) && substr($shape, 0, 4) !== 'open') {
                        $outline = preg_split('/\s+/', trim($this->request->outlinecolor));
                        if (count($outline) === 3 && count(array_filter($outline, 'is_numeric')) === 3) { $style->outlinecolor->setRGB(...array_map('intval', $outline)); }
                    }

                    $style->width = $this->_determineWidth();

                    $new_shape = new \shapeObj(MS_SHAPE_POINT);
                    $new_line = new \lineObj();

                    $rows = explode("\n", Utility::removeEmptyLines($data));
                    $points = [];

                    foreach ($rows as $row) {
                        $coord_array = Utility::makeCoordinates($row);
                        $coord = new \stdClass();
                        $coord->x = is_numeric($coord_array[1]) ? Utility::cleanCoord($coord_array[1]) : null;
                        $coord->y = is_numeric($coord_array[0]) ? Utility::cleanCoord($coord_array[0]) : null;
                        if (Utility::onEarth($coord)) {
                            if (!array_key_exists($coord->x.','.$coord->y, $points)) {
                                $new_point = new \pointObj();
                                $new_point->setXY($coord->x, $coord->y);
                                $new_line->add($new_point);
                                $points[$coord->x.','.$coord->y] = [];
                            }
                        } else {
                            $this->bad_points[] = Utility::checkPlain(stripslashes($this->request->coords[$j]['title'] . ' : ' . $row));
                        }
                        unset($coord);
                    }

                    unset($points);
                    $new_shape->add($new_line);
                    $layer->addFeature($new_shape);
                }
            }
        }
    }

    /**
     * Add WKT layers to the map
     *
     * @return void
     */
    public function addWKT()
    {
        $this->bad_drawings = [];
        if (isset($this->request->wkt) && $this->request->wkt) {
            $count = count($this->request->wkt)-1;
            for ($j=$count; $j>=0; $j--) {
                $color = [];
                $border = false;
                $hatched = false;
                $title = "";
                if ($this->request->wkt[$j]['title']) {
                    $title = $this->request->wkt[$j]['title'];
                }
                if (!empty($this->request->wkt[$j]['border']) && filter_var($this->request->wkt[$j]['border'], FILTER_VALIDATE_BOOLEAN)) {
                    $border = true;
                }
                if (!empty($this->request->wkt[$j]['hatch']) && filter_var($this->request->wkt[$j]['hatch'], FILTER_VALIDATE_BOOLEAN)) {
                    $hatched = true;
                }
                if ($this->request->wkt[$j]['color']) {
                    $color = explode(" ", $this->request->wkt[$j]['color']);
                    if (count($color) != 3) {
                        $color = [];
                    }
                }

                $data = trim($this->request->wkt[$j]['data']);

                if ($data) {
                    $this->_legend_required = true;
                    $rows = explode("\n", Utility::removeEmptyLines($data));
                    foreach ($rows as $key => $row) {
                        if (stripos($row, "POINT") !== false) {
                            $type = MS_LAYER_POINT;
                        } elseif (stripos($row, "LINE") !== false) {
                            $type = MS_LAYER_LINE;
                        } else {
                            $type = MS_LAYER_POLYGON;
                        }
                        $layer = new \layerObj($this->map_obj);
                        $layer->name = "wkt_layer_".$j.$key;
                        $layer->status = MS_ON;
                        $layer->type = $type;
                        $layer->template = "template.html";
                        $layer->setProjection(self::getProjection($this->default_projection));

                        $class = new \classObj($layer);
                        $class->name = trim(stripslashes($title));
                        $style = new \styleObj($class);
                        if ($type == MS_LAYER_POINT) {
                            $style->setSymbolByName($this->map_obj, 'circle');
                            $style->size = 8;
                        }
                        if ($type == MS_LAYER_POLYGON && $border) {
                            $style->outlinecolor->setRGB(0, 0, 0);
                            $style->width = $this->_determineWidth();
                        }
                        if (!empty($color)) {
                            $style->color->setRGB($color[0], $color[1], $color[2]);
                        }
                        $style->updateFromString('STYLE OPACITY 75 END');

                        if ($hatched && !empty($color)) {
                            $style = new \styleObj($class);
                            $style->setSymbolByName($this->map_obj, "hatch");
                            $style->angle = 45;
                            $style->size = 5;
                            $style->color->setRGB(max(0, $color[0]-100), max(0, $color[1]-100), max(0, $color[2]-100));
                        }

                        try {
                            $shape = \shapeObj::fromWKT($row);
                            $layer->addFeature($shape);
                        } catch (\Exception $e) {
                            $this->bad_drawings[] = Utility::checkPlain(stripslashes($title.' : '.$row));
                        }
                    }
                }
            }
        }
    }

    /**
     * Add shaded regions to the map
     *
     * @return void
     */
    public function addRegions()
    {
        if (isset($this->request->regions) && $this->request->regions) {
            $count = count($this->request->regions)-1;
            for ($j=$count; $j>=0; $j--) {
                //clear out previous loop's selection
                $color = [];
                $border = false;
                $hatched = false;
                $title = "";
                if ($this->request->regions[$j]['title']) {
                    $title = $this->request->regions[$j]['title'];
                }
                if ($this->request->regions[$j]['color']) {
                    $color = explode(" ", $this->request->regions[$j]['color']);
                    if (count($color) != 3) {
                        $color = [];
                    }
                }
                if (!empty($this->request->regions[$j]['hatch']) && filter_var($this->request->regions[$j]['hatch'], FILTER_VALIDATE_BOOLEAN)) {
                    $hatched = true;
                }
                if (!empty($this->request->regions[$j]['border']) && filter_var($this->request->regions[$j]['border'], FILTER_VALIDATE_BOOLEAN)) {
                    $border = true;
                }
                $data = trim($this->request->regions[$j]['data']);

                if ($data) {
                    $this->_legend_required = true;
                    $baselayer = true;
                    //grab the textarea for regions & split
                    $rows = explode("\n", Utility::removeEmptyLines($data));
                    $qry = [];
                    foreach ($rows as $row) {
                        $regions = preg_split("/[,;]+/", $row); //split by a comma, semicolon
                        foreach ($regions as $region) {
                            if ($region) {
                                $pos = strpos($region, '[');
                                if ($pos !== false) {
                                    $baselayer = false;
                                    $split = explode("[", str_replace("]", "", trim(strtoupper($region))));
                                    $states = preg_split("/[\s|]+/", $split[1]);
                                    $statekey = [];
                                    foreach ($states as $state) {
                                        $statekey[] = "'[code_hasc]' ~* '\.".$state."$'";
                                    }
                                    $qry['stateprovince'][] = "'[adm0_a3]' = '".trim($split[0])."' && (".implode(" || ", $statekey).")";
                                    $qry['country'][] = "'[iso_a3]' = '".trim($split[0])."'";
                                } else {
                                    $region = addslashes(trim($region));
                                    $qry['stateprovince'][] = "'[name]' ~* '".$region."$'";
                                    $qry['country'][] = "'[NAME]' ~* '".$region."$' || '[NAME_LONG]' ~* '".$region."$' || '[GEOUNIT]' ~* '".$region."$' || '[FORMAL_EN]' ~* '".$region."$'";
                                }
                            }
                        }
                    }

                    $layer = new \layerObj($this->map_obj);
                    $layer->name = "query_layer_".$j;

                    if ($baselayer) {
                        $layer->data = $this->shapes['countries']['path'];
                        $layer->type = MS_LAYER_POLYGON;
                    } else {
                        $layer->data = $this->shapes['stateprovinces_polygon']['path'];
                        $layer->type = $this->shapes['stateprovinces_polygon']['type'];
                    }

                    $layer->template = "template.html";
                    $layer->setProjection(self::getProjection($this->default_projection));

                    $query = ($baselayer) ? $qry['country'] : $qry['stateprovince'];

                    $layer->setFilter("(".implode(" || ", $query).")");

                    $class = new \classObj($layer);
                    $class->name = trim(stripslashes($title));

                    $style = new \styleObj($class);
                    if (!empty($color)) {
                        $style->color->setRGB($color[0], $color[1], $color[2]);
                    }
                    if ($border) {
                        $style->outlinecolor->setRGB(0, 0, 0);
                        $style->width = $this->_determineWidth();
                    }
                    $style->updateFromString('STYLE OPACITY 75 END');

                    if ($hatched && !empty($color)) {
                        $style = new \styleObj($class);
                        $style->setSymbolByName($this->map_obj, "hatch");
                        $style->angle = 45;
                        $style->size = 5;
                        $style->color->setRGB(max(0, $color[0]-100), max(0, $color[1]-100), max(0, $color[2]-100));
                    }

                    $layer->status = MS_ON;
                }
            }
        }
    }

    /**
     * Add graticules (or grid lines) to map
     *
     * @return void
     */
    public function addGraticules()
    {
        if (isset($this->request->graticules) && $this->request->graticules) {
            $layer = new \layerObj($this->map_obj);
            $layer->name = 'grid';
            $layer->type = MS_LAYER_LINE;
            $layer->status = MS_ON;
            $layer->setProjection(self::getProjection($this->default_projection));

            $class = new \classObj($layer);

            $label = new \labelObj();
            if (isset($this->request->hide_gridlabel) && $this->request->hide_gridlabel) {
                $label->color->setRGB(255, 255, 255);
            } else {
                $label->font = "arial";
                $label->encoding = "UTF-8";
                $size = 10;
                if ($this->_isResize() && $this->request->download_factor > 1) {
                    $size = $this->request->download_factor*9;
                }
                $label->size = $size;
                $label->position = MS_CC;
                $label->color->setRGB(30, 30, 30);
            }
            if (empty($this->request->hide_gridlabel)) { $class->addLabel($label); }

            $style = new \styleObj($class);
            $style->color->setRGB(200, 200, 200);

            $minx = $this->map_obj->extent->minx;
            $maxx = $this->map_obj->extent->maxx;

            //project the extent back to default such that we can work with proper tick marks
            if ($this->request->projection != $this->default_projection
                && $this->request->projection == $this->request->projection_map
            ) {
                $origProjObj = new \projectionObj($this->map_obj->getProjection());
                $newProjObj = new \projectionObj(self::getProjection($this->default_projection));

                $poPoint1 = new \pointObj();
                $poPoint1->setXY($this->map_obj->extent->minx, $this->map_obj->extent->miny);

                $poPoint2 = new \pointObj();
                $poPoint2->setXY($this->map_obj->extent->maxx, $this->map_obj->extent->maxy);

                $poPoint1->project($origProjObj, $newProjObj);
                $poPoint2->project($origProjObj, $newProjObj);

                $minx = $poPoint1->x;
                $maxx = $poPoint2->x;
            }

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

            $labelformat = ($this->request->gridspace) ? "DD" : $labelformat;
            $maxinterval = ($this->request->gridspace) ? $this->request->gridspace : $maxarcs;
            $maxsubdivide = 2;

            $this->configureGraticule($layer, $labelformat, $maxarcs, $maxinterval, $maxsubdivide);
        }
    }

    /**
     * Create the legend file
     *
     * @return void
     */
    public function addLegend()
    {
        if ($this->_legend_required) {
            $keysize = 20;
            $keyspacing = 5;
            $size = 10;
            if ($this->_isResize()
                && $this->request->download_factor > 1
            ) {
                $keysize = $this->request->download_factor*15;
                $keyspacing = $this->request->download_factor*3;
                $size = $this->request->download_factor*8;
            }
            $this->map_obj->legend->keysizex = $keysize;
            $this->map_obj->legend->keysizey = $keysize;
            $this->map_obj->legend->keyspacingx = $keyspacing;
            $this->map_obj->legend->keyspacingy = $keyspacing;
            $this->map_obj->legend->postlabelcache = 1;
            $this->map_obj->legend->label->font = "arial";
            $this->map_obj->legend->label->encoding = "UTF-8";
            $this->map_obj->legend->label->position = 1;
            $this->map_obj->legend->label->size = $size;
            $this->map_obj->legend->label->color->setRGB(0, 0, 0);

            //svg format cannot do legends in MapServer
            if ($this->request->download
                && $this->request->options['legend']
                && $this->request->output != 'svg'
            ) {
                $this->map_obj->legend->status = MS_EMBED;
                $this->map_obj->legend->position = MS_UR;
                $this->map_obj->drawLegend();
            }
            if (!$this->request->download) {
                $this->map_obj->legend->status = MS_DEFAULT;
                $this->legend = $this->map_obj->drawLegend();
                $this->legend_url = \SimpleMappr\TemporaryImage::write($this->legend, $this->map_obj)['url'];
            }
        }
    }

    /**
     * Create a scalebar image
     *
     * @return void
     */
    public function addScalebar()
    {
        $height = 8;
        $width = 200;
        $size = 8;
        if ($this->_isResize() && $this->request->download_factor > 1) {
            $height = $this->request->download_factor*4;
            $width = $this->request->download_factor*100;
            $size = $this->request->download_factor*5;
        }
        $this->map_obj->scalebar->style = 0;
        $this->map_obj->scalebar->intervals = 3;
        $this->map_obj->scalebar->height = $height;
        $this->map_obj->scalebar->width = $width;
        $this->map_obj->scalebar->color->setRGB(30, 30, 30);
        $this->map_obj->scalebar->outlinecolor->setRGB(0, 0, 0);
        $this->map_obj->scalebar->units = MS_KILOMETERS;
        $this->map_obj->scalebar->label->font = "arial";
        $this->map_obj->scalebar->label->encoding = "UTF-8";
        $this->map_obj->scalebar->label->size = $size;
        $this->map_obj->scalebar->label->color->setRGB(0, 0, 0);

        //svg format cannot do scalebar in MapServer
        if ($this->request->download
            && $this->request->options['scalebar']
            && $this->request->output != 'svg'
        ) {
            $this->map_obj->scalebar->status = MS_EMBED;
            $this->map_obj->scalebar->position = MS_LR;
            $this->map_obj->drawScalebar();
        }
        if (!$this->request->download) {
            $this->map_obj->scalebar->status = MS_DEFAULT;
            $this->scale = $this->map_obj->drawScalebar();
            $this->scalebar_url = \SimpleMappr\TemporaryImage::write($this->scale, $this->map_obj)['url'];
        }
    }

    /**
     * Get all the coordinates that fall outside Earth's geographic extent in dd
     *
     * @return string
     */
    public function getBadPoints()
    {
        return implode('<br />', $this->bad_points);
    }

    /**
     * Get all the coordinates that fall outside Earth's geographic extent in dd
     *
     * @return string
     */
    public function getBadDrawings()
    {
        return implode('<br />', $this->bad_drawings);
    }

    /**
     * Load the accepted output formats from the AcceptedOutputs trait
     *
     * @return void
     */
    private function _loadOutputFormats()
    {
        foreach (AcceptedOutputs::$outputs as $output) {
            $format = new \outputFormatObj($output["driver"], $output["name"]);
            foreach ($output as $key => $value) {
                if ($key != "formatoptions") {
                    $format->{$key} = $value;
                } else {
                    foreach ($value as $options) {
                        $option = explode("=", $options);
                        $format->setOption($option[0], $option[1]);
                    }
                }
            }
            $this->map_obj->appendOutputFormat($format);
        }
    }

    /**
     * Set the projection
     *
     * @return void
     */
    private function _loadProjection()
    {
        $current = $this->request->projection === $this->request->projection_map ? $this->request->projection_map : $this->default_projection;
        $projection = $this->_setOrigin($current, $this->request->origin_map);
        $this->map_obj->setProjection($projection);
    }

    /**
     * Load-up all the settings for potential shapes
     *
     * @return void
     */
    private function _loadShapes()
    {
        $this->shapes = self::getShapefileConfig();
    }

    /**
     * Tokenize shapefile.yml config
     *
     * @param array $config Array of configuration elements
     *
     * @return array of config
     */
    private static function _tokenizeShapefileConfig($config)
    {
        $config = $config['environments'][ENVIRONMENT] ?? $config['environments']['development'];
        $config = array_merge($config['layers'], $config['labels']);
        array_walk_recursive($config, static function (&$value) {
            if (is_string($value)) {
                $value = preg_replace_callback('/%%([A-Z_]+)%%/', static fn($match) => (string)constant($match[1]), $value);
            }
        });
        return $config;
    }

    /**
     * Load-up all the symbols
     *
     * @return void
     */
    private function _loadSymbols()
    {
        // Add hatch symbol
        $symbol = new \symbolObj("hatch");
        $symbol->type = MS_SYMBOL_HATCH;
        $symbol->filled = false;
        $this->map_obj->symbolset->appendSymbol($symbol);

        foreach (AcceptedMarkers::$shapes as $type => $style) {
            $fill = MS_FALSE;
            if ($type == 'closed') {
                $fill = MS_TRUE;
            }
            foreach ($style as $name => $settings) {
                $type = MS_SYMBOL_VECTOR;
                if (strpos($name, 'circle') !== false) {
                    $type = MS_SYMBOL_ELLIPSE;
                }
                $vertices = AcceptedMarkers::vertices($settings['style']);
                $this->_createSymbol($name, $type, $fill, $vertices);
            }
        }
    }

    /**
     * Create a symbol
     *
     * @param string $name     The name of the symbol
     * @param string $type     The type of the symbol
     * @param string $fill     MS_TRUE or MS_FALSE
     * @param array  $vertices The vertices
     *
     * @return void
     */
    private function _createSymbol($name, $type, $fill, $vertices)
    {
        $symbol = new \symbolObj($name);
        $symbol->type = $type;
        $symbol->filled = $fill;
        $this->map_obj->symbolset->appendSymbol($symbol);
        $points = new \lineObj();
        foreach (array_chunk($vertices, 2) as [$x, $y]) {
            $points->add(new \pointObj($x, $y));
        }
        $symbol->setPoints($points);
    }

    /**
     * Set config for web object, storage of tmp files
     *
     * @return void
     */
    private function _setWebConfig()
    {
        $this->map_obj->name = "simplemappr";
        $this->map_obj->setFontSet($this->font_file);
        $this->map_obj->web->template = "template.html";
        $this->map_obj->web->imagepath = $this->tmp_path;
        $this->map_obj->web->imageurl = $this->tmp_url;
    }

    /**
     * Set resolution
     *
     * @return void
     */
    private function _setResolution()
    {
        if ($this->request->output == 'tif') {
            $this->map_obj->defresolution = 300;
            $this->map_obj->resolution = 300;
        }
    }

    /**
     * Set units
     *
     * @return void
     */
    private function _setUnits()
    {
        $units = MS_METERS;
        if (isset($this->request->projection)
            && $this->request->projection == $this->default_projection
        ) {
            $units = MS_DD;
        }
        $this->map_obj->units = strpos($this->map_obj->getProjection(), '+proj=longlat') !== false ? MS_DD : MS_METERS;
    }

    /**
     * Set map color
     *
     * @return void
     */
    private function _setMapColor()
    {
        $this->map_obj->imagecolor->setRGB(255, 255, 255);
    }

    /**
     * Set output format
     *
     * @return void
     */
    private function _setOutputFormat()
    {
        $this->map_obj->selectOutputFormat('png');
        if (isset($this->request->output) && $this->request->output) {
            $output = $this->request->output;
            if ($this->request->output == 'pptx'
                || $this->request->output == 'docx'
                || !in_array($output, AcceptedOutputs::outputList())
            ) {
                $output = 'png';
            }
            $this->map_obj->selectOutputFormat($output);
        }
    }

    /**
     * Add legend and scalebar
     *
     * @return void
     */
    private function _addLegendScalebar()
    {
        if ($this->request->download
            && array_key_exists('legend', $this->request->options)
            && $this->request->options['legend']
        ) {
            $this->addLegend();
        } elseif (!$this->request->download) {
            $this->addLegend();
        }
        if ($this->request->download
            && array_key_exists('scalebar', $this->request->options)
            && $this->request->options['scalebar']
        ) {
            $this->addScalebar();
        } elseif (!$this->request->download) {
            $this->addScalebar();
        }
    }

    /**
     * Set the map extent
     *
     * @return void
     */
    private function _setMapExtent()
    {
        $ext = explode(',', $this->request->bbox_map);
        if ($this->request->projection !== $this->request->projection_map) {
            $extent = new \rectObj((float)$ext[0], (float)$ext[1], (float)$ext[2], (float)$ext[3]);
            if ($this->request->projection_map !== $this->default_projection) {
                $extent->project(
                    new \projectionObj($this->_setOrigin($this->request->projection_map, $this->request->origin_map)),
                    new \projectionObj(self::getProjection($this->default_projection))
                );
            }
            $limit = AcceptedProjections::$projections[$this->request->projection]['extent'] ?? $this->max_extent;
            $ext = [
                max($limit[0], $extent->minx), max($limit[1], $extent->miny),
                min($limit[2], $extent->maxx), min($limit[3], $extent->maxy)
            ];
            if (!is_finite(array_sum($ext)) || $ext[0] >= $ext[2] || $ext[1] >= $ext[3]) {
                $ext = $limit;
            }
        }

        $ext0 = (min($ext[0], $ext[2]) == $ext[0]) ? $ext[0] : $ext[2];
        $ext2 = (max($ext[0], $ext[2]) == $ext[2]) ? $ext[2] : $ext[0];
        $ext1 = (min($ext[1], $ext[3]) == $ext[1]) ? $ext[1] : $ext[3];
        $ext3 = (max($ext[1], $ext[3]) == $ext[3]) ? $ext[3] : $ext[1];

        // Set the padding correction factors because final extent produced after draw() is off from setExtent
        $cellsize = max(($ext2 - $ext0)/($this->image_size[0]-1), ($ext3 - $ext1)/($this->image_size[1]-1));

        if ($cellsize > 0) {
            $ox = max((($this->image_size[0]-1) - ($ext2 - $ext0)/$cellsize)/2, 0);
            $oy = max((($this->image_size[1]-1) - ($ext3 - $ext1)/$cellsize)/2, 0);
            $this->ox_pad = $ox*$cellsize;
            $this->oy_pad = $oy*$cellsize;
        }

        $this->map_obj->setExtent($ext0, $ext1, $ext2, $ext3);
    }

    /**
     * Set the map size
     *
     * @return void
     */
    private function _setMapSize()
    {
        $width = $this->image_size[0];
        $height = $this->image_size[1];
        if ($this->_isResize()) {
            $width = $this->request->download_factor*$this->image_size[0];
            $height = $this->request->download_factor*$this->image_size[1];
        }
        $this->map_obj->setSize($width, $height);
    }

    /**
     * Zoom In
     *
     * @return void
     */
    private function _setZoom()
    {
        //Zoom in
        if (isset($this->request->bbox_rubberband) && $this->request->bbox_rubberband && !$this->_isResize()) {
            $bbox_rubberband = explode(',', $this->request->bbox_rubberband);
            if ($bbox_rubberband[0] == $bbox_rubberband[2] || $bbox_rubberband[1] == $bbox_rubberband[3]) {
                $zoom_point = new \pointObj();
                $zoom_point->setXY($bbox_rubberband[0], $bbox_rubberband[1]);
                $this->map_obj->zoompoint(2, $zoom_point, $this->map_obj->width, $this->map_obj->height, $this->map_obj->extent, null);
            } else {
                // zoomRectangle requires inverted screen Y bounds, which the rectObj constructor rejects.
                $zoom_rect = new \rectObj();
                $zoom_rect->minx = $bbox_rubberband[0];
                $zoom_rect->miny = $bbox_rubberband[3];
                $zoom_rect->maxx = $bbox_rubberband[2];
                $zoom_rect->maxy = $bbox_rubberband[1];
                $this->map_obj->zoomrectangle($zoom_rect, $this->map_obj->width, $this->map_obj->height, $this->map_obj->extent, null);
            }
        }

        //Auto Zoom in
        if (isset($this->request->zoom_in) && $this->request->zoom_in) {
            $zoom_point = new \pointObj();
            $zoom_point->setXY($this->map_obj->width/2, $this->map_obj->height/2);
            $this->map_obj->zoompoint(2, $zoom_point, $this->map_obj->width, $this->map_obj->height, $this->map_obj->extent, null);
        }

        //Zoom out
        if (isset($this->request->zoom_out) && $this->request->zoom_out) {
            $zoom_point = new \pointObj();
            $zoom_point->setXY($this->map_obj->width/2, $this->map_obj->height/2);
            $this->map_obj->zoompoint(-2, $zoom_point, $this->map_obj->width, $this->map_obj->height, $this->map_obj->extent, null);
        }
    }

    /**
     * Set the pan direction
     *
     * @return void
     */
    private function _setPan()
    {
        if (isset($this->request->pan) && $this->request->pan) {
            switch ($this->request->pan) {
            case 'up':
                $x_offset = 1;
                $y_offset = 0.9;
                break;

            case 'right':
                $x_offset = 1.1;
                $y_offset = 1;
                break;

            case 'down':
                $x_offset = 1;
                $y_offset = 1.1;
                break;

            case 'left':
                $x_offset = 0.9;
                $y_offset = 1;
                break;
            }

            $new_point = new \pointObj();
            $new_point->setXY($this->map_obj->width/2*$x_offset, $this->map_obj->height/2*$y_offset);
            $this->map_obj->zoompoint(1, $new_point, $this->map_obj->width, $this->map_obj->height, $this->map_obj->extent, null);
        }
    }

    /**
     * Set the rotation
     *
     * @return void
     */
    private function _setRotation()
    {
        if (isset($this->request->rotation) && $this->request->rotation != 0) {
            $this->map_obj->setRotation($this->request->rotation);
            if ($this->request->projection == $this->default_projection) {
                $this->_reproject($this->request->projection);
            }
        }
    }

    /**
     * Set a new extent in the event of a crop action
     *
     * @return void
     */
    private function _setCrop()
    {
        if (isset($this->request->crop)
            && $this->request->crop
            && $this->request->bbox_rubberband
            && $this->_isResize()
        ) {
            $bbox_rubberband = explode(',', $this->request->bbox_rubberband);

            //lower-left coordinate
            $ll_point = new \stdClass();
            $ll_point->x = $bbox_rubberband[0];
            $ll_point->y = $bbox_rubberband[3];
            $ll_coord = $this->pix2Geo($ll_point);

            //upper-right coordinate
            $ur_point = new \stdClass();
            $ur_point->x = $bbox_rubberband[2];
            $ur_point->y = $bbox_rubberband[1];
            $ur_coord = $this->pix2Geo($ur_point);

            //set the size as selected
            $width = abs($bbox_rubberband[2]-$bbox_rubberband[0]);
            $height = abs($bbox_rubberband[3]-$bbox_rubberband[1]);

            $this->map_obj->setSize($width, $height);
            if ($this->_isResize() && $this->request->download_factor > 1) {
                $width = $this->request->download_factor*$width;
                $height = $this->request->download_factor*$height;
                $this->map_obj->setSize($width, $height);
            }

            //set the extent to match that of the crop
            $this->map_obj->setExtent($ll_coord->x, $ll_coord->y, $ur_coord->x, $ur_coord->y);
        }
    }

    /**
     * Add all selected layers to the map
     *
     * @return void
     */
    private function _addLayers()
    {
        $sort = [];

        $this->request->layers['base'] = 'on';
        unset($this->request->layers['grid']);

        foreach ($this->request->layers as $key => $row) {
            if (!isset($this->shapes[$key])) {
                throw new \InvalidArgumentException('The selected map layer is not installed.');
            }
            if (isset($this->request->output)
                && $this->request->output == 'svg'
                && $this->shapes[$key]['type'] == MS_LAYER_RASTER
            ) {
                unset($this->request->layers[$key]);
            } else {
                $sort[$key] = (isset($this->shapes[$key])) ? $this->shapes[$key]['sort'] : $row;
            }
        }
        array_multisort($sort, SORT_ASC, $this->request->layers);

        $srs_projections = implode(" ", array_keys(AcceptedProjections::$projections));

        foreach ($this->request->layers as $name => $status) {
            if (array_key_exists($name, $this->shapes)) {
                $data = $this->shapes[$name]['path'];
                if (!is_file($data) && !is_file($data.'.shp')) {
                    throw new \RuntimeException('Map data is unavailable for layer '.$name.'.', 503);
                }
                $layer = new \layerObj($this->map_obj);
                $layer->name = $name;
                $layer->metadata->set("wfs_title", $name);
                $layer->metadata->set("wfs_typename", $name);
                $layer->metadata->set("wfs_srs", $srs_projections);
                $layer->metadata->set("wfs_extent", "-180 -90 180 90");
                if (isset($this->shapes[$name]['encoding'])) { $layer->metadata->set("wfs_encoding", $this->shapes[$name]['encoding']); }
                $layer->metadata->set("wms_title", $name);
                $layer->metadata->set("wms_srs", $srs_projections);
                $layer->metadata->set("gml_include_items", "all");
                $layer->metadata->set("gml_geometries", "geometry");
                $layer->type = $this->shapes[$name]['type'];
                $layer->status = MS_ON;
                if ($layer->type !== MS_LAYER_RASTER) { $layer->setConnectionType(MS_SHAPEFILE, ''); }
                $layer->data = $this->shapes[$name]['path'];
                $layer->setProjection(self::getProjection($this->default_projection));
                $layer->template = "template.html";
                if (isset($this->shapes[$name]['filter'])) {
                    $filter=$this->shapes[$name]['filter'];
                    $layer->setFilter('("['.$filter['item'].']" = "'.$filter['equals'].'")');
                }
                foreach (DatasetCredits::selected([$name]) as $dataset) {
                    foreach (['wms','wfs'] as $service) {
                        $layer->metadata->set($service.'_abstract',DatasetCredits::notice($dataset));
                        $layer->metadata->set($service.'_metadataurl_href',DatasetCredits::url());
                        $layer->metadata->set($service.'_metadataurl_type','TC211');
                        $layer->metadata->set($service.'_metadataurl_format','text/html');
                    }
                }

                if (isset($this->shapes[$name]['opacity'])) {
                    $layer->setOpacity((int)$this->shapes[$name]['opacity']);
                }

                if (isset($this->shapes[$name]['class'])) {
                    $classitem = $this->shapes[$name]['class']['item'];
                    $layer->classitem = $classitem;
                    $this->_setSLDClasses($layer, $this->shapes[$name]['class']['sld'], $classitem);
                    $this->_legend_required = true;
                }

                if (isset($this->shapes[$name]['tolerance'])) {
                    $layer->tolerance = (int)$this->shapes[$name]['tolerance'];
                }

                if (isset($this->shapes[$name]['tolerance_units'])) {
                    $layer->toleranceunits = $this->shapes[$name]['tolerance_units'];
                }

                if (isset($this->shapes[$name]['label'])) {
                    $layer->labelitem = $this->shapes[$name]['label']['item'];
                }

                $class = new \classObj($layer);
                $style = new \styleObj($class);

                if (isset($this->shapes[$name]['legend'])) {
                    $class->name = $this->shapes[$name]['legend'];
                    $this->_legend_required = true;
                }

                if (isset($this->shapes[$name]['outline_color'])) {
                    $color = explode(",", $this->shapes[$name]['outline_color']);
                    $style->outlinecolor->setRGB($color[0], $color[1], $color[2]);
                }

                if (isset($this->shapes[$name]['color'])) {
                    $color = explode(",", $this->shapes[$name]['color']);
                    $style->color->setRGB($color[0], $color[1], $color[2]);
                }

                if (isset($this->shapes[$name]['dynamic_width'])) {
                    $style->width = $this->_determineWidth();
                }

                if (isset($this->shapes[$name]['label'])) {
                    $class->addLabel($this->_createLabel((int)$this->shapes[$name]['label']['size'], $this->shapes[$name]['label']['position'], $this->shapes[$name]['encoding']));
                }

                if (isset($this->shapes[$name]['symbol'])) {
                    $style->setSymbolByName($this->map_obj, $this->shapes[$name]['symbol']['shape']);
                    $size = $this->shapes[$name]['symbol']['size'];
                    $style->size = ($this->_isResize() && $this->request->download_factor > 1) ? $this->request->download_factor*($size+1) : $size;
                    $color = explode(",", $this->shapes[$name]['symbol']['color']);
                    $style->color->setRGB($color[0], $color[1], $color[2]);
                }
            }
        }
    }

    /**
     * Make label for layers
     *
     * @param int    $size     The pixel size for the label.
     * @param int    $position The position of the label using MapScript constant
     * @param string $encoding The encoding
     *
     * @return object $label
     */
    private function _createLabel($size = 8, $position = MS_UR, $encoding = "CP1252")
    {
        $label = new \labelObj();
        $label->font = "arial";
        $label->encoding = $encoding;
        if ($this->_isResize() && $this->request->download_factor > 1) {
            $size = $this->request->download_factor*7;
        }
        $label->size = $size;
        $label->position = $position;
        $label->offsetx = 3;
        $label->offsety = 3;
        $label->partials = MS_FALSE;
        $label->color->setRGB(10, 10, 10);
        return $label;
    }

    /**
     * Add a watermark
     *
     * @return void
     */
    private function _addWatermark()
    {
        if (isset($this->request->watermark) && $this->request->watermark) {
            $layer = new \layerObj($this->map_obj);
            $layer->name = 'watermark';
            $layer->type = MS_LAYER_POINT;
            $layer->status = MS_ON;
            $layer->transform = MS_FALSE;
            $layer->sizeunits = MS_PIXELS;

            $class = new \classObj($layer);
            $class->settext(MAPPR_URL);

            $label = new \labelObj();
            $label->font = "arial";
            $label->encoding = "UTF-8";
            $label->size = 8;
            $label->position = MS_XY;
            $label->color->setRGB(10, 10, 10);
            $class->addLabel($label);

            $shape = new \shapeObj(MS_SHAPE_POINT);
            $line = new \lineObj();

            $point = new \pointObj();
            $point->setXY(2, $this->map_obj->height-2);

            $line->add($point);
            $shape->add($line);
            $layer->addFeature($shape);
        }
    }

    protected function wrapRasterLayers(array $layers,string $projection): void
    {
        if (!preg_match('/(?:^|\s)\+proj=lcc(?:\s|$)/',$projection) || !preg_match('/(?:^|\s)\+lon_0=([-+0-9.eE]+)(?:\s|$)/',$projection,$origin)) { return; }
        foreach ($layers as $name) {
            $layer=$this->map_obj->getLayerByName($name);
            if (!$layer || $layer->type!==MS_LAYER_RASTER) { continue; }
            $this->wrappedRasterSource ??= new \SimpleMappr\WrappedRasterSource();
            $source=$this->wrappedRasterSource->prepare($layer->data,(float)$origin[1]);
            if ($source!==null) { $layer->data=$source['path'];$layer->setProjection($source['projection']); }
        }
    }

    protected function addDetailedRelief(array $layers,?array $bounds=null,?string $projection=null,?int $width=null,?int $height=null): void
    {
        if (!in_array('reliefgrey',$layers,true) || $this->request->output==='svg' || !\SimpleMappr\DetailedRelief::enabled()) { return; }
        $extent=$this->map_obj->extent;
        $bounds ??= [$extent->minx,$extent->miny,$extent->maxx,$extent->maxy];
        if (count(array_filter($bounds,static fn($value)=>is_finite((float)$value) && abs((float)$value)<=1e9))!==4) { throw new \InvalidArgumentException('The map extent is outside supported Earth coordinates.'); }
        $source=new \projectionObj($projection ?? $this->map_obj->getProjection());
        $destination=new \projectionObj('EPSG:3857');
        $center=new \pointObj(($bounds[0]+$bounds[2])/2,($bounds[1]+$bounds[3])/2);
        if ($center->project($source,$destination)!==MS_SUCCESS || !is_finite($center->x)) { return; }
        $angle=deg2rad($projection===null?(float)$this->request->rotation:0);
        $cx=($bounds[0]+$bounds[2])/2;$cy=($bounds[1]+$bounds[3])/2;
        $xs=[];$ys=[];
        for ($i=0;$i<=16;$i++) {
            $fraction=$i/16;
            foreach ([[$bounds[0]+($bounds[2]-$bounds[0])*$fraction,$bounds[1]],[$bounds[0]+($bounds[2]-$bounds[0])*$fraction,$bounds[3]],[$bounds[0],$bounds[1]+($bounds[3]-$bounds[1])*$fraction],[$bounds[2],$bounds[1]+($bounds[3]-$bounds[1])*$fraction]] as [$x,$y]) {
                $dx=$x-$cx;$dy=$y-$cy;
                $point=new \pointObj($cx+$dx*cos($angle)-$dy*sin($angle),$cy+$dx*sin($angle)+$dy*cos($angle));
                if ($point->project($source,$destination)!==MS_SUCCESS || !is_finite($point->x) || !is_finite($point->y)) { return; }
                $half=\SimpleMappr\DetailedRelief::HALF_WORLD;$world=2*$half;
                $point->x=$center->x+fmod(fmod($point->x-$center->x+$half,$world)+$world,$world)-$half;
                $xs[]=$point->x;$ys[]=$point->y;
            }
        }
        $plan=\SimpleMappr\DetailedRelief::plan([min($xs),min($ys),max($xs),max($ys)],$width ?? $this->map_obj->width,$height ?? $this->map_obj->height);
        if ($plan===null) { return; }
        $this->detailedRelief=new \SimpleMappr\DetailedRelief();
        $path=$this->detailedRelief->prepare($plan);
        if ($path===null) { return; }
        $mask=new \layerObj($this->map_obj);$mask->name='internal_detailed_relief_land';$mask->type=MS_LAYER_POLYGON;$mask->status=MS_OFF;
        $mask->setProjection('EPSG:4326');$mask->metadata->set('ows_enable_request','!*');
        $maskClass=new \classObj($mask);$maskStyle=new \styleObj($maskClass);$maskStyle->color->setRGB(255,255,255);
        $land=new \shapefileObj($this->shapes['base']['path'],-1);
        foreach (\SimpleMappr\DetailedRelief::geographicClips($plan) as [$west,$south,$east,$north]) {
            $clip=\shapeObj::fromWKT("POLYGON(($west $south,$east $south,$east $north,$west $north,$west $south))");
            for ($index=0;$index<$land->numshapes;$index++) {
                $shape=$land->getShape($index)->intersection($clip);
                if ($shape!==null && $shape->numlines>0) { $mask->addFeature($shape); }
            }
        }
        $layer=new \layerObj($this->map_obj);$layer->name='internal_detailed_relief';$layer->type=MS_LAYER_RASTER;$layer->status=MS_DEFAULT;
        $layer->data=$path;$layer->setProjection('EPSG:3857');$layer->addProcessing('RESAMPLE=BILINEAR');$layer->updateFromString('LAYER MASK internal_detailed_relief_land END');
        $layer->metadata->set('ows_enable_request','!*');
        $detail=$layer->index;
        $grey=$this->map_obj->getLayerByName('reliefgrey')->index;
        for ($i=0;$i<$detail-$grey-1;$i++) { $this->map_obj->moveLayerUp($detail); }
        $this->detailedReliefUsed=true;$this->detailedReliefZoom=$plan['zoom'];
    }

    protected function addDataCredits(array $layers,?int $width=null,?int $height=null): void
    {
        $datasets=DatasetCredits::selected($layers);
        if ($this->detailedReliefUsed) { $datasets['detailedRelief']=DatasetCredits::DETAILED_RELIEF; }
        if (!$datasets) { return; }
        $scale=$width===null && $this->_isResize()?max(1,(int)$this->request->download_factor):1;
        $width ??= $this->map_obj->width;$height ??= $this->map_obj->height;
        $lines=DatasetCredits::lines($datasets,(int)floor($width/$scale));
        $embeddedScalebar=$this->request->download && ($this->request->options['scalebar'] ?? false) && $this->request->output!=='svg';
        $reservedBottom=($embeddedScalebar?36:($this->request->watermark?12:0))*$scale;
        $requiredHeight=(count($lines)*13+24)*$scale+$reservedBottom;
        if ($width<160*$scale || $height<$requiredHeight) { throw new \InvalidArgumentException('Required map data credits need at least '.(160*$scale).' pixels of width and '.$requiredHeight.' pixels of height.'); }
        foreach ($lines as $index=>$text) {
            $layer=new \layerObj($this->map_obj);
            $layer->name='dataset_credit_'.$index;
            $layer->type=MS_LAYER_POINT;$layer->status=MS_DEFAULT;$layer->transform=MS_FALSE;$layer->sizeunits=MS_PIXELS;
            $class=new \classObj($layer);$class->setText($text);
            $label=new \labelObj();$label->font='arial';$label->encoding='UTF-8';$label->size=7*$scale;$label->position=MS_XY;
            $label->color->setRGB(0,0,0);$label->outlinecolor->setRGB(255,255,255);$label->outlinewidth=2*$scale;$label->force=MS_TRUE;$label->partials=MS_TRUE;
            $class->addLabel($label);
            $point=new \pointObj();$point->setXY(8*$scale,$height-$reservedBottom-(10+(count($lines)-1-$index)*13)*$scale);
            $line=new \lineObj();$line->add($point);$shape=new \shapeObj(MS_SHAPE_POINT);$shape->add($line);$layer->addFeature($shape);
        }
        DatasetCredits::headers($datasets);
    }

    /**
     * Discover if the output ought to be resized
     *
     * @return bool
     */
    private function _isResize()
    {
        if ($this->request->download
            || $this->request->output == 'pptx'
            || $this->request->output == 'docx'
        ) {
            return true;
        }
        return false;
    }

    /**
     * Add a border to a downloaded map image
     *
     * @return void
     */
    private function _addBorder()
    {
        if ($this->_isResize()
            && array_key_exists('border', $this->request->options)
            && ($this->request->options['border'] == 1 || $this->request->options['border'] == 'true')
        ) {
            $outline_layer = new \layerObj($this->map_obj);
            $outline_layer->name = "outline";
            $outline_layer->type = MS_LAYER_POLYGON;
            $outline_layer->status = MS_ON;
            $outline_layer->transform = MS_FALSE;
            $outline_layer->sizeunits = MS_PIXELS;

            // Add new class to new layer
            $outline_class = new \classObj($outline_layer);

            // Add new style to new class
            $outline_style = new \styleObj($outline_class);
            $outline_style->outlinecolor->setRGB(0, 0, 0);
            $outline_style->width = 3;

            $polygon = new \shapeObj(MS_SHAPE_POLYGON);

            $polyLine = new \lineObj();
            $polyLine->add(new \pointObj(0, 0));
            $polyLine->add(new \pointObj($this->map_obj->width, 0));
            $polyLine->add(new \pointObj($this->map_obj->width, $this->map_obj->height));
            $polyLine->add(new \pointObj(0, $this->map_obj->height));
            $polyLine->add(new \pointObj(0, 0));

            $polygon->add($polyLine);
            $outline_layer->addFeature($polygon);
        }
    }

    /**
     * Prepare the output.
     *
     * @return void
     */
    private function _prepareOutput()
    {
        if (isset($this->request->projection)) {
            $this->_reproject($this->request->projection);
            $this->wrapRasterLayers(array_keys($this->request->layers),$this->map_obj->getProjection());
            $this->addDetailedRelief(array_keys($this->request->layers));
            $this->addDataCredits(array_keys($this->request->layers));
            $this->_addLegendScalebar();
            $this->_addBorder();
            $this->image = $this->map_obj->drawQuery();
        }
    }

    /**
     * Reproject a $map from one projection to another.
     *
     * @param string $output_projection The output projection.
     *
     * @return void
     */
    private function _reproject($output_projection)
    {
        $target = $this->_setOrigin($output_projection, $this->request->origin);
        $source = $this->map_obj->getProjection();
        if ($source !== $target) {
            $extent = new \rectObj(
                $this->map_obj->extent->minx, $this->map_obj->extent->miny,
                $this->map_obj->extent->maxx, $this->map_obj->extent->maxy
            );
            $extent->project(new \projectionObj($source), new \projectionObj($target));
            $this->map_obj->setProjection($target);
            $this->map_obj->setExtent($extent->minx, $extent->miny, $extent->maxx, $extent->maxy);
        }
        $this->map_obj->units = $output_projection === $this->default_projection ? MS_DD : MS_METERS;
    }

    private function _setOrigin($output_projection, $origin)
    {
        $projection = self::getProjection($output_projection);
        $lambert = ['esri:102009', 'esri:102015', 'esri:102014', 'esri:102012', 'esri:102024', 'epsg:3112'];
        if (in_array($output_projection, $lambert, true)
            && $origin !== false && $origin !== null
            && abs($origin) <= 180
        ) {
            $projection = preg_replace('/\\+lon_0=\\S+/', '+lon_0='.$origin, $projection);
        }
        return $projection;
    }

    protected function configureGraticule($layer, $format, $arcs, $interval, $subdivide)
    {
        if (!in_array($format, ['DD', 'DDMM', 'DDMMSS'], true) || !is_numeric($interval) || $interval <= 0) {
            throw new \InvalidArgumentException('Invalid graticule settings');
        }
        $layer->updateFromString(sprintf(
            'LAYER GRID LABELFORMAT "%s" MAXARCS %d MAXINTERVAL %.10f MAXSUBDIVIDE %d END END',
            $format, max(1, (int)$arcs), (float)$interval, (int)$subdivide
        ));
    }

    /**
     * Build ecoregion layer classes from SLD file
     *
     * @param obj    $layer The MapScript layer object.
     * @param string $sld   The full path to an SLD file
     * @param string $item  The term filtered on in the SLD
     *
     * @return void
     */
    private function _setSLDClasses($layer, $sld, $item)
    {
        $xml = simplexml_load_file($sld);
        $xml->registerXPathNamespace('sld', 'http://www.opengis.net/sld');
        $xml->registerXPathNamespace('se', 'http://www.opengis.net/se');
        $xml->registerXPathNamespace('ogc', 'http://www.opengis.net/ogc');

        //version 1.0.0 of SLD
        foreach ($xml->xpath('//sld:Rule') as $rule) {
            $class = new \classObj($layer);
            $class->setExpression("([".$item."] = ".(string)$rule->xpath('.//ogc:Literal')[0].")");
            $style = new \styleObj($class);
            $color = Utility::hex2Rgb((string)$rule->xpath('.//sld:CssParameter[@name="fill"]')[0]);
            $style->color->setRGB($color[0], $color[1], $color[2]);
            $style->outlinecolor->setRGB(30, 30, 30);
        }

        //version 1.1.0 of SLD
        foreach ($xml->xpath('//se:Rule') as $rule) {
            $class = new \classObj($layer);
            $class->setExpression("([".$item."] = ".(string)$rule->xpath('.//se:Name')[0].")");
            $style = new \styleObj($class);
            $color = Utility::hex2Rgb((string)$rule->xpath('.//se:SvgParameter')[0]);
            $style->color->setRGB($color[0], $color[1], $color[2]);
            $style->outlinecolor->setRGB(30, 30, 30);
        }
    }

    /**
     * Get the scaled width for a layer's line
     *
     * @return number $width
     */
    private function _determineWidth()
    {
        $width = 1.25;
        if (isset($this->request->border_thickness)) {
            $width = $this->request->border_thickness;
            if ($this->_isResize()
                && $this->request->download_factor > 1
                && array_key_exists('scalelinethickness', $this->request->options)
                && $this->request->options['scalelinethickness']
            ) {
                $width = $this->request->border_thickness*$this->request->download_factor/2;
            }
        }
        return $width;
    }
}
