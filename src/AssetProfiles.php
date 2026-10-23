<?php
namespace SimpleMappr;

final class AssetProfiles
{
    public static function registry(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/config/asset-profiles/index.json'),true,32,JSON_THROW_ON_ERROR);
    }
    public static function selected(?string $name = null): array
    {
        $name ??= getenv('SIMPLEMAPPR_ASSET_PROFILE') ?: 'minimal-vector-v1';
        $profile=self::registry()['profiles'][$name] ?? null;
        if (!$profile) { throw new \RuntimeException('Unknown SIMPLEMAPPR_ASSET_PROFILE. Choose minimal-vector-v1, extended-vector-v1, gray-relief-v1 or full-map-v1.'); }
        return ['name'=>$name]+$profile;
    }
    public static function configuration(): string { return dirname(__DIR__).'/'.self::selected()['configuration']; }
    public static function availability(): array
    {
        $root=dirname(__DIR__);$profile=self::selected();$datasets=[];
        foreach ($profile['manifests'] as $entry) {
            $manifest=json_decode(file_get_contents($root.'/'.$entry['path']),true,512,JSON_THROW_ON_ERROR);
            foreach ($manifest['assets'] as $asset) {
                $bytes=0;$installed=true;
                foreach ($asset['components'] as $component) {
                    $path=$root.'/mapserver/maps/'.$asset['destination'].'/'.$component['name'];
                    if (!is_file($path) || is_link($path) || hash_file('sha256',$path)!==$component['sha256']) { $installed=false; }
                    if (is_file($path)) { $bytes+=filesize($path); }
                }
                $datasets[]=['name'=>$asset['basename'],'version'=>$asset['version'],'installed'=>$installed,'bytes'=>$bytes,'source'=>$asset['url'] ?? $asset['source'],'license'=>$asset['license']];
            }
        }
        $excluded=self::registry()['excluded'];
        if ($profile['name']==='minimal-vector-v1') {
            foreach (['rivers','oceans','roads','railroads','rivernames','placenames','physicalLabels','marineLabels'] as $key) { $excluded[$key]='Install the extended-vector-v1, gray-relief-v1 or full-map-v1 profile.'; }
        }
        if (!in_array($profile['name'],['gray-relief-v1','full-map-v1'],true)) { $excluded['reliefgrey']='Install the gray-relief-v1 profile. This is medium-resolution Gray Earth, not the archived custom raster.'; }
        if ($profile['name']!=='full-map-v1') {
            foreach (['relief','reliefalt','blueMarble'] as $key) { $excluded[$key]='Install the full-map-v1 profile.'; }
        }
        return ['schemaVersion'=>1,'profile'=>$profile['name'],'datasets'=>$datasets,'excluded'=>$excluded];
    }
}
