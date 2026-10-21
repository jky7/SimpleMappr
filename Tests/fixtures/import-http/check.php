<?php
require '/srv/simplemappr/config/defaults.php';
require '/srv/simplemappr/vendor/autoload.php';

final class ControlledImport extends SimpleMappr\RemoteImport
{
    protected function resolve(string $host, float $deadline): array
    {
        return $host === 'fixture.test' || $host === 'wrong.test' ? ['13.77.41.2'] : parent::resolve($host, $deadline);
    }
}
$client = new ControlledImport();
$results = [];
foreach (['http://fixture.test/data','https://fixture.test/data','https://fixture.test/redirect','https://fixture.test/gzip'] as $url) {
    $start=microtime(true);$bytes=$client->fetch($url);
    $parsed=SimpleMappr\ImportData::parse($bytes);
    if ($parsed['coords'][0]['title'] !== 'Montréal, Québec' || $parsed['coords'][1]['data'] !== '0,0') { throw new RuntimeException('Unexpected fixture content.'); }
    $results[$url]=['bytes'=>strlen($bytes),'seconds'=>microtime(true)-$start,'sha256'=>hash('sha256',$bytes)];
}
foreach (['escape','downgrade','headers','size','chunked','gzip-large','slow'] as $path) {
    $start=microtime(true);
    try { $client->fetch('https://fixture.test/'.$path); throw new RuntimeException('Unsafe response accepted: '.$path); }
    catch (InvalidArgumentException $e) { $results[$path]=['rejected'=>$e->getMessage(),'seconds'=>microtime(true)-$start]; }
    if (microtime(true)-$start > SimpleMappr\RemoteImport::MAX_SECONDS+1) { throw new RuntimeException('Deadline exceeded: '.$path); }
}
try { $client->fetch('https://wrong.test/data'); throw new RuntimeException('TLS name mismatch accepted.'); }
catch (InvalidArgumentException $e) { $results['tls-name-mismatch']=$e->getMessage(); }
$samples = [];
for ($sample=0; $sample<21; $sample++) {
    $start = microtime(true);
    $_REQUEST = ['data'=>$client->fetch('https://fixture.test/data'), 'width'=>600, 'height'=>300];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $map = new SimpleMappr\Mappr\WebServices\Api();
    $image = $map->execute()->createOutput();
    $seconds = microtime(true)-$start;
    if (!imagecreatefromstring($image) || array_slice(getimagesizefromstring($image),0,2) !== [600,300]) { throw new RuntimeException('Controlled import did not render the requested image.'); }
    if ($sample > 0) { $samples[] = $seconds; }
}
$ordered = $samples; sort($ordered);
if (max($samples) > 30) { throw new RuntimeException('Controlled import exceeded the 30-second budget.'); }
$results['https-import-to-map'] = ['samples'=>$samples,'median_seconds'=>($ordered[9]+$ordered[10])/2,'range_seconds'=>[min($samples),max($samples)],'decoded_dimensions'=>[600,300],'failures'=>0,'budget_seconds'=>30,'baseline'=>'New bounded remote importer; no comparable successful parent path.'];
echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
