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
use SimpleMappr\SiteUrl;

final class CitationFeed
{
    private string $base;
    private array $items = [];

    public function makeChannel(): self
    {
        $this->base = SiteUrl::base();
        $this->items = [];
        return $this;
    }

    public function addItems(?array $entries = null): self
    {
        $entries ??= (new Citation())->index()['citations'];
        usort($entries, static fn($a,$b) => [(int)$b->year,(int)$b->created,(int)$b->id] <=> [(int)$a->year,(int)$a->created,(int)$a->id]);
        foreach ($entries as $citation) {
            if ($citation->created < time()-604800 || $citation->created > time()) { continue; }
            $url = $citation->link;
            if ($citation->doi !== '' && preg_match('~^10\.[0-9]{4,9}/[^\s<>]+$~D',$citation->doi)) { $url = 'https://doi.org/'.str_replace('%2F','/',rawurlencode($citation->doi)); }
            if (!CitationDocument::validLink($url)) { continue; }
            $this->items[] = ['id'=>(int)$citation->id,'text'=>$citation->reference,'url'=>$url,'created'=>(int)$citation->created];
        }
        return $this;
    }

    public function getFeed(): string
    {
        $xml = new \XMLWriter();$xml->openMemory();$xml->startDocument('1.0','UTF-8');
        $xml->startElement('rss');$xml->writeAttribute('version','2.0');$xml->startElement('channel');
        $xml->writeElement('title','SimpleMappr Recent Citations');$xml->writeElement('link',$this->base);
        $xml->writeElement('description','Recent publications that have used SimpleMappr');$xml->writeElement('language','en-US');$xml->writeElement('ttl','60');
        foreach ($this->items as $item) {
            $xml->startElement('item');$xml->writeElement('title',$item['text']);
            $xml->writeElement('description',htmlspecialchars($item['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));
            $xml->writeElement('link',$item['url']);$xml->startElement('guid');$xml->writeAttribute('isPermaLink','false');$xml->text($this->base.'/citation/'.$item['id']);$xml->endElement();
            $xml->writeElement('pubDate',date(DATE_RSS,$item['created']));$xml->endElement();
        }
        $xml->endElement();$xml->endElement();$xml->endDocument();return $xml->outputMemory();
    }
}
