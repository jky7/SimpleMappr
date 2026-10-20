<?php
require dirname(__DIR__,2).'/config/defaults.php';
require ROOT.'/vendor/autoload.php';

use SimpleMappr\Database;
use SimpleMappr\DatabaseSchema;

$dsn=getenv('SIMPLEMAPPR_TEST_DSN');
if (!$dsn) { fwrite(STDERR,"Set SIMPLEMAPPR_TEST_DSN for an isolated disposable MariaDB server. This test creates and drops fixture databases.\n"); exit(1); }
$pdo=new PDO($dsn,getenv('SIMPLEMAPPR_TEST_DB_USER') ?: 'root',getenv('SIMPLEMAPPR_TEST_DB_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
final class FailingBackupStream
{
    public $context;
    public static string $failure = 'write';
    public function stream_open($path,$mode,$options,&$openedPath): bool { return true; }
    public function stream_metadata($path,$option,$value): bool { return true; }
    public function stream_write($data): int { return self::$failure === 'write' ? 0 : strlen($data); }
    public function stream_flush(): bool { return false; }
    public function stream_close(): void {}
    public function stream_stat(): array { return []; }
}
stream_wrapper_register('failingbackup',FailingBackupStream::class);
$databases=[]; $backups=[];
function check(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function fixture(string $label): PDO {
    global $pdo,$databases,$dsn;
    $name='simplemappr_test_'.$label.'_'.bin2hex(random_bytes(5));
    $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4'); $databases[]=$name;
    return new PDO($dsn.';dbname='.$name,getenv('SIMPLEMAPPR_TEST_DB_USER') ?: 'root',getenv('SIMPLEMAPPR_TEST_DB_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function backupPath(): string { global $backups; return $backups[]=sys_get_temp_dir().'/simplemappr-test-'.bin2hex(random_bytes(10)).'.sql'; }
try {
    $fresh=fixture('fresh'); $schema=new DatabaseSchema($fresh);
    check(str_contains($schema->migrate(),'created'),'Fresh baseline failed'); check(str_contains($schema->migrate(),'current'),'Repeat migration failed');
    $sample=fixture('sample');$sample->exec(file_get_contents(ROOT.'/db/sample.db.sql'));check(str_contains((new DatabaseSchema($sample))->migrate(),'current'),'Current sample import failed');
    foreach (['write','flush'] as $failure) {
        $broken=fixture('backup_'.$failure);$broken->exec(file_get_contents(ROOT.'/Tests/fixtures/legacy-sample.sql'));
        FailingBackupStream::$failure=$failure;
        try { (new DatabaseSchema($broken))->migrate('failingbackup://fixture');throw new RuntimeException('Failed backup allowed schema changes'); }
        catch(RuntimeException $error) { check(str_contains($error->getMessage(),'backup '.$failure.' failed'),'Unexpected backup failure'); }
        check(!in_array('token',$broken->query('SHOW COLUMNS FROM shares')->fetchAll(PDO::FETCH_COLUMN),true),'Schema changed after failed backup');
        check(!in_array('schema_migrations',$broken->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),true),'Migration marked current after failed backup');
    }
    $legacy=fixture('legacy');$legacy->exec(file_get_contents(ROOT.'/Tests/fixtures/legacy-sample.sql'));
    $legacy->exec("INSERT INTO users(uid,hash,identifier,username) VALUES(1,'legacy-hash','fixture:owner','Owner')");
    $map=json_encode(['save'=>['title'=>'Montréal 😀'],'coords'=>[['title'=>'Café','data'=>"43.6,-79.3\n45.5,-73.5"]],'projection'=>'epsg:4326','bbox_rubberband'=>[]]);
    $insert=$legacy->prepare('INSERT INTO maps(mid,uid,title,map,created) VALUES(1,1,?,?,1)');$insert->execute(['Legacy title',$map]);
    $legacy->exec('INSERT INTO shares(mid,created) VALUES(1,1)');
    try { (new DatabaseSchema($legacy))->migrate(); throw new RuntimeException('Implicit adoption was allowed'); } catch(RuntimeException $error) { check(str_contains($error->getMessage(),'explicit adoption'),'Unexpected adoption error'); }
    $backup=backupPath();(new DatabaseSchema($legacy))->migrate($backup);
    check($legacy->query('SELECT map FROM maps')->fetchColumn()===$map,'Legacy map bytes changed');
    check($legacy->query('SELECT token FROM shares')->fetchColumn()===null,'Old numeric share became public');
    check(str_contains((new DatabaseSchema($legacy))->migrate(),'current'),'Repeated adoption failed');
    $restore=fixture('restore');$restore->exec(file_get_contents($backup));check($restore->query('SELECT map FROM maps')->fetchColumn()===$map,'Backup restore changed map bytes');
    $orphan=fixture('orphan');$orphan->exec(file_get_contents(ROOT.'/Tests/fixtures/legacy-sample.sql'));$orphan->prepare('INSERT INTO maps(uid,title,map,created) VALUES(9,?,?,1)')->execute(['Orphan',$map]);
    $rejectedBackup=backupPath();
    try { (new DatabaseSchema($orphan))->migrate($rejectedBackup);throw new RuntimeException('Orphan adoption allowed'); } catch(RuntimeException $error) { check(str_contains($error->getMessage(),'orphan maps'),'Unexpected orphan error'); }
    check(!file_exists($rejectedBackup),'Rejected adoption wrote a backup or changed the schema');
    $invalid=fixture('invalid');$invalid->exec(file_get_contents(ROOT.'/Tests/fixtures/legacy-sample.sql'));$invalid->exec("INSERT INTO users(uid,hash,identifier) VALUES(1,'hash','fixture:1');INSERT INTO maps(uid,title,map,created) VALUES(1,'Bad','false',1)");
    try { (new DatabaseSchema($invalid))->migrate(backupPath());throw new RuntimeException('Invalid JSON adoption allowed'); } catch(RuntimeException $error) { check(str_contains($error->getMessage(),'Invalid settings JSON'),'Unexpected JSON error'); }
    $unknown=fixture('unknown');$unknown->exec('CREATE TABLE surprise(id INT)');
    try { (new DatabaseSchema($unknown))->migrate(backupPath());throw new RuntimeException('Unknown schema adopted'); } catch(RuntimeException $error) { check(str_contains($error->getMessage(),'Unknown legacy'),'Unexpected schema error'); }
    $freshName=$databases[0];
    preg_match('/host=([^;]+)/',$dsn,$host);putenv('SIMPLEMAPPR_DB_HOST='.$host[1]);putenv('SIMPLEMAPPR_DB_NAME='.$freshName);putenv('SIMPLEMAPPR_DB_USER='.(getenv('SIMPLEMAPPR_TEST_DB_USER') ?: 'root'));putenv('SIMPLEMAPPR_DB_PASSWORD='.(getenv('SIMPLEMAPPR_TEST_DB_PASSWORD') ?: ''));
    $db=Database::getInstance();$uid=$db->queryInsert('users',['hash'=>'text-hash','identifier'=>'fixture:unicode','username'=>'Montréal 😀','email'=>null,'role'=>1]);
    $db->queryUpdate('users',['displayname'=>"Café\nsecond line"],['uid'=>$uid,'identifier'=>'fixture:unicode']);
    $db->prepare('SELECT * FROM users WHERE uid=:uid');$db->bindParam(':uid',$uid);$row=$db->fetchFirstArray();
    check($row['username']==='Montréal 😀' && $row['email']===null && $row['hash']==='text-hash' && $row['displayname']==="Café\nsecond line",'Typed database roundtrip failed');
    echo "PASS: empty/current/legacy/repeated migration, byte-preserving adoption and backup restore, private old shares, rejected orphan/invalid/unknown fixtures and failed backup writes/flushes before DDL, Unicode/null/multiline typed bindings.\n";
} finally {
    foreach ($databases as $name) { $pdo->exec('DROP DATABASE `'.$name.'`'); }
    foreach ($backups as $path) { if (is_file($path)) { unlink($path); } }
}
