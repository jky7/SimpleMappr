<?php
namespace SimpleMappr;

final class DetailedRelief
{
    public const HALF_WORLD = 20037508.342789244;
    public const CACHE = '/var/cache/simplemappr-relief';
    public const MAX_TILES = 256;
    public const MAX_NORTHING = 17821911.959436886;
    public const SOURCE = 'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/ASTER_GDEM_Greyscale_Shaded_Relief/default/GoogleMapsCompatible_Level12';
    private ?string $directory = null;

    public static function enabled(): bool
    {
        $value=getenv('SIMPLEMAPPR_DETAILED_RELIEF') ?: '0';
        if (!in_array($value,['0','1'],true)) { throw new \RuntimeException('SIMPLEMAPPR_DETAILED_RELIEF must be 0 or 1.'); }
        return $value==='1';
    }

    public static function plan(array $bounds,int $width,int $height): ?array
    {
        if (count($bounds)!==4 || count(array_filter($bounds,static fn($v)=>is_numeric($v) && is_finite((float)$v)))!==4) { throw new \InvalidArgumentException('Invalid detailed relief extent.'); }
        [$left,$bottom,$right,$top]=array_map('floatval',$bounds);
        if ($right<=$left || $top<=$bottom || $width<2 || $height<2) { throw new \InvalidArgumentException('Invalid detailed relief extent.'); }
        if (max($right-$left,$top-$bottom)>600000) { return null; }
        $bottom=max(-self::MAX_NORTHING,$bottom);$top=min(self::MAX_NORTHING,$top);
        if ($top<=$bottom) { return null; }
        $resolution=max(($right-$left)/$width,($top-$bottom)/$height);
        $zoom=max(0,min(12,(int)ceil(log(2*self::HALF_WORLD/(256*$resolution),2))));
        do {
            $count=1<<$zoom;$span=2*self::HALF_WORLD/$count;
            $x0=(int)floor(($left+self::HALF_WORLD)/$span);$x1=(int)ceil(($right+self::HALF_WORLD)/$span)-1;
            $y0=max(0,(int)floor((self::HALF_WORLD-$top)/$span));$y1=min($count-1,(int)ceil((self::HALF_WORLD-$bottom)/$span)-1);
            $tiles=($x1-$x0+1)*($y1-$y0+1);
            if ($tiles<=self::MAX_TILES) { break; }
            $zoom--;
        } while ($zoom>=0);
        if ($tiles<1 || $tiles>self::MAX_TILES) { throw new \InvalidArgumentException('Detailed relief extent exceeds the tile limit.'); }
        return ['zoom'=>$zoom,'x0'=>$x0,'x1'=>$x1,'y0'=>$y0,'y1'=>$y1];
    }

    public static function validatePlan(array $plan): array
    {
        if (array_keys($plan)!==['zoom','x0','x1','y0','y1'] || count(array_filter($plan,'is_int'))!==5) { throw new \InvalidArgumentException('Invalid relief tile plan.'); }
        extract($plan);
        if ($zoom<0 || $zoom>12 || $x0<-(1<<$zoom) || $x1>=(2<<$zoom) || $x1<$x0 || $y0<0 || $y1>=(1<<$zoom) || $y1<$y0 || ($x1-$x0+1)*($y1-$y0+1)>self::MAX_TILES) { throw new \InvalidArgumentException('Invalid relief tile plan.'); }
        return $plan;
    }

    public static function tileNames(array $plan): array
    {
        self::validatePlan($plan);$result=[];$count=1<<$plan['zoom'];
        for ($y=$plan['y0'];$y<=$plan['y1'];$y++) {
            for ($x=$plan['x0'];$x<=$plan['x1'];$x++) { $result[$x.','.$y]=$plan['zoom'].'-'.(($x%$count+$count)%$count).'-'.$y.'.jpeg'; }
        }
        return $result;
    }

