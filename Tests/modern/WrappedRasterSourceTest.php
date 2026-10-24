<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\WrappedRasterSource;

final class WrappedRasterSourceTest extends TestCase
{
    public function testGlobalRgbaPixelsRepeatWithoutChangingAlphaAndTemporarySourceIsRemoved(): void
    {
        $directory=sys_get_temp_dir().'/mappr-wrap-fixture-'.bin2hex(random_bytes(8));mkdir($directory,0700);
        try {
            $image=imagecreatetruecolor(4,2);imagealphablending($image,false);imagesavealpha($image,true);
            foreach ([[220,30,10,0],[20,210,30,20],[20,30,210,80],[0,0,0,127]] as $x=>$rgba) {
                for ($y=0;$y<2;$y++) { imagesetpixel($image,$x,$y,imagecolorallocatealpha($image,...$rgba)); }
            }
            imagepng($image,$directory.'/original.png');
            $this->translate(['-a_srs','EPSG:4326','-a_ullr','-180','90','180','-90',$directory.'/original.png',$directory.'/global.tif']);
            $wrapper=new WrappedRasterSource();$source=$wrapper->prepare($directory.'/global.tif',-96);
            self::assertNotNull($source);self::assertStringContainsString('+lon_wrap=-96',$source['projection']);
            self::assertSame(0600,fileperms($source['path'])&0777);self::assertSame(0700,fileperms(dirname($source['path']))&0777);
            $this->translate(['-of','PNG',$source['path'],$directory.'/repeated.png']);
            $repeated=imagecreatefrompng($directory.'/repeated.png');self::assertSame(12,imagesx($repeated));self::assertSame(2,imagesy($repeated));
            for ($x=0;$x<12;$x++) {
                self::assertSame(imagecolorsforindex($image,imagecolorat($image,$x%4,0)),imagecolorsforindex($repeated,imagecolorat($repeated,$x,0)));
            }
            imagedestroy($image);imagedestroy($repeated);$path=$source['path'];unset($wrapper);clearstatcache();self::assertFileDoesNotExist($path);self::assertDirectoryDoesNotExist(dirname($path));
        } finally {
            foreach (glob($directory.'/*') as $path) { unlink($path); }rmdir($directory);
        }
    }

    public function testRegionalRasterIsNotReinterpretedAsARepeatingWorld(): void
    {
        $directory=sys_get_temp_dir().'/mappr-regional-fixture-'.bin2hex(random_bytes(8));mkdir($directory,0700);
        try {
            $image=imagecreatetruecolor(4,2);imagepng($image,$directory.'/original.png');imagedestroy($image);
            $this->translate(['-a_srs','EPSG:4326','-a_ullr','-10','50','10','40',$directory.'/original.png',$directory.'/regional.tif']);
            self::assertNull((new WrappedRasterSource())->prepare($directory.'/regional.tif',10));
        } finally { foreach (glob($directory.'/*') as $path) { unlink($path); }rmdir($directory); }
    }

    private function translate(array $arguments): void
    {
        $process=proc_open(['gdal_translate','-q',...$arguments],[['file','/dev/null','r'],['file','/dev/null','a'],['file','/dev/null','a']],$pipes);
        self::assertIsResource($process);self::assertSame(0,proc_close($process));
    }
}
