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
namespace SimpleMappr\Controller;

use SimpleMappr\Database;
use SimpleMappr\Session;
use SimpleMappr\SavedMapDocument;
use SimpleMappr\HttpException;

final class Map implements RestMethods
{
    public $total = 0, $filter_username = '', $filter_uid = null, $sort = 'created', $dir = 'desc', $results = [], $search = '', $row_count = 0;
    private Database $db;
    private int $uid;

    public function __construct()
    {
        $user = Session::currentUser();
        if (!$user) { throw new HttpException(403, 'Sign in to access your saved maps.'); }
        $this->uid = (int)$user->uid;
        $this->db = Database::getInstance();
    }

    public function index($params)
    {
        $this->sort = in_array($params['sort'] ?? '', ['title','created','updated'], true) ? $params['sort'] : 'created';
        $this->dir = strtolower($params['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        $this->search = mb_substr((string)($params['search'] ?? ''), 0, 255);
        $this->db->prepare('SELECT COUNT(*) AS total FROM maps WHERE uid=:uid'); $this->db->bindParam(':uid', $this->uid);
        $this->total = (int)$this->db->fetchFirstObject()->total;
        $sql = 'SELECT m.mid,m.uid,m.title,m.created,m.updated,s.sid,s.token FROM maps m LEFT JOIN shares s ON s.mid=m.mid WHERE m.uid=:uid';
        if ($this->search !== '') { $sql .= ' AND m.title LIKE :search'; }
        $sql .= ' ORDER BY m.'.$this->sort.' '.$this->dir.',m.mid '.$this->dir;
        $this->db->prepare($sql); $this->db->bindParam(':uid', $this->uid);
        if ($this->search !== '') { $this->db->bindParam(':search', '%'.$this->search.'%'); }
        $this->results = $this->db->fetchAllObject();
        $this->row_count = count($this->results);
        return $this;
    }

    public static function loadOwned(int $mid, int $uid): ?array
    {
        $db = Database::getInstance();
        $db->prepare('SELECT m.mid,m.title,m.map,s.token FROM maps m LEFT JOIN shares s ON s.mid=m.mid WHERE m.mid=:mid AND m.uid=:uid');
        $db->bindParam(':mid', $mid); $db->bindParam(':uid', $uid);
        $row = $db->fetchFirstArray();
        if (!$row) { return null; }
        return ['mid'=>(int)$row['mid'], 'title'=>$row['title'], 'map'=>SavedMapDocument::decode($row['map']), 'share_url'=>$row['token'] ? MAPPR_URL.'/shared/'.$row['token'] : null];
    }

    public function show($id)
    {
        $record = self::loadOwned((int)$id, $this->uid);
        if (!$record) { throw new HttpException(404, 'Map not found.'); }
        return ['status'=>'ok'] + $record;
    }

    public function create($content)
    {
        $document = SavedMapDocument::fromForm($content);
        SavedMapDocument::validateForWrite($document);
        $title = $document['save']['title'];
        if ($title === '') { throw new HttpException(422, 'Enter a map title.'); }
        $this->db->prepare('INSERT INTO maps(uid,title,map,created,updated) VALUES(:uid,:title,:map,:created,:updated) ON DUPLICATE KEY UPDATE mid=LAST_INSERT_ID(mid),map=VALUES(map),updated=VALUES(updated)');
        foreach (['uid'=>$this->uid,'title'=>$title,'map'=>SavedMapDocument::encode($document),'created'=>time(),'updated'=>time()] as $key=>$value) { $this->db->bindParam(':'.$key, $value); }
        $this->db->execute();
        $mid = (int)$this->db->connection()->lastInsertId();
        return ['status'=>'ok','mid'=>$mid,'share_url'=>self::loadOwned($mid, $this->uid)['share_url']];
    }

    public function update($content, $where) { throw new HttpException(405, 'Use the saved-map form.'); }
    public function destroy($id)
    {
        $this->show($id);
        $this->db->prepare('DELETE FROM maps WHERE mid=:mid AND uid=:uid');
        $this->db->bindParam(':mid', (int)$id); $this->db->bindParam(':uid', $this->uid); $this->db->execute();
        return ['status'=>'ok'];
    }
}
