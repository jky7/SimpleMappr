<?php
namespace SimpleMappr;

class ReliefTileCache
{
    public const MAX_BYTES=262144;
    public const BUDGET=1073741824;

    public function fill(array $plan,string $directory=DetailedRelief::CACHE,array $sources=['grey']): void
    {
        DetailedRelief::validatePlan($plan);$deadline=hrtime(true)/1e9+14.5;
        if (!is_dir($directory) || is_link($directory)) { throw new \RuntimeException('Relief cache is unavailable.'); }
        $lock=fopen($directory.'/.lock','c');
        if (!$lock) { throw new \RuntimeException('Relief cache is unavailable.'); }
        try {
            while (!flock($lock,LOCK_EX|LOCK_NB)) {
                if (hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Relief cache is busy.'); }
                usleep(10000);
            }
            foreach (glob($directory.'/.partial-*') as $path) { unlink($path); }
            $names=[];
            foreach (array_unique($sources) as $source) { array_push($names,...array_values(DetailedRelief::tileNames($plan,$source))); }
            $names=array_values(array_unique($names));$missing=[];
            foreach ($names as $name) {
                $path=$directory.'/'.$name;
                if (is_file($path) && !is_link($path) && filesize($path)<=self::MAX_BYTES && self::validTile($name,file_get_contents($path))) { touch($path); }
                else { $missing[]=$name; }
            }
            $this->download($missing,$directory,$names,$deadline);
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }

    public static function valid(string $bytes): bool
    {
        if (strlen($bytes)>self::MAX_BYTES || !str_starts_with($bytes,"\xff\xd8") || !str_ends_with($bytes,"\xff\xd9")) { return false; }
        $size=@getimagesizefromstring($bytes);
        if (!$size || $size[0]!==256 || $size[1]!==256 || $size[2]!==IMAGETYPE_JPEG || ($size['channels'] ?? 0)!==3) { return false; }
        $image=@imagecreatefromstring($bytes);
        if (!$image) { return false; }
        imagedestroy($image);return true;
    }

    /** Validate a tile by the kind its name says it is. */
    public static function validTile(string $name,string $bytes): bool
    {
        return str_starts_with($name,'index-')?self::validIndex($bytes):self::valid($bytes);
    }

    /**
     * An elevation index tile is a 256x256 palette PNG. Land tiles carry the published key's palette in order;
     * open-ocean tiles are blank (every pixel index 0) whatever their palette.
     */
    public static function validIndex(string $bytes): bool
    {
        if (strlen($bytes)>self::MAX_BYTES || !str_starts_with($bytes,"\x89PNG\r\n\x1a\n")) { return false; }
        $size=@getimagesizefromstring($bytes);
        if (!$size || $size[0]!==256 || $size[1]!==256 || $size[2]!==IMAGETYPE_PNG) { return false; }
        $image=@imagecreatefromstring($bytes);
        if (!$image) { return false; }
        try {
            if (imageistruecolor($image) || imagecolorstotal($image)!==256) { return false; }
            $blank=true;
            for ($y=0;$y<256 && $blank;$y++) { for ($x=0;$x<256;$x++) { if (imagecolorat($image,$x,$y)!==0) { $blank=false;break; } } }
            if ($blank) { return true; }
            foreach (DetailedRelief::colorIndex() as $index=>$entry) {
                $colour=imagecolorsforindex($image,$index);
                if ([$colour['red'],$colour['green'],$colour['blue']]!==$entry['rgb']) { return false; }
            }
            return true;
        } finally { imagedestroy($image); }
    }

    protected function download(array $names,string $directory,array $protected,float $deadline): void
    {
        $multi=curl_multi_init();$active=[];
        try {
            while ($names || $active) {
                if (hrtime(true)/1e9>=$deadline) { throw new \RuntimeException('Relief download timed out.'); }
                while ($names && count($active)<4) {
                    $name=array_shift($names);$source=DetailedRelief::SOURCES[str_starts_with($name,'index-')?'index':'grey'];
                    [$z,$x,$y]=array_map('intval',explode('-',substr(pathinfo($name,PATHINFO_FILENAME),strlen($source['prefix']))));
                    $handle=curl_init($source['url'].'/'.$z.'/'.$y.'/'.$x.'.'.$source['extension']);
                    $state=(object)['name'=>$name,'body'=>'','headers'=>0];
                    curl_setopt_array($handle,[CURLOPT_PROTOCOLS_STR=>'https',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>3000,CURLOPT_TIMEOUT_MS=>max(1,(int)(($deadline-hrtime(true)/1e9)*1000)),CURLOPT_USERAGENT=>'SimpleMappr detailed relief',CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use($state):int{$state->headers+=strlen($line);return $state->headers<=16384?strlen($line):0;},CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use($state):int{if(strlen($state->body)+strlen($chunk)>self::MAX_BYTES){return 0;}$state->body.=$chunk;return strlen($chunk);}]);
                    curl_multi_add_handle($multi,$handle);$active[spl_object_id($handle)]=[$handle,$state];
                }
                do { $status=curl_multi_exec($multi,$running); } while ($status===CURLM_CALL_MULTI_PERFORM);
                if ($status!==CURLM_OK) { throw new \RuntimeException('Relief download failed.'); }
                while ($info=curl_multi_info_read($multi)) {
                    $handle=$info['handle'];[, $state]=$active[spl_object_id($handle)];
                    if ($info['result']!==CURLE_OK || curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200 || !self::validTile($state->name,$state->body)) { throw new \RuntimeException('Relief source returned an invalid tile.'); }
                    $this->publish($directory,$state->name,$state->body,$protected);
                    curl_multi_remove_handle($multi,$handle);unset($active[spl_object_id($handle)]);
                }
                if ($active) { curl_multi_select($multi,0.05); }
            }
        } finally { foreach ($active as [$handle]) { curl_multi_remove_handle($multi,$handle); }curl_multi_close($multi); }
    }

    public function publish(string $directory,string $name,string $bytes,array $protected): void
    {
        if (!preg_match('/^(?:(?:[0-9]|1[0-2])-[0-9]+-[0-9]+\.jpeg|index-(?:[0-9]|1[0-2])-[0-9]+-[0-9]+\.png)$/D',$name) || !self::validTile($name,$bytes)) { throw new \InvalidArgumentException('Invalid relief tile.'); }
        $files=[];$total=0;
        foreach (array_merge(glob($directory.'/*.jpeg'),glob($directory.'/*.png')) as $path) {
            if (is_link($path)) { throw new \RuntimeException('Unsafe relief cache.'); }
            $total+=filesize($path);$files[$path]=filemtime($path);
        }
        asort($files,SORT_NUMERIC);
        foreach ($files as $path=>$mtime) {
            if ($total+strlen($bytes)<=self::BUDGET) { break; }
            if (in_array(basename($path),$protected,true)) { continue; }
            $total-=filesize($path);unlink($path);
        }
        if ($total+strlen($bytes)>self::BUDGET) { throw new \RuntimeException('Relief cache is full.'); }
        $temporary=$directory.'/.partial-'.bin2hex(random_bytes(16));
        try {
            if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes) || !rename($temporary,$directory.'/'.$name)) { throw new \RuntimeException('Relief cache write failed.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}
