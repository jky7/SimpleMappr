<?php
namespace SimpleMappr;

final class WrappedRasterSource
{
    private ?string $directory=null;
    private array $sources=[];

    public function prepare(string $source,float $center): ?array
    {
        if (!is_finite($center) || abs($center)>180) { throw new \InvalidArgumentException('Invalid raster longitude origin.'); }
        $source=realpath($source);
        if ($source===false || !is_file($source)) { throw new \RuntimeException('Map background is unavailable.',503); }
        $key=hash('sha256',$source.'|'.$center);
        if (array_key_exists($key,$this->sources)) { return $this->sources[$key]; }
        if ($this->directory===null) {
            $this->directory=sys_get_temp_dir().'/simplemappr-wrap-'.bin2hex(random_bytes(16));
            if (!mkdir($this->directory,0700)) { throw new \RuntimeException('Map background storage is unavailable.',503); }
        }
        $path=$this->directory.'/'.$key.'.vrt';
        $this->translate($source,$path);
        $xml=new \DOMDocument();
        if (!$xml->loadXML(file_get_contents($path),LIBXML_NONET) || $xml->doctype!==null) { throw new \RuntimeException('Map background metadata is invalid.',503); }
        $root=$xml->documentElement;$xpath=new \DOMXPath($xml);
        $width=(int)$root->getAttribute('rasterXSize');$height=(int)$root->getAttribute('rasterYSize');
        $transform=$xpath->query('/VRTDataset/GeoTransform')->item(0);
        $grid=$transform?array_map('floatval',explode(',',$transform->textContent)):[];
        $srs=$xpath->query('/VRTDataset/SRS')->item(0)?->textContent ?? '';
        if ($width<1 || $height<1 || count($grid)!==6 || !str_contains($srs,'WGS 84') || abs($grid[0]+180)>1e-6 || abs($grid[3]-90)>1e-6 || abs($grid[1]*$width-360)>1e-6 || abs($grid[5]*$height+180)>1e-6 || $grid[2]!==0.0 || $grid[4]!==0.0) {
            return $this->sources[$key]=null;
        }
        $projection='+proj=longlat +datum=WGS84 +no_defs +lon_wrap='.$center;
        $root->setAttribute('rasterXSize',(string)($width*3));$grid[0]-=360;
        $transform->textContent=implode(',',$grid);
        foreach (iterator_to_array($xpath->query('//SimpleSource | //ComplexSource')) as $original) {
            $rectangle=$xpath->query('./DstRect',$original)->item(0);
            if ($rectangle===null) { throw new \RuntimeException('Map background window is invalid.',503); }
            for ($copy=1;$copy<3;$copy++) {
                $duplicate=$original->cloneNode(true);
                $destination=$xpath->query('./DstRect',$duplicate)->item(0);
                $destination->setAttribute('xOff',(string)((int)$rectangle->getAttribute('xOff')+$width*$copy));
                $original->parentNode->appendChild($duplicate);
            }
        }
        if (file_put_contents($path,$xml->saveXML())===false || !chmod($path,0600)) { throw new \RuntimeException('Map background storage is unavailable.',503); }
        return $this->sources[$key]=['path'=>$path,'projection'=>$projection];
    }

    private function translate(string $source,string $path): void
    {
        $process=proc_open(['gdal_translate','-q','-of','VRT',$source,$path],[['file','/dev/null','r'],['file','/dev/null','a'],['file','/dev/null','a']],$pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Map background metadata is unavailable.',503); }
        $deadline=hrtime(true)/1e9+3;
        try {
            do {
                $status=proc_get_status($process);clearstatcache(true,$path);
                if (hrtime(true)/1e9>=$deadline || (is_file($path) && filesize($path)>1048576)) { throw new \RuntimeException('Map background metadata exceeded its limit.',503); }
                if ($status['running']) { usleep(10000); }
            } while ($status['running']);
            if ($status['exitcode']!==0 || !is_file($path)) { throw new \RuntimeException('Map background metadata is unavailable.',503); }
        } finally { proc_terminate($process,9);proc_close($process); }
    }

    public function __destruct()
    {
        if ($this->directory!==null && is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') as $path) { unlink($path); }
            rmdir($this->directory);
        }
    }
}
