<?php
namespace SimpleMappr;

/**
 * Disposable cache of finished map previews keyed by the full request.
 *
 * The cache holds image bytes only. Every hit is copied into a fresh TemporaryImage, so guest and
 * owner previews keep their own random names and access rules. A write failure never fails a render.
 */
final class RenderCache
{
    private const DIRECTORY = '/var/cache/simplemappr-render';
    private const LIFETIME = 86400;
    private const BUDGET = 268435456;
    private const FIELDS = ['mapOutputImage', 'legend_url', 'scalebar_url'];

    public static function enabled(): bool
    {
        return (getenv('SIMPLEMAPPR_RENDER_CACHE') ?: '1') !== '0' && is_dir(self::DIRECTORY) && is_writable(self::DIRECTORY);
    }

    /** Null when the request is not a JSON preview render or cannot be keyed. */
    public static function key(object $request): ?string
    {
        if (!empty($request->download) || !in_array($request->output ?? 'png', ['png', 'pnga', 'jpg'], true)) { return null; }
        // Request defaults seed these with the clock and a random token; previews never use them.
        $fields = array_diff_key((array)$request, ['file_name' => 1, 'download_token' => 1]);
        $encoded = json_encode([self::version(), SiteUrl::base(), MAPPR_URL, $fields]);
        return $encoded === false ? null : hash('sha256', $encoded);
    }

    public static function fetch(string $key): ?array
    {
        $raw = @file_get_contents(self::path($key).'.json');
        $entry = $raw === false ? null : json_decode($raw, true);
        if (!is_array($entry) || !is_array($entry['output'] ?? null) || !is_array($entry['files'] ?? null) || ($entry['created'] ?? 0) < time() - self::LIFETIME) { return null; }
        foreach ($entry['files'] as $field => $extension) {
            if (!in_array($field, self::FIELDS, true) || !in_array($extension, ['png', 'jpg'], true) || !is_file(self::path($key).'.'.$field.'.'.$extension)) { return null; }
        }
        @touch(self::path($key).'.json');
        return $entry;
    }

    public static function materialize(string $key, array $entry): array
    {
        $output = $entry['output'];
        foreach ($entry['files'] as $field => $extension) {
            $output[$field] = TemporaryImage::writeFile(self::path($key).'.'.$field.'.'.$extension, $extension)['url'];
        }
        return $output;
    }

    public static function store(string $key, array $output): void
    {
        try {
            $files = [];
            $directory = dirname(self::path($key));
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { return; }
            foreach (self::FIELDS as $field) {
                if (empty($output[$field])) { continue; }
                $source = TemporaryImage::written()[$output[$field]] ?? null;
                $extension = $source === null ? '' : strtolower(pathinfo($source, PATHINFO_EXTENSION));
                if (!in_array($extension, ['png', 'jpg'], true)) { return; }
                $target = self::path($key).'.'.$field.'.'.$extension;
                if (!copy($source, $target.'.tmp') || !rename($target.'.tmp', $target)) { return; }
                $files[$field] = $extension;
                $output[$field] = null;
            }
            $meta = self::path($key).'.json';
            file_put_contents($meta.'.tmp', json_encode(['created' => time(), 'output' => $output, 'files' => $files]));
            rename($meta.'.tmp', $meta);
            self::trim();
        } catch (\Throwable $e) {
            error_log('Render cache write failed: '.$e->getMessage());
        }
    }

    /** Keep the cache under its byte budget, dropping the least recently used entries first. */
    public static function trim(): void
    {
        $lock = fopen(self::DIRECTORY.'/.trim', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return; }
        if ((int)stream_get_contents($lock) > time() - 60) { fclose($lock); return; }
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)time());
        $files = [];
        $total = 0;
        foreach (glob(self::DIRECTORY.'/??/*') ?: [] as $path) {
            if (!is_file($path) || is_link($path)) { continue; }
            $files[$path] = [filemtime($path), filesize($path)];
            $total += $files[$path][1];
        }
        asort($files);
        foreach ($files as $path => [$modified, $size]) {
            if ($total <= self::BUDGET * 0.8 && $modified >= time() - 2 * self::LIFETIME) { break; }
            unlink($path);
            $total -= $size;
        }
        fclose($lock);
    }

    private static function path(string $key): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $key)) { throw new \InvalidArgumentException('Invalid render cache key.'); }
        return self::DIRECTORY.'/'.substr($key, 0, 2).'/'.$key;
    }

    /** Changes with the code, configuration, datasets and feature flags that shape a render. */
    private static function version(): string
    {
        static $version = null;
        if ($version !== null) { return $version; }
        $stamp = static function (string $directory): string {
            $parts = [];
            foreach (glob($directory.'/*') ?: [] as $path) { $parts[] = basename($path).':'.filemtime($path).':'.(is_file($path) ? filesize($path) : 0); }
            return implode(',', $parts);
        };
        $directories = [ROOT.'/src', ROOT.'/src/Mappr', ROOT.'/src/Mappr/Application', ROOT.'/config', ROOT.'/mapserver', ROOT.'/mapserver/maps'];
        return $version = hash('sha256', implode('|', array_merge([getenv('SIMPLEMAPPR_ASSET_PROFILE') ?: '', getenv('SIMPLEMAPPR_DETAILED_RELIEF') ?: ''], array_map($stamp, $directories))));
    }
}
