<?php
use PHPUnit\Framework\TestCase;
use SimpleMappr\RenderCache;
use SimpleMappr\TemporaryImage;

final class RenderCacheTest extends TestCase
{
    private function request(array $overrides = []): object
    {
        return (object)($overrides + ['output' => 'png', 'download' => false, 'width' => 600.0, 'bbox_map' => '-10,-10,10,10']);
    }

    public function testKeysAreStableAndOnlyCoverJsonPreviews(): void
    {
        self::assertSame(RenderCache::key($this->request()), RenderCache::key($this->request()));
        self::assertNotSame(RenderCache::key($this->request()), RenderCache::key($this->request(['bbox_map' => '-10,-10,10,11'])));
        self::assertSame(RenderCache::key($this->request(['file_name' => 1, 'download_token' => 'a'])), RenderCache::key($this->request(['file_name' => 2, 'download_token' => 'b'])));
        foreach ([['download' => true], ['output' => 'svg'], ['output' => 'tif']] as $override) {
            self::assertNull(RenderCache::key($this->request($override)));
        }
    }

    public function testHitCopiesBytesToANewPreviewWithoutExposingTheCachedFile(): void
    {
        if (!RenderCache::enabled()) { self::markTestSkipped('The render cache directory is not writable.'); }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $source = tempnam(sys_get_temp_dir(), 'rc');
        file_put_contents($source, $png);
        $first = TemporaryImage::writeFile($source, 'png');
        $key = RenderCache::key($this->request(['width' => microtime(true)]));
        RenderCache::store($key, ['mapOutputImage' => $first['url'], 'legend_url' => null, 'scalebar_url' => null, 'bad_points' => 'x']);

        $entry = RenderCache::fetch($key);
        self::assertNotNull($entry);
        self::assertNull($entry['output']['mapOutputImage'], 'the cache stores bytes, never a preview URL');
        $output = RenderCache::materialize($key, $entry);
        self::assertSame('x', $output['bad_points']);
        self::assertNotSame($first['url'], $output['mapOutputImage']);
        self::assertSame($png, file_get_contents(TemporaryImage::written()[$output['mapOutputImage']]));
        unlink($source);
    }

    public function testMissingOrTamperedEntriesAreMisses(): void
    {
        self::assertNull(RenderCache::fetch(str_repeat('a', 64)));
        $this->expectException(InvalidArgumentException::class);
        RenderCache::fetch('../../etc/passwd');
    }
}
