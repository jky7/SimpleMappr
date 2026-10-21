<?php
namespace SimpleMappr\Mappr\WebServices;

use SimpleMappr\Header;
use SimpleMappr\ImportData;
use SimpleMappr\Request;
use SimpleMappr\Mappr\Mappr;

abstract class OgcService extends Mappr
{
    public const SERVICE_SRS = 'EPSG:4326 EPSG:3857 EPSG:3395 EPSG:3112';
    protected string $service;
    private array $selectedLayers;
    private array $parameters = [];
    private string $postXml = '';
    private ?string $inputError = null;

    public function __construct(array $layers = [])
    {
        $shapes = self::getShapefileConfig();
        $this->selectedLayers = array_fill_keys(array_keys(array_filter($shapes, fn($shape, $name) => (!$layers || in_array($name, $layers, true)) && ($this->service !== 'WFS' || $shape['type'] !== MS_LAYER_RASTER), ARRAY_FILTER_USE_BOTH)), 'on');
        try { $this->readParameters(); }
        catch (\InvalidArgumentException $e) { $this->inputError = $e->getMessage(); }
        parent::__construct();
    }

    public function getRequest()
    {
        return Request::fromDocument(['layers'=>$this->selectedLayers]);
    }

    private function readParameters(): void
    {
        $allowed = $this->service === 'WMS'
            ? ['SERVICE','VERSION','REQUEST','LAYERS','LAYER','STYLES','SRS','CRS','BBOX','WIDTH','HEIGHT','FORMAT','TRANSPARENT','BGCOLOR','EXCEPTIONS','QUERY_LAYERS','INFO_FORMAT','FEATURE_COUNT','X','Y','I','J','FILTER']
            : ['SERVICE','VERSION','REQUEST','TYPENAME','TYPENAMES','MAXFEATURES','COUNT','STARTINDEX','OUTPUTFORMAT','FILTER','BBOX','SRSNAME','RESULTTYPE','PROPERTYNAME','FEATUREID','RESOURCEID','ACCEPTVERSIONS'];
        foreach (array_change_key_case($_REQUEST, CASE_UPPER) as $name=>$value) {
            if (!in_array($name, $allowed, true)) { continue; }
            if (!is_scalar($value) || strlen((string)$value) > 100000) { throw new \InvalidArgumentException('Invalid service parameter '.$name.'.'); }
            $this->parameters[$name] = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string)$value;
        }
        $this->parameters['SERVICE'] = $this->service;
        $this->parameters['VERSION'] ??= $this->service === 'WMS' ? '1.1.1' : '1.0.0';
        $this->parameters['REQUEST'] ??= 'GetCapabilities';
        if ($this->service === 'WMS') {
            $this->parameters += ['WIDTH'=>'200', 'HEIGHT'=>'100','FORMAT'=>'image/png','STYLES'=>'','TRANSPARENT'=>'TRUE'];
            if (isset($this->parameters['LAYER']) && !isset($this->parameters['LAYERS'])) { $this->parameters['LAYERS'] = $this->parameters['LAYER']; }
            foreach (['WIDTH','HEIGHT'] as $key) {
                if (!ctype_digit($this->parameters[$key]) || (int)$this->parameters[$key] < 2 || (int)$this->parameters[$key] > 5000) { throw new \InvalidArgumentException('Image dimensions must be between 2 and 5000 pixels.'); }
            }
            if ((int)$this->parameters['WIDTH'] * (int)$this->parameters['HEIGHT'] > 20000000) { throw new \InvalidArgumentException('The requested map exceeds 20 megapixels.'); }
        } else {
            foreach (['COUNT','MAXFEATURES'] as $key) {
                if (isset($this->parameters[$key]) && !ctype_digit($this->parameters[$key])) { throw new \InvalidArgumentException('Invalid feature count.'); }
                if (isset($this->parameters[$key])) { $this->parameters[$key] = (string)min(1000, max(0, (int)$this->parameters[$key])); }
            }
            $limit = min((int)($this->parameters['COUNT'] ?? 1000), (int)($this->parameters['MAXFEATURES'] ?? 1000));
            unset($this->parameters['COUNT'], $this->parameters['MAXFEATURES']);
            $this->parameters[version_compare($this->parameters['VERSION'], '2.0.0', '>=') ? 'COUNT' : 'MAXFEATURES'] = (string)$limit;
        }
        if (isset($this->parameters['FILTER'])) { ImportData::xml($this->parameters['FILTER']); }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'xml')) {
            $body = file_get_contents('php://input', false, null, 0, 1000001);
            if (strlen($body) > 1000000) { throw new \InvalidArgumentException('Service XML exceeds 1 MB.'); }
            $xml = ImportData::xml($body);
            foreach (['SLD','OnlineResource'] as $name) { if ($xml->getElementsByTagNameNS('*', $name)->length) { throw new \InvalidArgumentException('Remote styles are not supported.'); } }
            foreach ($xml->getElementsByTagName('*') as $element) {
                foreach (['width','height'] as $dimension) {
                    if ($element->hasAttribute($dimension)) {
                        $value = $element->getAttribute($dimension);
                        if (!ctype_digit($value) || (int)$value < 2 || (int)$value > 5000) { throw new \InvalidArgumentException('Image dimensions must be between 2 and 5000 pixels.'); }
                    }
                }
                if ($element->hasAttribute('width') && $element->hasAttribute('height') && (int)$element->getAttribute('width') * (int)$element->getAttribute('height') > 20000000) { throw new \InvalidArgumentException('The requested map exceeds 20 megapixels.'); }
                foreach (['count','maxFeatures'] as $count) {
                    if ($element->hasAttribute($count)) {
                        $value = $element->getAttribute($count);
                        if (!ctype_digit($value)) { throw new \InvalidArgumentException('Invalid feature count.'); }
                        $element->setAttribute($count, (string)min(1000, max(0, (int)$value)));
                    }
                }
            }
            $this->parameters['REQUEST'] = $xml->documentElement->localName;
            if ($xml->documentElement->hasAttribute('version')) { $this->parameters['VERSION'] = $xml->documentElement->getAttribute('version'); }
            if ($this->service === 'WMS' && strtolower($this->parameters['REQUEST']) === 'getmap') {
                $xpath = new \DOMXPath($xml);
                $text = static function (string $name) use ($xpath): string { return trim((string)$xpath->evaluate('string(//*[local-name()="'.$name.'"][1])')); };
                $this->parameters['WIDTH'] = $text('Width');
                $this->parameters['HEIGHT'] = $text('Height');
                foreach (['WIDTH','HEIGHT'] as $key) {
                    if (!ctype_digit($this->parameters[$key]) || (int)$this->parameters[$key] < 2 || (int)$this->parameters[$key] > 5000) { throw new \InvalidArgumentException('Image dimensions must be between 2 and 5000 pixels.'); }
                }
                if ((int)$this->parameters['WIDTH'] * (int)$this->parameters['HEIGHT'] > 20000000) { throw new \InvalidArgumentException('The requested map exceeds 20 megapixels.'); }
                $names = [];
                foreach ($xpath->query('//*[local-name()="NamedLayer"]/*[local-name()="Name"]') as $name) { $names[] = trim($name->textContent); }
                if (!$names) { throw new \InvalidArgumentException('The XML map request must name a layer.'); }
                $this->parameters['LAYERS'] = implode(',', $names);
                $this->parameters['STYLES'] = '';
                foreach ($xpath->query('//*[local-name()="NamedStyle"]/*[local-name()="Name"]') as $name) { if (!in_array(trim($name->textContent), ['', 'default'], true)) { throw new \InvalidArgumentException('Only the default XML map style is supported.'); } }
                $bounds = $xpath->query('//*[local-name()="BoundingBox"]')->item(0);
                if (!$bounds) { throw new \InvalidArgumentException('The XML map request must include a bounding box.'); }
                $this->parameters[version_compare($this->parameters['VERSION'], '1.3.0', '>=') ? 'CRS' : 'SRS'] = $bounds->getAttribute('srsName');
                $this->parameters['BBOX'] = implode(',', preg_split('/\s+/', $text('LowerCorner').' '.$text('UpperCorner')));
                $this->parameters['FORMAT'] = $text('Format');
                $this->parameters['TRANSPARENT'] = $text('Transparent') ?: 'TRUE';
            } else { $this->postXml = $xml->saveXML(); }

        }
    }

    public function makeService()
    {
        $prefix = strtolower($this->service);
        $metadata = $this->map_obj->web->metadata;
        $metadata->set($prefix.'_title', 'SimpleMappr '.$this->service);
        $metadata->set($prefix.'_abstract', 'SimpleMappr bundled map data');
        $metadata->set($prefix.'_onlineresource', self::baseUrl().'/'.$prefix.'?');
        $metadata->set($prefix.'_srs', self::SERVICE_SRS);
        $metadata->set($prefix.'_encoding', 'UTF-8');
        $metadata->set($prefix.'_enable_request', $this->service === 'WFS' ? 'GetCapabilities DescribeFeatureType GetFeature' : 'GetCapabilities GetMap GetLegendGraphic GetFeatureInfo DescribeLayer GetStyles');
        $metadata->set('wfs_maxfeatures', '1000');
        $metadata->set('ows_namespace_prefix', 'simplemappr');
        $metadata->set('ows_namespace_uri', self::baseUrl().'/features');
        return $this;
    }

    public static function baseUrl(): string
    {
        if (preg_match('~^https?://~i', MAPPR_URL)) { return MAPPR_URL; }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[0-9]{1,5})?$/iD', $host)) { $host = 'localhost'; }
        return (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http').'://'.$host.MAPPR_URL;
    }

    public function execute()
    {
        if ($this->inputError === null) {
            $this->prepareServiceMap();
            for ($i=0; $i<$this->map_obj->numlayers; $i++) {
                $metadata = $this->map_obj->getLayer($i)->metadata;
                $metadata->set('wms_srs', self::SERVICE_SRS);
                $metadata->set('wfs_srs', self::SERVICE_SRS);
                if ($this->service === 'WFS') {
                    $layer = $this->map_obj->getLayer($i);
                    $path = $layer->data;
                    $layer->setConnectionType(MS_OGR, '');
                    $layer->connection = is_file($path) ? $path : $path.'.shp';
                    $layer->data = 'SELECT FID AS mappr_id, * FROM "'.str_replace('"', '""', pathinfo($path, PATHINFO_FILENAME)).'"';
                    $metadata->set('gml_featureid', 'mappr_id');
                }
            }
        }
        return $this;
    }

    public function createOutput()
    {
        if ($this->inputError !== null) { return $this->exception($this->inputError); }
        $request = new \OWSRequest();
        foreach ($this->parameters as $name=>$value) { $request->setParameter($name, $value); }
        if ($this->postXml !== '') {
            $request->type = MS_POST_REQUEST;
            $request->contenttype = 'text/xml';
            $request->postrequest = $this->postXml;
        }
        msIO_installStdoutToBuffer();
        try {
            try { $result = $this->map_obj->owsDispatch($request); }
            catch (\Exception $e) { $result = MS_FAILURE; }
            if (msIO_getStdoutBufferString() === '') { return $this->exception('The service request is invalid or unsupported.'); }
            $type = msIO_stripStdoutBufferContentType();
            Header::setHeader();
            header('Content-Type: '.($type ?: 'application/xml'));
            if ($result !== MS_SUCCESS) { http_response_code(400); }
            ob_start();
            $bytes = msIO_getStdoutBufferBytes();
            $printed = ob_get_clean();
            return is_string($bytes) ? $bytes : $printed;
        } finally { msIO_resetHandlers(); }
    }

    private function exception(string $message): string
    {
        http_response_code(400); Header::setHeader('xml');
        $message = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        if ($this->service === 'WMS') {
            return '<?xml version="1.0" encoding="UTF-8"?><ServiceExceptionReport version="1.3.0"><ServiceException code="InvalidParameterValue">'.$message.'</ServiceException></ServiceExceptionReport>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><ows:ExceptionReport xmlns:ows="http://www.opengis.net/ows/1.1" version="2.0.0"><ows:Exception exceptionCode="InvalidParameterValue"><ows:ExceptionText>'.$message.'</ows:ExceptionText></ows:Exception></ows:ExceptionReport>';
    }
}
