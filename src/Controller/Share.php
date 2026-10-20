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

final class Share implements RestMethods
{
    public $sort = 'created', $dir = 'desc', $results = [];
    private Database $db;
    private int $uid;
    public function __construct()
    {
        $user = Session::currentUser();
        if (!$user) { throw new HttpException(403, 'Sign in to manage sharing.'); }
        $this->uid = (int)$user->uid;
        $this->db = Database::getInstance();
    }
    public function index($params)
    {
        $this->sort = in_array($params['sort'] ?? '', ['title','created'], true) ? $params['sort'] : 'created';
        $this->dir = strtolower($params['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        $order = $this->sort === 'title' ? 'm.title' : 's.created';
        $this->db->prepare('SELECT s.sid,s.mid,s.token,m.title,s.created FROM shares s JOIN maps m ON m.mid=s.mid WHERE m.uid=:uid AND s.token IS NOT NULL ORDER BY '.$order.' '.$this->dir);
        $this->db->bindParam(':uid', $this->uid); $this->results = $this->db->fetchAllObject();
        return $this;
    }
    public static function loadToken(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $token)) { return null; }
        $db = Database::getInstance();
        $db->prepare('SELECT m.title,m.map FROM shares s JOIN maps m ON m.mid=s.mid WHERE s.token=:token');
        $db->bindParam(':token', $token); $row = $db->fetchFirstArray();
        return $row ? ['title'=>$row['title'],'map'=>SavedMapDocument::decode($row['map'])] : null;
    }
    public function show($id) { throw new HttpException(404, 'Share not found.'); }
    public function create($content)
    {
        $mid = filter_var($content['mid'] ?? null, FILTER_VALIDATE_INT);
        if (!$mid || !Map::loadOwned($mid, $this->uid)) { throw new HttpException(404, 'Map not found.'); }
        $token = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO shares(mid,token,created) VALUES(:mid,:token,:created) ON DUPLICATE KEY UPDATE token=COALESCE(token,VALUES(token))');
        $this->db->bindParam(':mid', $mid); $this->db->bindParam(':token', $token); $this->db->bindParam(':created', time()); $this->db->execute();
        $this->db->prepare('SELECT sid,token FROM shares WHERE mid=:mid'); $this->db->bindParam(':mid', $mid);
        $row = $this->db->fetchFirstObject();
        return ['status'=>'ok','sid'=>(int)$row->sid,'url'=>MAPPR_URL.'/shared/'.$row->token];
    }
    public function update($content, $where) { throw new HttpException(405, 'Revoke the share to change access.'); }
    public function destroy($id)
    {
        $this->db->prepare('DELETE s FROM shares s JOIN maps m ON m.mid=s.mid WHERE s.sid=:sid AND m.uid=:uid');
        $this->db->bindParam(':sid', (int)$id); $this->db->bindParam(':uid', $this->uid); $this->db->execute();
        if (!$this->db->rowCount()) { throw new HttpException(404, 'Share not found.'); }
        return ['status'=>'ok'];
    }
}
