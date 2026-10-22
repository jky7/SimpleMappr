<?php
namespace SimpleMappr;

use PDO;

final class DatabaseSchema
{
    public const VERSION = '20261020000000';
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function migrate(?string $backup = null): string
    {
        if (!$this->pdo->query("SELECT GET_LOCK('simplemappr-schema', 30)")->fetchColumn()) { throw new \RuntimeException('Database migration is already running.'); }
        try {
            $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('schema_migrations', $tables, true)) {
                if ($this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) !== [self::VERSION]) { throw new \RuntimeException('Unknown database schema version.'); }
                return 'Database baseline is current.';
            }
            if ($tables) {
                if (!$backup) { throw new \RuntimeException('Legacy database requires explicit adoption and a backup destination.'); }
                $this->validateLegacy($tables);
                $before = $this->mapHashes();
                $this->backup($tables, $backup);
                $this->adopt($tables);
                if ($before !== $this->mapHashes()) { throw new \RuntimeException('Map bytes changed during adoption. Restore the backup.'); }
            } else {
                $this->pdo->exec(file_get_contents(ROOT.'/db/migrations/'.self::VERSION.'_baseline.sql'));
            }
            $this->pdo->exec('CREATE TABLE schema_migrations (version CHAR(14) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $statement = $this->pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)');
            $statement->execute([self::VERSION]);
            return $tables ? 'Legacy database adopted; map bytes preserved; old shares remain private.' : 'Database baseline created.';
        } finally { $this->pdo->query("SELECT RELEASE_LOCK('simplemappr-schema')"); }
    }

    private function validateLegacy(array $tables): void
    {
        $expected = [
            'users' => ['uid','hash','identifier','username','displayname','email','role','created','access'],
            'maps' => ['mid','uid','title','map','created','updated'],
            'shares' => ['sid','mid','created'],
            'citations' => ['id','year','reference','doi','link','first_author_surname','created'],
            'stateprovinces' => ['id','country_iso','country','stateprovince','stateprovince_code'],
            'migrations' => ['version','migration_name','start_time','end_time','breakpoint'],
        ];
        if (array_diff($tables, array_keys($expected)) || array_diff(['users','maps','citations','stateprovinces'], $tables)) { throw new \RuntimeException('Unknown legacy database tables.'); }
        foreach ($tables as $table) {
            $status = $this->pdo->query("SHOW TABLE STATUS LIKE ".$this->pdo->quote($table))->fetch(PDO::FETCH_ASSOC);
            if (($status['Engine'] ?? null) !== 'InnoDB') { throw new \RuntimeException('Unsupported storage engine in '.$table.'.'); }
            $definitions = $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_column($definitions, 'Field');
            foreach ($definitions as $column) {
                if (in_array($column['Field'], ['uid','mid','sid','id'], true) && !preg_match('/^(?:bigint|int)(?:\\([0-9]+\\))?(?: unsigned)?$/D', $column['Type'])) { throw new \RuntimeException('Unsupported identifier type in '.$table.'.'); }
            }
            if (in_array($table, ['users','maps','citations','stateprovinces'], true)) {
                $id = ['users'=>'uid','maps'=>'mid','citations'=>'id','stateprovinces'=>'id'][$table];
                $definition = array_column($definitions, null, 'Field')[$id];
                if (!str_starts_with($definition['Type'], 'bigint') || !str_contains($definition['Type'], 'unsigned') || $definition['Key'] !== 'PRI') { throw new \RuntimeException('Unsupported primary identifier in '.$table.'.'); }
            }
            $optional = $table === 'users' ? ['hash'] : ($table === 'citations' ? ['created'] : []);
            if (array_diff($columns, $expected[$table]) || array_diff($expected[$table], $columns, $optional)) { throw new \RuntimeException('Unknown legacy columns in '.$table.'.'); }
        }
        foreach ($this->pdo->query('SELECT mid,map FROM maps') as $row) {
            try { SavedMapDocument::decode($row['map'] ?? ''); } catch (\Throwable $e) { throw new \RuntimeException('Invalid settings JSON in legacy map '.(int)$row['mid'].'.', 0, $e); }
        }
        $checks = [
            'orphan maps' => 'SELECT COUNT(*) FROM maps m LEFT JOIN users u ON u.uid=m.uid WHERE u.uid IS NULL',
            'duplicate owner map titles' => 'SELECT COUNT(*) FROM (SELECT uid,title FROM maps GROUP BY uid,title HAVING COUNT(*)>1) x',
            'duplicate user identifiers' => 'SELECT COUNT(*) FROM (SELECT identifier FROM users GROUP BY identifier HAVING COUNT(*)>1) x',
        ];
        if (in_array('shares', $tables, true)) {
            $checks['orphan shares'] = 'SELECT COUNT(*) FROM shares s LEFT JOIN maps m ON m.mid=s.mid WHERE m.mid IS NULL';
            $checks['duplicate shares'] = 'SELECT COUNT(*) FROM (SELECT mid FROM shares GROUP BY mid HAVING COUNT(*)>1) x';
        }
        foreach ($checks as $label => $sql) { if ($this->pdo->query($sql)->fetchColumn()) { throw new \RuntimeException('Resolve '.$label.' before adopting this database.'); } }
    }

    private function mapHashes(): array
    {
        return $this->pdo->query('SELECT mid,SHA2(CAST(map AS BINARY),256) AS hash FROM maps ORDER BY mid')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function backup(array $tables, string $path): void
    {
        $file = fopen($path, 'x');
        if (!$file) { throw new \RuntimeException('Backup destination must be a new writable file.'); }
        try {
            if (!chmod($path, 0600)) { throw new \RuntimeException('Cannot protect the database backup. Adoption stopped.'); }
            $this->writeBackup($file, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($tables as $table) {
                $this->writeBackup($file, 'DROP TABLE IF EXISTS `'.$table."`;\n".$this->pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1].";\n");
                foreach ($this->pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $values = array_map(fn($value) => $value === null ? 'NULL' : $this->pdo->quote((string)$value), array_values($row));
                    $this->writeBackup($file, 'INSERT INTO `'.$table.'` VALUES ('.implode(',', $values).");\n");
                }
            }
            $this->writeBackup($file, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (!fflush($file)) { throw new \RuntimeException('Database backup flush failed. Adoption stopped.'); }
            if (stream_get_meta_data($file)['stream_type'] === 'STDIO' && !fsync($file)) { throw new \RuntimeException('Database backup sync failed. Adoption stopped.'); }
            if (!fclose($file)) { throw new \RuntimeException('Database backup close failed. Adoption stopped.'); }
        } finally { if (is_resource($file)) { fclose($file); } }
    }

    private function writeBackup($file, string $bytes): void
    {
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length; $offset += $written) {
            $written = fwrite($file, substr($bytes, $offset));
            if ($written === false || $written === 0) { throw new \RuntimeException('Database backup write failed. Adoption stopped.'); }
        }
    }

    private function adopt(array $tables): void
    {
        $this->pdo->exec('ALTER TABLE maps MODIFY uid BIGINT UNSIGNED NOT NULL, MODIFY created BIGINT NOT NULL, MODIFY updated BIGINT NULL');
        if (in_array('shares', $tables, true)) {
            $this->pdo->exec('ALTER TABLE shares MODIFY sid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, MODIFY mid BIGINT UNSIGNED NOT NULL, MODIFY created BIGINT NOT NULL, ADD token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD UNIQUE KEY share_mid(mid), ADD UNIQUE KEY share_token(token)');
        } else { $this->pdo->exec('CREATE TABLE shares (sid BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, mid BIGINT UNSIGNED NOT NULL UNIQUE, token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE, created BIGINT NOT NULL) ENGINE=InnoDB'); }
        $usersColumns = $this->pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('hash', $usersColumns, true)) { $this->pdo->exec("ALTER TABLE users ADD hash VARCHAR(255) NOT NULL DEFAULT ''"); }
        $citationColumns = $this->pdo->query('SHOW COLUMNS FROM citations')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('created', $citationColumns, true)) { $this->pdo->exec('ALTER TABLE citations ADD created BIGINT NOT NULL DEFAULT 0'); }
        foreach (['users','maps','citations','stateprovinces','shares'] as $table) { $this->pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
        $this->pdo->exec('ALTER TABLE shares MODIFY token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
        $this->pdo->exec('ALTER TABLE users MODIFY hash VARCHAR(255) NOT NULL, MODIFY created BIGINT NULL, MODIFY access BIGINT NULL, ADD UNIQUE KEY owner_identifier(identifier)');
        $this->pdo->exec('ALTER TABLE citations MODIFY created BIGINT NOT NULL');
        $this->pdo->exec('ALTER TABLE stateprovinces MODIFY stateprovince_code VARCHAR(16) NOT NULL');
        $this->pdo->exec('ALTER TABLE maps ADD CONSTRAINT maps_owner FOREIGN KEY(uid) REFERENCES users(uid) ON DELETE CASCADE, ADD UNIQUE KEY owner_title(uid,title), ADD KEY owner_created(uid,created)');
        $this->pdo->exec('ALTER TABLE shares ADD CONSTRAINT shares_map FOREIGN KEY(mid) REFERENCES maps(mid) ON DELETE CASCADE');
        $this->pdo->exec('CREATE TABLE login_attempts (attempt_key CHAR(64) CHARACTER SET ascii PRIMARY KEY, window_start BIGINT NOT NULL, attempts INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    }
}
