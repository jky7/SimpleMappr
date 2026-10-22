<?php
require dirname(__DIR__,2).'/config/defaults.php';
require ROOT.'/vendor/autoload.php';
use SimpleMappr\Database;
use SimpleMappr\Session;
if (PHP_SAPI !== 'cli' || getenv('SIMPLEMAPPR_ALLOW_TEST_FIXTURES') !== '1') { fwrite(STDERR,"Run this fixture helper only through the CLI with SIMPLEMAPPR_ALLOW_TEST_FIXTURES=1.\n"); exit(1); }
$path=$argv[1] ?? '';
if (!str_starts_with($path,'/var/lib/simplemappr/')) { throw new RuntimeException('Use a private runtime fixture file.'); }
$db=Database::getInstance();
if (($argv[2] ?? '') === '--cleanup') {
    $fixtures=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    foreach ($fixtures as $fixture) {
        $db->prepare("DELETE FROM users WHERE uid=:uid AND identifier LIKE 'fixture:p3:%'");$db->bindParam(':uid',$fixture['uid']);$db->execute();
        $session=session_save_path().'/sess_'.$fixture['session'];if (is_file($session)) { unlink($session); }
    }
    unlink($path);echo "Private authorization fixtures removed.\n";exit;
}
if (is_file($path)) { throw new RuntimeException('Fixture file already exists.'); }
$fixtures=[];
foreach (['owner'=>1,'stranger'=>1,'administrator'=>2] as $name=>$role) {
    $uid=$db->queryInsert('users',['identifier'=>'fixture:p3:'.bin2hex(random_bytes(12)),'hash'=>bin2hex(random_bytes(16)),'username'=>'P3 '.$name,'role'=>$role,'created'=>time()]);
    Session::setSession();session_regenerate_id(true);
    $_SESSION=['uid'=>$uid,'credential_fingerprint'=>hash('sha256',getenv('SIMPLEMAPPR_OWNER_USERNAME')."\0".getenv('SIMPLEMAPPR_OWNER_PASSWORD_HASH')),'activity'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    $fixtures[$name]=['uid'=>$uid,'session'=>session_id(),'csrf'=>$_SESSION['csrf']];Session::closeSession();session_id('');
}
file_put_contents($path,json_encode($fixtures,JSON_THROW_ON_ERROR));chmod($path,0600);
echo "Private owner/stranger/administrator fixtures created.\n";
