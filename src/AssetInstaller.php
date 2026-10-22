<?php
namespace SimpleMappr;

final class AssetInstaller
{
    private static function relative(string $path): void
    {
        if (!preg_match('~^[A-Za-z0-9_./-]+$~D',$path) || str_starts_with($path,'/') || array_intersect(['..','.'],explode('/',$path))) { throw new \RuntimeException('Unsafe asset path.'); }
    }
    public static function install(array $manifest,string $archiveRoot,string $dataRoot,bool $check): int
    {
        if (($manifest['schemaVersion'] ?? null)!==1 || !is_array($manifest['assets'] ?? null) || is_link($dataRoot) || !is_dir($dataRoot)) { throw new \RuntimeException('Invalid asset manifest or destination.'); }
        $count=0;
        foreach ($manifest['assets'] as $asset) {
            self::relative($asset['archive']);self::relative($asset['destination']);self::relative($asset['basename']);
            $destination=$dataRoot;
            foreach (explode('/',$asset['destination']) as $segment) {
                $destination.='/'.$segment;
                if (is_link($destination) || (!is_dir($destination) && ($check || !mkdir($destination,0755)))) { throw new \RuntimeException('Missing or unsafe asset directory.'); }
            }
            $zip=null;
            if (!$check) {
                $archive=$archiveRoot.'/'.$asset['archive'];
                if (is_link($archive) || !is_file($archive) || hash_file('sha256',$archive)!==$asset['archiveSha256'] || isset($asset['archiveBytes']) && filesize($archive)!==$asset['archiveBytes']) { throw new \RuntimeException('Missing or corrupt archive: '.$asset['archive']); }
                $zip=new \ZipArchive();if ($zip->open($archive)!==true) { throw new \RuntimeException('Cannot open asset archive.'); }
                $expected=array_column($asset['components'],'name');$actual=[];
                for ($i=0;$i<$zip->numFiles;$i++) { $actual[]=$zip->getNameIndex($i); }
                sort($expected);sort($actual);if ($expected!==$actual) { throw new \RuntimeException('Archive members differ from manifest.'); }
            }
            try {
                foreach ($asset['components'] as $component) {
                    $name=$component['name'];
                    if (basename($name)!==$name || !str_starts_with($name,$asset['basename'].'.') || !preg_match('/^[0-9a-f]{64}$/D',$component['sha256'])) { throw new \RuntimeException('Unsafe asset member.'); }
                    $target=$destination.'/'.$name;
                    if (is_link($target)) { throw new \RuntimeException('Asset cannot be a symlink.'); }
                    if (is_file($target)) {
                        if (hash_file('sha256',$target)!==$component['sha256'] || isset($component['bytes']) && filesize($target)!==$component['bytes']) { throw new \RuntimeException('Corrupt installed asset: '.$name); }
                    } elseif ($check) { throw new \RuntimeException('Missing asset: '.$name); }
                    else {
                        $input=$zip->getStream($name);$temporary=tempnam($destination,'.install-');
                        if ($input===false || $temporary===false) { throw new \RuntimeException('Cannot stage asset.'); }
                        try {
                            $output=fopen($temporary,'wb');if ($output===false) { throw new \RuntimeException('Cannot open staged asset.'); }
                            $copied=stream_copy_to_stream($input,$output);fclose($input);
                            if ($copied===false || !fflush($output) || !fsync($output) || !fclose($output) || isset($component['bytes']) && $copied!==$component['bytes'] || hash_file('sha256',$temporary)!==$component['sha256'] || !chmod($temporary,0644) || !rename($temporary,$target)) { throw new \RuntimeException('Asset extraction or verification failed: '.$name); }
                        } finally { if (is_file($temporary)) { unlink($temporary); } }
                    }
                    $count++;
                }
            } finally { if ($zip) { $zip->close(); } }
        }
        return $count;
    }
}
