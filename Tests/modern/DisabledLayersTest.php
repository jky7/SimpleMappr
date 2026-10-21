<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\Mappr\Application\Map;
use SimpleMappr\SavedMapDocument;

final class DisabledLayersTest extends TestCase
{
    private function fixture(array $layers): array
    {
        return ['width'=>600,'height'=>300,'projection'=>'epsg:4326','projection_map'=>'epsg:4326','bbox_map'=>'-100,30,-60,55','layers'=>$layers,'coords'=>[['title'=>'Toronto','data'=>'43.65,-79.38','color'=>'210 30 35','size'=>12]],'options'=>['legend'=>false]];
    }

    private function saved(array $layers): string
    {
        $document=SavedMapDocument::fromForm($this->fixture($layers));
        ob_start();
        try { (new Map(['title'=>'Layer flags','map'=>$document],'png'))->execute()->createOutput(); $bytes=ob_get_contents(); }
        finally { ob_end_clean(); }
        return $this->pixels($bytes);
    }

    private function pixels(string $bytes): string
    {
        $image=imagecreatefromstring($bytes);
        self::assertNotFalse($image);
        self::assertSame([600,300],[imagesx($image),imagesy($image)]);
        imagepalettetotruecolor($image);
        $digest=hash_init('sha256');
        for ($y=0;$y<300;$y++) {
            $row=[];
            for ($x=0;$x<600;$x++) { $row[]=imagecolorat($image,$x,$y); }
            hash_update($digest,pack('N*',...$row));
        }
        return hash_final($digest);
    }

    public function testDisabledSavedLayersHaveTheSameNativePixelsAsOmittedLayers(): void
    {
        $base=$this->saved(['countries'=>'on']);
        foreach ([false,0,'0','false','off',''] as $disabled) {
            self::assertSame($base,$this->saved(['countries'=>'on','grid'=>$disabled,'lakesOutline'=>$disabled]));
        }
        self::assertNotSame($base,$this->saved(['countries'=>'on','grid'=>'on']));
        self::assertNotSame($base,$this->saved(['countries'=>'on','lakesOutline'=>'on']));
    }

    public function testDisabledHttpLayersHaveTheSameDecodedPixelsAsOmittedLayers(): void
    {
        $base=rtrim(getenv('SIMPLEMAPPR_TEST_URL') ?: 'http://127.0.0.1','/');
        $render=function(array $layers) use ($base): string {
            $curl=curl_init($base.'/application');
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($this->fixture($layers)+['output'=>'png','download'=>1])]);
            $bytes=curl_exec($curl);self::assertSame(200,curl_getinfo($curl,CURLINFO_RESPONSE_CODE));self::assertNotFalse(imagecreatefromstring($bytes));
            return $this->pixels($bytes);
        };
        $omitted=$render(['countries'=>'on']);
        foreach (['0','false','off',''] as $disabled) { self::assertSame($omitted,$render(['countries'=>'on','grid'=>$disabled,'lakesOutline'=>$disabled])); }
        self::assertNotSame($omitted,$render(['countries'=>'on','grid'=>'on']));
        self::assertNotSame($omitted,$render(['countries'=>'on','lakesOutline'=>'on']));
    }
}
