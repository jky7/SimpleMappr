<?php

/**
 * SimpleMappr - create point maps for publications and presentations
 *
 * PHP Version >= 5.6
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 *
 * MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 */
namespace SimpleMappr;

use PDO;
use Symfony\Component\Yaml\Yaml;

final class Database
{
    private static ?self $_instance = null;
    private PDO $_link;
    private \PDOStatement $_handle;

    private function __construct()
    {
        if (getenv('SIMPLEMAPPR_DB_HOST')) {
            $config = ['host'=>getenv('SIMPLEMAPPR_DB_HOST'), 'port'=>getenv('SIMPLEMAPPR_DB_PORT') ?: 3306, 'name'=>getenv('SIMPLEMAPPR_DB_NAME') ?: 'simplemappr', 'user'=>getenv('SIMPLEMAPPR_DB_USER') ?: 'simplemappr', 'pass'=>getenv('SIMPLEMAPPR_DB_PASSWORD') ?: '', 'charset'=>'utf8mb4'];
        } else { $config = Yaml::parseFile(ROOT.'/config/phinx.yml')['environments'][ENVIRONMENT]; }
        $dsn = 'mysql:host='.$config['host'].';port='.($config['port'] ?? 3306).';dbname='.$config['name'].';charset=utf8mb4';
        $this->_link = new PDO($dsn, $config['user'], $config['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }

    public static function getInstance(): self { return self::$_instance ??= new self; }
    public function connection(): PDO { return $this->_link; }
    public function prepare($sql): \PDOStatement { return $this->_handle = $this->_link->prepare($sql); }
    public function exec($sql): int|false { return $this->_link->exec($sql); }
    public function query($sql): array { return $this->_link->query($sql)->fetchAll(PDO::FETCH_OBJ); }

    public function bindParam($key, $value, $type = null): void
    {
        $pdoType = match (true) { $value === null => PDO::PARAM_NULL, is_bool($value) => PDO::PARAM_BOOL, $type === 'integer' || is_int($value) => PDO::PARAM_INT, default => PDO::PARAM_STR };
        $this->_handle->bindValue($key, $value, $pdoType);
    }
    public function execute(): void { $this->_handle->execute(); }
    public function rowCount(): int { return $this->_handle->rowCount(); }
    public function fetchFirstObject(): object|false { $this->execute(); return $this->_handle->fetch(PDO::FETCH_OBJ); }
    public function fetchAllObject(): array { $this->execute(); return $this->_handle->fetchAll(PDO::FETCH_OBJ); }
    public function fetchFirstArray(): array|false { $this->execute(); return $this->_handle->fetch(PDO::FETCH_ASSOC); }
    public function fetchAllArray(): array { $this->execute(); return $this->_handle->fetchAll(PDO::FETCH_ASSOC); }

    private function identifier(string $name): string
    {
        if (!preg_match('/^[a-z_]+$/D', $name)) { throw new \InvalidArgumentException('Invalid database identifier.'); }
        return '`'.$name.'`';
    }

    public function queryInsert($table, $data = []): ?int
    {
        if (!$data) { return null; }
        $columns = array_keys($data);
        $sql = 'INSERT INTO '.$this->identifier($table).' ('.implode(',', array_map($this->identifier(...), $columns)).') VALUES ('.implode(',', array_map(fn($key)=>':'.$key, $columns)).')';
        $this->prepare($sql);
        foreach ($data as $key=>$value) { $this->bindParam(':'.$key, $value); }
        $this->execute();
        return (int)$this->_link->lastInsertId();
    }

    public function queryUpdate($table, $data, $where): int
    {
        if (!$data || !is_array($where) || !$where) { throw new \InvalidArgumentException('Updates require bound predicates.'); }
        $set = array_map(fn($key)=>$this->identifier($key).'=:set_'.$key, array_keys($data));
        $conditions = array_map(fn($key)=>$this->identifier($key).'=:where_'.$key, array_keys($where));
        $this->prepare('UPDATE '.$this->identifier($table).' SET '.implode(',', $set).' WHERE '.implode(' AND ', $conditions));
        foreach ($data as $key=>$value) { $this->bindParam(':set_'.$key, $value); }
        foreach ($where as $key=>$value) { $this->bindParam(':where_'.$key, $value); }
        $this->execute();
        return $this->rowCount();
    }

    public function queryDelete($table, $id): int
    {
        $this->prepare('DELETE FROM '.$this->identifier($table).' WHERE id=:id');
        $this->bindParam(':id', $id, 'integer'); $this->execute();
        return $this->rowCount();
    }
}
