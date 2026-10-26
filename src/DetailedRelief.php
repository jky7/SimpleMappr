<?php
namespace SimpleMappr;

final class DetailedRelief
{
    public const HALF_WORLD = 20037508.342789244;
    public const CACHE = '/var/cache/simplemappr-relief';
    public const MAX_TILES = 256;
    public const MAX_NORTHING = 17821911.959436886;
    public const SOURCE = 'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/ASTER_GDEM_Greyscale_Shaded_Relief/default/GoogleMapsCompatible_Level12';
    public const INDEX_SOURCE = 'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/ASTER_GDEM_Color_Index/default/GoogleMapsCompatible_Level12';
    public const LANDSAT_SOURCE = 'https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/Landsat_WELD_CorrectedReflectance_TrueColor_Global_Annual/default/2000-12-01/GoogleMapsCompatible_Level12';
    // Grey shaded relief tiles keep their original cache names; the other sources are prefixed beside them.
    public const SOURCES = ['grey'=>['url'=>self::SOURCE,'prefix'=>'','extension'=>'jpeg'],'index'=>['url'=>self::INDEX_SOURCE,'prefix'=>'index-','extension'=>'png'],'landsat'=>['url'=>self::LANDSAT_SOURCE,'prefix'=>'landsat-','extension'=>'jpeg']];
    // Blue Marble's flat ocean colour, used for all water under the detailed imagery.
    public const WATER = [10,10,51];
    // Elevation tints (metres => RGB) for the colour relief, close to Natural Earth's cross-blended hypsometric tints.
    public const TINTS = [[0,[132,174,163]],[300,[154,194,179]],[700,[177,207,196]],[1200,[229,225,213]],[1800,[214,200,184]],[2400,[198,178,159]],[3000,[226,222,215]],[4000,[246,246,246]]];
    private ?string $directory = null;
    private static ?array $colorIndex = null;

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

    public static function tileNames(array $plan,string $source='grey'): array
    {
        self::validatePlan($plan);$result=[];$count=1<<$plan['zoom'];
        if (!isset(self::SOURCES[$source])) { throw new \InvalidArgumentException('Unknown relief tile source.'); }
        ['prefix'=>$prefix,'extension'=>$extension]=self::SOURCES[$source];
        for ($y=$plan['y0'];$y<=$plan['y1'];$y++) {
            for ($x=$plan['x0'];$x<=$plan['x1'];$x++) { $result[$x.','.$y]=$prefix.$plan['zoom'].'-'.(($x%$count+$count)%$count).'-'.$y.'.'.$extension; }
        }
        return $result;
    }

    /** The published ASTER_GDEM_Color_Index key: palette index => [rgb, low, high, nodata]. */
    public static function colorIndex(): array
    {
        if (self::$colorIndex===null) {
            $document=json_decode(file_get_contents(dirname(__DIR__).'/config/aster-gdem-color-index.json'),true,8,JSON_THROW_ON_ERROR);
            if (!is_array($document['entries'] ?? null) || count($document['entries'])!==256) { throw new \RuntimeException('The elevation colour key is invalid.'); }
            self::$colorIndex=$document['entries'];
        }
        return self::$colorIndex;
    }

    public static function tint(float $elevation): array
    {
        if ($elevation<=self::TINTS[0][0]) { return self::TINTS[0][1]; }
        for ($i=1;$i<count(self::TINTS);$i++) {
            [$high,$to]=self::TINTS[$i];
            if ($elevation<=$high) {
                [$low,$from]=self::TINTS[$i-1];$t=($elevation-$low)/($high-$low);
                return array_map(static fn($a,$b)=>(int)round($a+($b-$a)*$t),$from,$to);
            }
        }
        return self::TINTS[count(self::TINTS)-1][1];
    }

    /** Palette index => [r, g, b, alpha]: each elevation band painted with its tint, index 0 (no data) transparent. */
    public static function tintTable(): array
    {
        $table=[];
        foreach (self::colorIndex() as $index=>$entry) {
            if ($entry['nodata']) { $table[$index]=[0,0,0,0];continue; }
            $high=$entry['high'] ?? $entry['low']+200;
            $table[$index]=[...self::tint(($entry['low']+$high)/2),255];
        }
        return $table;
    }