    public function prepare(array $plan): ?string
    {
        self::validatePlan($plan);
        $deadline=hrtime(true)/1e9+15;
        $process=proc_open([PHP_BINDIR.'/php',ROOT.'/scripts/cache-detailed-relief'],[['pipe','r'],['pipe','w'],['file','/dev/null','a']],$pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Detailed relief is temporarily unavailable.',503); }
        fwrite($pipes[0],json_encode($plan,JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_blocking($pipes[1],false);
        $result='';
        try {
            while (true) {
                $result.=stream_get_contents($pipes[1]);
                if (strlen($result)>1024 || hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Detailed relief timed out. Please retry.',503); }
                if (!proc_get_status($process)['running']) { break; }
                usleep(10000);
            }
            $result.=stream_get_contents($pipes[1]);
            if (trim($result)!=='ready') { throw new \RuntimeException('Detailed relief is temporarily unavailable. Please retry.',503); }
        } finally { proc_terminate($process,9);fclose($pipes[1]);proc_close($process); }
        $this->directory=sys_get_temp_dir().'/simplemappr-relief-'.bin2hex(random_bytes(16));
        if (!mkdir($this->directory,0700)) { throw new \RuntimeException('Detailed relief storage is unavailable.',503); }
        $lock=fopen(self::CACHE.'/.lock','r');
        if (!$lock) { throw new \RuntimeException('Detailed relief cache is unavailable.',503); }
        try {
            while (!flock($lock,LOCK_SH|LOCK_NB)) {
                if (hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Detailed relief cache is busy. Please retry.',503); }
                usleep(10000);
            }
            foreach (array_unique(self::tileNames($plan)) as $name) {
                $path=self::CACHE.'/'.$name;
                if (hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Detailed relief timed out. Please retry.',503); }
                if (!is_file($path) || is_link($path) || filesize($path)>ReliefTileCache::MAX_BYTES || !copy($path,$this->directory.'/'.$name)) { throw new \RuntimeException('Detailed relief cache changed. Please retry.',503); }
            }
        } finally { flock($lock,LOCK_UN);fclose($lock); }
        $vrt=$this->vrt($plan,$deadline);
        if ($vrt===null) { return null; }
        $path=$this->directory.'/relief.vrt';
        if (file_put_contents($path,$vrt)===false) { throw new \RuntimeException('Detailed relief storage is unavailable.',503); }
        return $path;
    }

    private function vrt(array $plan,float $deadline): ?string
    {
        $tiles=[];
        foreach (self::tileNames($plan) as $position=>$name) {
            if (hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Detailed relief timed out. Please retry.',503); }
            if (!self::emptyTile($this->directory.'/'.$name)) { $tiles[$position]=$name; }
        }
        if (!$tiles) { return null; }
        $columns=$plan['x1']-$plan['x0']+1;$rows=$plan['y1']-$plan['y0']+1;
        $span=2*self::HALF_WORLD/(1<<$plan['zoom']);$pixel=$span/256;
        $left=-self::HALF_WORLD+$plan['x0']*$span;$top=self::HALF_WORLD-$plan['y0']*$span;
        $xml='<VRTDataset rasterXSize="'.($columns*256).'" rasterYSize="'.($rows*256).'"><SRS>EPSG:3857</SRS><GeoTransform>'.$left.','.$pixel.',0,'.$top.',0,-'.$pixel.'</GeoTransform>';
        for ($band=1;$band<=3;$band++) {
            $xml.='<VRTRasterBand dataType="Byte" band="'.$band.'"><NoDataValue>0</NoDataValue><ColorInterp>'.['Red','Green','Blue'][$band-1].'</ColorInterp>';
            foreach ($tiles as $position=>$name) {
                [$x,$y]=array_map('intval',explode(',',$position));
                $xml.='<SimpleSource><SourceFilename relativeToVRT="1">'.$name.'</SourceFilename><SourceBand>'.$band.'</SourceBand><SrcRect xOff="0" yOff="0" xSize="256" ySize="256"/><DstRect xOff="'.(($x-$plan['x0'])*256).'" yOff="'.(($y-$plan['y0'])*256).'" xSize="256" ySize="256"/></SimpleSource>';
            }
            $xml.='</VRTRasterBand>';
        }
        return $xml.'</VRTDataset>';
    }

    public static function emptyTile(string $path): bool
    {
        $image=@imagecreatefromjpeg($path);
        if (!$image) { throw new \RuntimeException('Detailed relief cache contains an invalid tile.',503); }
        try {
            for ($y=0;$y<256;$y++) { for ($x=0;$x<256;$x++) { if ((imagecolorat($image,$x,$y)&0xffffff)!==0x585858) { return false; } } }
            return true;
        } finally { imagedestroy($image); }
    }

    public function __destruct()
    {
        if ($this->directory!==null && is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') as $path) { unlink($path); }
            rmdir($this->directory);
        }
    }
}
