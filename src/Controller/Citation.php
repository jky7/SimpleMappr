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

use SimpleMappr\CitationDocument;
use SimpleMappr\Database;
use SimpleMappr\HttpException;

class Citation implements RestMethods
{
    private Database $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index($params = null)
    {
        $this->db->prepare('SELECT id,year,reference,doi,link,first_author_surname,created FROM citations ORDER BY first_author_surname ASC,year DESC,id ASC');
        return ['status'=>'ok','citations'=>$this->db->fetchAllObject()];
    }

    public function show($id)
    {
        $this->db->prepare('SELECT id,year,reference,doi,link,first_author_surname,created FROM citations WHERE id=:id');
        $this->db->bindParam(':id',(int)$id);
        $record = $this->db->fetchFirstObject();
        if (!$record) { throw new HttpException(404,'Citation not found.'); }
        return $record;
    }

    public function create($content)
    {
        $values = CitationDocument::parse($content);
        $values['created'] = time();
        $values['id'] = $this->db->queryInsert('citations',$values);
        return ['status'=>'ok','citations'=>$values];
    }

    public function update($content,$where)
    {
        $id = (int)($where['id'] ?? 0);
        $values = CitationDocument::parse($content,$id);
        $this->show($id);
        $this->db->queryUpdate('citations',$values,['id'=>$id]);
        return ['status'=>'ok','citations'=>''];
    }

    public function destroy($id)
    {
        if ($this->db->queryDelete('citations',(int)$id) === 0) { throw new HttpException(404,'Citation not found.'); }
        return ['status'=>'ok','citations'=>''];
    }
}
