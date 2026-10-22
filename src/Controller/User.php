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
use SimpleMappr\HttpException;

final class User implements RestMethods
{
    public $sort = 'access', $dir = 'desc', $results;
    public static $roles = [1=>'user',2=>'administrator'];
    private Database $db;
    public function __construct() { $this->db = Database::getInstance(); }
    public static function count()
    {
        $db=Database::getInstance(); $db->prepare('SELECT COUNT(*) AS num FROM users'); return (int)$db->fetchFirstObject()->num;
    }
    public static function checkPermission($role = 'user')
    {
        $user=Session::currentUser();
        return $user && ($role === 'user' ? in_array((int)$user->role,[1,2],true) : (int)$user->role === 2);
    }
    public static function isAdministrator($user)
    {
        $record=$user instanceof self ? $user->results : $user;
        return $record && (int)$record->role === 2;
    }
    public function index($params)
    {
        $sorts=['num'=>'num','access'=>'u.access','username'=>'u.username'];
        $this->sort=isset($sorts[$params['sort'] ?? '']) ? $params['sort'] : 'access';
        $this->dir=strtolower($params['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
        $this->db->prepare('SELECT u.uid,u.username,u.email,u.access,u.role,COUNT(m.mid) AS num FROM users u LEFT JOIN maps m ON m.uid=u.uid GROUP BY u.uid ORDER BY '.$sorts[$this->sort].' '.$this->dir.' LIMIT 100');
        $this->results=$this->db->fetchAllObject(); return $this;
    }
    public function show($id)
    {
        $this->db->prepare('SELECT * FROM users WHERE uid=:uid'); $this->db->bindParam(':uid',(int)$id); $this->results=$this->db->fetchFirstObject(); return $this;
    }
    public function showByIdentifier($identifier)
    {
        $this->db->prepare('SELECT * FROM users WHERE identifier=:identifier'); $this->db->bindParam(':identifier',$identifier); $this->results=$this->db->fetchFirstObject(); return $this;
    }
    public function create($content) { $content['uid']=$this->db->queryInsert('users',$content); return (object)$content; }
    public function update($content,$where) { $this->results=$this->db->queryUpdate('users',$content,$where); return $this; }
    public function destroy($id) { throw new HttpException(403,'User deletion is disabled for private deployments.'); }
}
