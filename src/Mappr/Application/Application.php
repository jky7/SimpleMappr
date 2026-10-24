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

use SimpleMappr\Request;
use SimpleMappr\Utility;
use SimpleMappr\Header;
use SimpleMappr\Mappr\Mappr;

/**
 * Application class for SimpleMappr
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 */
class Application extends Mappr
{

    /**
     * Run a request, answering a repeated identical preview from the render cache.
     *
     * @return string
     */
    public static function respond()
    {
        $key = \SimpleMappr\RenderCache::enabled() ? \SimpleMappr\RenderCache::key(Request::getRequest()) : null;
        if ($key !== null && ($entry = \SimpleMappr\RenderCache::fetch($key)) !== null) {
            Header::setHeader('json');
            header('X-Render-Cache: hit');
            return json_encode(\SimpleMappr\RenderCache::materialize($key, $entry));
        }
        $application = (new static())->execute();
        $body = $application->createOutput();
        // A relief render that fell back to the overview must not be remembered as the answer.
        if ($key !== null && !$application->detailedReliefDegraded) {
            \SimpleMappr\RenderCache::store($key, json_decode($body, true));
            header('X-Render-Cache: miss');
        }
        return $body;
    }

    /**
     * Implement getRequest method
     *
     * @return obj
     */
    public function getRequest()
    {
        return Request::getRequest();
    }

    /**
     * Implement createOutput method
     *
     * @return void
     */
    public function createOutput()
    {
        $format = $this->request->output;
        if ($this->request->download || in_array($format, ['tif','svg'], true)) {
            $extension = $format === 'pnga' ? 'png' : $format;
            $bytes = \SimpleMappr\RenderedImage::bytes($this->image, $this->map_obj, $this->request->output);
            Header::setHeader($extension, Utility::cleanFilename($this->request->file_name, $extension), strlen($bytes));
            return $bytes;
        }
        Header::setHeader('json');
        return json_encode($this->_defaultOutput());
    }

    /**
     * Produce an array for the default output
     *
     * @return array
     */
    private function _defaultOutput()
    {
        $this->image_url = \SimpleMappr\TemporaryImage::write($this->image, $this->map_obj, $this->request->output === 'pnga' ? 'png' : $this->request->output)['url'];

        $bbox = [
          sprintf('%.10f', $this->map_obj->extent->minx + $this->ox_pad),
          sprintf('%.10f', $this->map_obj->extent->miny + $this->oy_pad),
          sprintf('%.10f', $this->map_obj->extent->maxx - $this->ox_pad),
          sprintf('%.10f', $this->map_obj->extent->maxy - $this->oy_pad)
        ];

        $output = [
          'mapOutputImage'      => $this->image_url,
          'size'                => $this->image_size,
          'rendered_bbox'       => implode(",", $bbox),
          'rendered_rotation'   => $this->request->rotation,
          'rendered_projection' => $this->request->projection,
          'rendered_origin'     => $this->request->origin,
          'legend_url'          => $this->legend_url,
          'scalebar_url'        => $this->scalebar_url,
          'bad_points'          => $this->getBadPoints(),
          'bad_drawings'        => $this->getBadDrawings(),
          'relief_source'       => $this->detailedReliefUsed ? ['name'=>'NASA/METI ASTER GDEM via NASA GIBS','zoom'=>$this->detailedReliefZoom,'pixel_metres_at_equator'=>2*\SimpleMappr\DetailedRelief::HALF_WORLD/(256*(1<<$this->detailedReliefZoom)),'coverage'=>'Land between 83 degrees south and north; overview fills uncovered areas.'] : null
        ];

        return $output;
    }
}
