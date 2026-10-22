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
