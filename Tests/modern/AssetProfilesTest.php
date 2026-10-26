<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\AssetProfiles;
use SimpleMappr\AssetInstaller;

final class AssetProfilesTest extends TestCase
{
    public function testProfilesHaveDeclaredConfigurationAndInventories(): void
    {
        $registry=AssetProfiles::registry();self::assertSame(1,$registry['schemaVersion']);
        foreach ($registry['profiles'] as $name=>$profile) {
            self::assertFileExists(ROOT.'/'.$profile['configuration']);
            foreach ($profile['manifests'] as $entry) { self::assertSame(1,json_decode(file_get_contents(ROOT.'/'.$entry['path']),true)['schemaVersion']); }
            self::assertSame($name,AssetProfiles::selected($name)['name']);
        }
        $this->expectException(RuntimeException::class);AssetProfiles::selected('unknown');
    }
    public function testRawFileInstallationAndBoundaryRefusals(): void
    {
        $root=sys_get_temp_dir().'/mappr-raw-assets-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/data');
        $bytes='verified raw raster fixture';file_put_contents($root.'/fixture.tif',$bytes);
        $component=['name'=>'fixture.tif','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
        $asset=['format'=>'file','archive'=>'fixture.tif','archiveBytes'=>strlen($bytes),'archiveSha256'=>$component['sha256'],'destination'=>'raster','basename'=>'fixture','components'=>[$component]];
        $manifest=['schemaVersion'=>1,'assets'=>[$asset]];
        try {
            self::assertSame(1,AssetInstaller::install($manifest,$root,$root.'/data',false));
            self::assertSame($bytes,file_get_contents($root.'/data/raster/fixture.tif'));
            self::assertSame(1,AssetInstaller::install($manifest,$root,$root.'/data',false));
            self::assertSame(1,AssetInstaller::install($manifest,$root,$root.'/data',true));
            foreach (['component hash','source path','destination path','extra component','unbounded bytes','corrupt source'] as $invalid) {
                $candidate=$asset;
                switch ($invalid) {
                    case 'component hash': $candidate['components'][0]['sha256']=str_repeat('0',64);break;
                    case 'source path': $candidate['archive']='../fixture.tif';break;
                    case 'destination path': $candidate['destination']='../escaped';break;
                    case 'extra component': $candidate['components'][]=$component;break;
                    case 'unbounded bytes': $candidate['archiveBytes']=536870913;$candidate['components'][0]['bytes']=536870913;break;
                    case 'corrupt source': file_put_contents($root.'/fixture.tif','corrupt');break;
                }
                try { AssetInstaller::install(['schemaVersion'=>1,'assets'=>[$candidate]],$root,$root.'/data',false);self::fail('Accepted '.$invalid); }
                catch (RuntimeException) { self::assertSame($bytes,file_get_contents($root.'/data/raster/fixture.tif')); }
            }
            self::assertDirectoryDoesNotExist($root.'/escaped');
            file_put_contents($root.'/data/raster/fixture.tif','corrupt');
            try { AssetInstaller::install($manifest,$root,$root.'/data',true);self::fail('Accepted corrupt installed raw raster.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('Corrupt installed asset',$error->getMessage()); }
        } finally {
            unlink($root.'/data/raster/fixture.tif');rmdir($root.'/data/raster');rmdir($root.'/data');unlink($root.'/fixture.tif');rmdir($root);
        }
    }
    public function testFullRasterProfileHasPinnedColorAndBlueMarbleSources(): void
    {
        $profile=AssetProfiles::selected('full-map-v1');
        $assets=[];
        foreach ($profile['manifests'] as $entry) {
            foreach (json_decode(file_get_contents(ROOT.'/'.$entry['path']),true)['assets'] as $asset) { $assets[$asset['basename']]=$asset; }
        }
        foreach (['HYP_HR_SR_OB_DR','NE2_HR_LC_SR_W_DR','GRAY_HR_SR_OB_DR','land_shallow_topo_21600'] as $basename) {
            self::assertArrayHasKey($basename,$assets);
            self::assertLessThanOrEqual(536870912,$assets[$basename]['archiveBytes']);
            self::assertSame($assets[$basename]['extractedBytes'],array_sum(array_column($assets[$basename]['components'],'bytes')));
        }
        $marble=$assets['land_shallow_topo_21600'];
        self::assertSame('file',$marble['format']);
        self::assertSame($marble['archiveSha256'],$marble['components'][0]['sha256']);
        $vrt=simplexml_load_file(ROOT.'/config/blue-marble.vrt');
        self::assertSame('21600',(string)$vrt['rasterXSize']);
        self::assertSame('10800',(string)$vrt['rasterYSize']);
        self::assertCount(3,$vrt->VRTRasterBand);
        foreach ($vrt->VRTRasterBand as $band) { self::assertSame('../mapserver/maps/blueMarble/land_shallow_topo_21600.tif',(string)$band->SimpleSource->SourceFilename); }
    }
    public function testInstallationIsIdempotentAndRejectsCorruptOrLinkedMembers(): void
    {
        $root=sys_get_temp_dir().'/mappr-assets-'.bin2hex(random_bytes(8));mkdir($root);mkdir($root.'/data');
        $zip=new ZipArchive();$zip->open($root.'/fixture.zip',ZipArchive::CREATE);$zip->addFromString('fixture.dbf','verified fixture');$zip->close();
        $manifest=['schemaVersion'=>1,'assets'=>[['archive'=>'fixture.zip','archiveSha256'=>hash_file('sha256',$root.'/fixture.zip'),'destination'=>'physical','basename'=>'fixture','components'=>[['name'=>'fixture.dbf','sha256'=>hash('sha256','verified fixture')]]]]];
        try {
            self::assertSame(1,AssetInstaller::install($manifest,$root,$root.'/data',false));
            self::assertSame(1,AssetInstaller::install($manifest,$root,$root.'/data',false));
            self::assertSame(1,AssetInstaller::install($manifest,$root,$root.'/data',true));
            unlink($root.'/data/physical/fixture.dbf');symlink($root.'/fixture.zip',$root.'/data/physical/fixture.dbf');
            try { AssetInstaller::install($manifest,$root,$root.'/data',true);self::fail('Linked asset was accepted.'); } catch(RuntimeException $error) { self::assertStringContainsString('symlink',$error->getMessage()); }
            unlink($root.'/data/physical/fixture.dbf');file_put_contents($root.'/data/physical/fixture.dbf','corrupt');
            $this->expectException(RuntimeException::class);AssetInstaller::install($manifest,$root,$root.'/data',true);
        } finally {
            unlink($root.'/data/physical/fixture.dbf');rmdir($root.'/data/physical');rmdir($root.'/data');unlink($root.'/fixture.zip');rmdir($root);
        }
    }
}
