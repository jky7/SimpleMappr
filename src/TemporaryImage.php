<?php
namespace SimpleMappr;

final class TemporaryImage
{
    private const PRIVATE_DIRECTORY = '/var/lib/simplemappr/previews';
    private const LIFETIME = 3600;

    private static array $written = [];

    public static function write(\imageObj $image, \mapObj $map, string $extension = 'png'): array
    {
        return self::store($extension, static fn (string $path) => $image->save($path, $map));
    }

    public static function writeFile(string $source, string $extension = 'png'): array
    {
        return self::store($extension, static function (string $path) use ($source) {
            if (!copy($source, $path)) { throw new \RuntimeException('Preview storage is unavailable.'); }
        });
    }

    /** Paths written during this request, keyed by the URL returned to the caller. */
    public static function written(): array
    {
        return self::$written;
    }

    private static function store(string $extension, callable $save): array
    {
        if (!in_array($extension, ['png','jpg','svg','tif'], true)) { throw new \InvalidArgumentException('Unsupported temporary image format.'); }
        $owner = Session::currentUser();
        $token = bin2hex(random_bytes(16));
        $name = 'preview-'.$token.'.'.$extension;
        $directory = $owner ? self::PRIVATE_DIRECTORY : ROOT.'/public/tmp';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Preview storage is unavailable.'); }
        $path = $directory.'/'.$name;
        $save($path);
        if ($owner) {
            chmod($path, 0600);
            file_put_contents(self::PRIVATE_DIRECTORY.'/'.$token.'.json', json_encode(['uid'=>(int)$owner->uid,'expires'=>time()+self::LIFETIME,'filename'=>$name]), LOCK_EX);
            $url = MAPPR_URL.'/_preview/'.$token.'.'.$extension;
        } else { $url = MAPPR_MAPS_URL.$name; }
        self::cleanExpired();
        self::$written[$url] = $path;
        return ['path'=>$path,'url'=>$url];
    }

    public static function read(string $token, string $extension): string
    {
        $owner = Session::currentUser();
        if (!$owner || !preg_match('/^[0-9a-f]{32}$/D', $token) || !in_array($extension,['png','jpg','svg','tif'],true)) { throw new HttpException(404,'Preview not found.'); }
        $metadata = self::PRIVATE_DIRECTORY.'/'.$token.'.json';
        $record = is_file($metadata) ? json_decode(file_get_contents($metadata),true) : null;
        $path = self::PRIVATE_DIRECTORY.'/preview-'.$token.'.'.$extension;
        if (!$record || $record['uid'] !== (int)$owner->uid || $record['expires'] < time() || $record['filename'] !== basename($path) || !is_file($path)) { throw new HttpException(404,'Preview not found.'); }
        return $path;
    }

    public static function readGuest(string $token, string $extension): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/D', $token) || !in_array($extension,['png','jpg','svg','tif'],true)) { throw new HttpException(404,'Preview not found.'); }
        $path = ROOT.'/public/tmp/preview-'.$token.'.'.$extension;
        if (!is_file($path) || filemtime($path) < time()-self::LIFETIME) { throw new HttpException(404,'Preview not found.'); }
        return $path;
    }

    private static function cleanExpired(): void
    {
        $marker = '/var/lib/simplemappr/preview-cleanup';
        $lock = fopen($marker,'c+');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { return; }
        if ((int)stream_get_contents($lock) > time()-300) { fclose($lock); return; }
        ftruncate($lock,0); rewind($lock); fwrite($lock,(string)time());
        foreach (glob(ROOT.'/public/tmp/preview-*') ?: [] as $path) { if (preg_match('/^preview-[0-9a-f]{32}\.(?:png|jpg|svg|tif)$/D',basename($path)) && !is_link($path) && is_file($path) && filemtime($path) < time()-self::LIFETIME) { unlink($path); } }
        foreach (glob(self::PRIVATE_DIRECTORY.'/*.json') ?: [] as $metadata) {
            if (is_link($metadata) || !preg_match('/^([0-9a-f]{32})\.json$/D',basename($metadata),$matches) || filesize($metadata) > 2048) { continue; }
            $record=json_decode(file_get_contents($metadata),true);
            if (!is_array($record) || !is_int($record['expires'] ?? null) || !is_int($record['uid'] ?? null) || !is_string($record['filename'] ?? null) || $record['expires'] >= time()
                || !preg_match('/^preview-'.preg_quote($matches[1],'/').'\.(?:png|jpg|svg|tif)$/D',$record['filename'])) { continue; }
            $path=self::PRIVATE_DIRECTORY.'/'.$record['filename'];
            if (is_link($path)) { continue; }
            if (is_file($path)) { unlink($path); }
            unlink($metadata);
        }
        fclose($lock);
    }
}
