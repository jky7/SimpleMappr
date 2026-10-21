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

use SimpleMappr\Controller\User;

final class Assets
{
    private array $manifest;
    private bool $administrator;

    public function __construct()
    {
        $filename = dirname(__DIR__).'/public/build/manifest.json';
        if (!is_file($filename)) { throw new \RuntimeException('Frontend assets are missing. Rebuild the Docker image or run npm ci and npm run build.'); }
        $manifest = json_decode(file_get_contents($filename), true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['schemaVersion'] ?? null) !== 1 || !is_array($manifest['assets'] ?? null)) { throw new \RuntimeException('Invalid frontend asset manifest.'); }
        $this->manifest = $manifest['assets'];
        $user = Session::currentUser();
        $this->administrator = $user !== null && User::isAdministrator((new User)->show($user->uid));
    }

    private function asset(string $name): string
    {
        $asset = $this->manifest[$name] ?? [];
        if (!is_string($asset['file'] ?? null) || !preg_match('/^[a-z-]+\.[a-f0-9]{12}\.(?:js|css)$/D', $asset['file']) || !is_file(dirname(__DIR__).'/public/build/'.$asset['file'])) {
            throw new \RuntimeException('The frontend asset manifest is incomplete. Rebuild the Docker image.');
        }
        return '/public/build/'.$asset['file'];
    }

    public function getCSSHeader(): string
    {
        $names = ['editor-css'];
        return implode("\n", array_map(fn($name) => '<link rel="stylesheet" href="'.$this->asset($name).'">', $names));
    }

    public function getJSFooter(): string
    {
        $names = $this->administrator ? ['jquery','jquery-ui','editor','admin'] : ['jquery','jquery-ui','editor'];
        $scripts = implode("\n", array_map(fn($name) => '<script src="'.$this->asset($name).'"></script>', $names));
        $config = json_encode(['baseUrl'=>MAPPR_URL, 'active'=>Session::currentUser() ? 'true' : 'false', 'maxTextareaCount'=>MAXNUMTEXTAREA, 'csrfToken'=>Session::csrfToken(), 'storageScope'=>Session::currentUser() ? hash('sha256', 'owner:'.Session::currentUser()->uid) : 'guest', 'migrateLegacyStorage'=>Session::currentUser() !== null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $scripts .= '<script>jQuery.ajaxSetup({beforeSend:function(xhr,options){if(!options.crossDomain){xhr.setRequestHeader("X-CSRF-Token",'.$config.'.csrfToken);}}});SimpleMappr.init('.$config.');';
        if ($this->administrator) { $scripts .= 'SimpleMapprAdmin.init();'; }
        return $scripts.'</script>';
    }

    public function getJSVars(): string { return ''; }

    public function getHash(): string { return substr(hash('sha256', json_encode($this->manifest, JSON_THROW_ON_ERROR)), 0, 12); }

    public static function flushCache(bool $output = true): void
    {
        if ($output) { Header::setHeader('json'); echo json_encode(['files'=>true, 'immutable'=>true]); }
    }
}