    public static function geographicClips(array $plan): array
    {
        self::validatePlan($plan);$count=1<<$plan['zoom'];
        $west=$plan['x0']/$count*360-180;$east=($plan['x1']+1)/$count*360-180;
        $south=rad2deg(atan(sinh(pi()*(1-2*($plan['y1']+1)/$count))));
        $north=rad2deg(atan(sinh(pi()*(1-2*$plan['y0']/$count))));
        $clips=[];
        for ($world=-1;$world<=1;$world++) {
            $left=max(-180,$west-360*$world);$right=min(180,$east-360*$world);
            if ($right>$left) { $clips[]=[$left,$south,$right,$north]; }
        }
        return $clips;
    }

    /**
     * Fetch (or reuse) the plan's tiles for each source and build private mosaics. Returns source => VRT path,
     * or null for a source whose tiles are all empty (open ocean).
     */
    public function prepare(array $plan,array $sources=['grey']): array
    {
        self::validatePlan($plan);
        $sources=array_values(array_unique($sources));
        foreach ($sources as $source) { if (!isset(self::SOURCES[$source])) { throw new \InvalidArgumentException('Unknown relief tile source.'); } }
        $deadline=hrtime(true)/1e9+15;
        $process=proc_open([PHP_BINDIR.'/php',ROOT.'/scripts/cache-detailed-relief'],[['pipe','r'],['pipe','w'],['file','/dev/null','a']],$pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Detailed relief is temporarily unavailable.',503); }
        fwrite($pipes[0],json_encode(['plan'=>$plan,'sources'=>$sources],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_blocking($pipes[1],false);
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
            foreach ($sources as $source) {
                foreach (array_unique(self::tileNames($plan,$source)) as $name) {
                    $path=self::CACHE.'/'.$name;
                    if (hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Detailed relief timed out. Please retry.',503); }
                    if (!is_file($path) || is_link($path) || filesize($path)>ReliefTileCache::MAX_BYTES || !copy($path,$this->directory.'/'.$name)) { throw new \RuntimeException('Detailed relief cache changed. Please retry.',503); }
                }
            }
        } finally { flock($lock,LOCK_UN);fclose($lock); }
        $paths=[];
        foreach ($sources as $source) {
            $vrt=$source==='index'?self::tintMosaic($plan,$this->directory,$deadline):self::mosaic($plan,$this->directory,$deadline,$source);
            $paths[$source]=null;
            if ($vrt===null) { continue; }
            $path=$this->directory.'/'.$source.'.vrt';
            if (file_put_contents($path,$vrt)===false) { throw new \RuntimeException('Detailed relief storage is unavailable.',503); }
            $paths[$source]=$path;
        }
        return $paths;
    }

    /** RGBA mosaic of JPEG tiles. Grey relief is opaque; Landsat's black no-data pixels become transparent. */
    public static function mosaic(array $plan,string $directory,float $deadline,string $source='grey'): ?string
    {
        $tiles=[];$landsat=$source==='landsat';
        foreach (self::tileNames($plan,$source) as $position=>$name) {
            if (hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Detailed relief timed out. Please retry.',503); }
            if (!self::emptyTile($directory.'/'.$name,$landsat?0:0x585858)) { $tiles[$position]=$name; }
        }
        if (!$tiles) { return null; }
        $columns=$plan['x1']-$plan['x0']+1;$rows=$plan['y1']-$plan['y0']+1;
        $span=2*self::HALF_WORLD/(1<<$plan['zoom']);$pixel=$span/256;
        $left=-self::HALF_WORLD+$plan['x0']*$span;$top=self::HALF_WORLD-$plan['y0']*$span;
        $xml='<VRTDataset rasterXSize="'.($columns*256).'" rasterYSize="'.($rows*256).'"><SRS>EPSG:3857</SRS><GeoTransform>'.$left.','.$pixel.',0,'.$top.',0,-'.$pixel.'</GeoTransform>';
        for ($band=1;$band<=4;$band++) {
            $xml.='<VRTRasterBand dataType="Byte" band="'.$band.'"><ColorInterp>'.['Red','Green','Blue','Alpha'][$band-1].'</ColorInterp>';
            foreach ($tiles as $position=>$name) {
                [$x,$y]=array_map('intval',explode(',',$position));
                $element=$band===4?'ComplexSource':'SimpleSource';
                $alpha=$landsat?'<LUT>0:0,6:0,7:255,255:255</LUT>':'<ScaleOffset>255</ScaleOffset><ScaleRatio>0</ScaleRatio>';
                $xml.='<'.$element.'><SourceFilename relativeToVRT="1">'.$name.'</SourceFilename><SourceBand>'.($band===4?($landsat?1:3):$band).'</SourceBand><SrcRect xOff="0" yOff="0" xSize="256" ySize="256"/><DstRect xOff="'.(($x-$plan['x0'])*256).'" yOff="'.(($y-$plan['y0'])*256).'" xSize="256" ySize="256"/>'.($band===4?$alpha:'').'</'.$element.'>';
            }
            $xml.='</VRTRasterBand>';
        }
        return $xml.'</VRTDataset>';
    }

    /** A JPEG tile whose every pixel is the source's blank colour (grey relief's flat sea, Landsat's black). */
    public static function emptyTile(string $path,int $blank=0x585858): bool
    {
        $image=@imagecreatefromjpeg($path);
        if (!$image) { throw new \RuntimeException('Detailed relief cache contains an invalid tile.',503); }
        try {
            for ($y=0;$y<256;$y++) { for ($x=0;$x<256;$x++) { if ((imagecolorat($image,$x,$y)&0xffffff)!==$blank) { return false; } } }
            return true;
        } finally { imagedestroy($image); }
    }

    /** RGBA mosaic of elevation index tiles: GDAL maps each palette index to its tint, so no pixel is touched in PHP. */
    public static function tintMosaic(array $plan,string $directory,float $deadline): ?string
    {
        $tiles=[];
        foreach (self::tileNames($plan,'index') as $position=>$name) {
            if (hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Detailed relief timed out. Please retry.',503); }
            if (!self::emptyIndexTile($directory.'/'.$name)) { $tiles[$position]=$name; }
        }
        if (!$tiles) { return null; }
        $table=self::tintTable();
        $columns=$plan['x1']-$plan['x0']+1;$rows=$plan['y1']-$plan['y0']+1;
        $span=2*self::HALF_WORLD/(1<<$plan['zoom']);$pixel=$span/256;
        $left=-self::HALF_WORLD+$plan['x0']*$span;$top=self::HALF_WORLD-$plan['y0']*$span;
        $xml='<VRTDataset rasterXSize="'.($columns*256).'" rasterYSize="'.($rows*256).'"><SRS>EPSG:3857</SRS><GeoTransform>'.$left.','.$pixel.',0,'.$top.',0,-'.$pixel.'</GeoTransform>';
        foreach (['Red','Green','Blue','Alpha'] as $component=>$interpretation) {
            $lut=implode(',',array_map(static fn($index,$rgba)=>$index.':'.$rgba[$component],array_keys($table),$table));
            $xml.='<VRTRasterBand dataType="Byte" band="'.($component+1).'"><ColorInterp>'.$interpretation.'</ColorInterp>';
            foreach ($tiles as $position=>$name) {
                [$x,$y]=array_map('intval',explode(',',$position));
                $xml.='<ComplexSource><SourceFilename relativeToVRT="1">'.$name.'</SourceFilename><SourceBand>1</SourceBand><SrcRect xOff="0" yOff="0" xSize="256" ySize="256"/><DstRect xOff="'.(($x-$plan['x0'])*256).'" yOff="'.(($y-$plan['y0'])*256).'" xSize="256" ySize="256"/><LUT>'.$lut.'</LUT></ComplexSource>';
            }
            $xml.='</VRTRasterBand>';
        }
        return $xml.'</VRTDataset>';
    }

    /** An index tile with no land: every pixel is palette index 0. */
    public static function emptyIndexTile(string $path): bool
    {
        $image=@imagecreatefrompng($path);
        if (!$image || imageistruecolor($image)) { throw new \RuntimeException('Detailed relief cache contains an invalid tile.',503); }
        try {
            for ($y=0;$y<256;$y++) { for ($x=0;$x<256;$x++) { if (imagecolorat($image,$x,$y)!==0) { return false; } } }
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
