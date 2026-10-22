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

use \Phroute\Phroute\Autoloader;
use \Phroute\Phroute\RouteCollector;
use \Phroute\Phroute\Dispatcher;
use \Symfony\Component\Yaml\Yaml;
use Twig\Loader\FilesystemLoader;
use Twig\Environment;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\PoFileLoader;
use SimpleMappr\Constants\AcceptedMarkers;
use SimpleMappr\Constants\AcceptedProjections;
use SimpleMappr\Controller\Citation;
use SimpleMappr\Controller\User;
use SimpleMappr\Mappr\Mappr;

/**
 * Router for SimpleMappr
 *
 * @category  Class
 * @package   SimpleMappr
 * @author    David P. Shorthouse <davidpshorthouse@gmail.com>
 * @copyright 2010-2018 David P. Shorthouse
 * @license   MIT, https://github.com/dshorthouse/SimpleMappr/blob/master/LICENSE
 * @link      http://github.com/dshorthouse/SimpleMappr
 */
class Router
{

    /**
     * Class constructor
     */
    public function __construct()
    {
        Session::setSession();
        mb_internal_encoding("UTF-8");
        mb_http_output("UTF-8");

        //set the default timezone
        date_default_timezone_set("America/Toronto");

        $this->_setRoutes();
    }

    /**
     * Set the controller for each route
     *
     * @return mixed
     */
    private function _setRoutes()
    {
        $router = new RouteCollector();

        $router->filter(
            'logAPI',
            function () {
                $this->_log("API");
            }
        );
        $router->filter(
            'logWMS',
            function () {
                $this->_log("WMS");
            }
        );
        $router->filter(
            'logWFS',
            function () {
                $this->_log("WFS");
            }
        );
        $router->filter(
            'check_role_user',
            function () {
                if (!User::checkPermission('user')) {
                    throw new HttpException(403, 'Sign in to access this resource.');
                }
                if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true)) { Session::requireCsrf(); }
            }
        );
        $router->filter(
            'check_role_administrator',
            function () {
                if (!User::checkPermission('administrator')) {
                    throw new HttpException(403, 'Sign in to access this resource.');
                }
                if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true)) { Session::requireCsrf(); }
            }
        );

        $router->get('/', function () {
            return $this->_main();
        });

        $router->get('/about', function () {
            Header::setHeader("html");
            Session::selectLocale();
            $citations = new Citation();
            $config = [
                'citations' => $citations->index()
            ];
            return $this->_twig()->render("about.html", $config);
        });

        $router->options('/api', function () {
            http_response_code(204);
        });

        $router->any('/api', function () {
            //headers are set in Mappr\WebServices\Api class
            $klass = $this->_klass("Mappr\WebServices\Api");
            return $klass->execute()->createOutput();
        }, ['after' => 'logAPI']);

        $router->get('/apidoc', function () {
            Header::setHeader('html');
            Session::selectLocale();
            $config = ['openapi' => $this->_klass("Controller\OpenApi")->index()];
            return $this->_twig()->render("apidoc.html", $config);
        });

        $router->get('/apilog', function () {
            Header::setHeader('html');
            return $this->_tailLog();
        }, ['before' => 'check_role_administrator']);

        $router->post('/application', function () {
            //headers are set in Mappr\Application class
            $klass = $this->_klass("Mappr\Application\Application");
            return $klass->execute()->createOutput();
        });

        $router->post('/application.json', function () {
            //headers are set in Mappr\Application class
            $klass = $this->_klass("Mappr\Application\Application");
            return $klass->execute()->createOutput();
        });

        $router->get('/citation.rss', function () {
            Header::setHeader('xml');
            $klass = $this->_klass("Controller\CitationFeed");
            $feed = $klass->makeChannel()->addItems()->getFeed();
            return $feed;
        });

        $router->group(['before' => 'check_role_administrator'], function ($router) {
            $router->get('/citation.json', function () {
                Header::setHeader("json");
                $klass = $this->_klass("Controller\Citation");
                return json_encode($klass->index(null));
            })
            ->get('/citation/{id:i}.json', function ($id) {
                Header::setHeader('json');
                return json_encode($this->_klass("Controller\Citation")->show($id));
            })
            ->post('/citation', function () {
                Header::setHeader("json");
                $klass = $this->_klass("Controller\Citation");
                return json_encode($klass->create($_POST['citation'] ?? null));
            })
            ->put('/citation/{id:i}', function ($id) {
                Header::setHeader("json");
                $klass = $this->_klass("Controller\Citation");
                $put = array();
                parse_str(file_get_contents('php://input'), $put);
                return json_encode($klass->update($put['citation'] ?? null, ['id'=>(int)$id]));
            })
            ->delete('/citation/{id:i}', function ($id) {
                Header::setHeader("json");
                $klass = $this->_klass("Controller\Citation");
                return json_encode($klass->destroy($id));
            });
        });

        $router->post('/docx', function () {
            //headers are set in Mappr\Formats\Docx class
            Session::selectLocale();
            $klass = $this->_klass("Mappr\Formats\Docx");
            return $klass->execute()->createOutput();
        });

        $router->get('/feedback', function () {
            $locale = Session::selectLocale();
            $config = [
                'locale' => $locale,
                'tweet' => ($locale['canonical'] == 'en') ? 'Tweet' : 'Tweeter',
                'url' => MAPPR_URL
            ];
            return $this->_twig()->render("feedback.html", $config);
        });

        $router->post('/flush_cache', function () {
            Assets::flushCache();
        }, ['before' => 'check_role_administrator']);

        $router->get('/help', function () {
            $config = [
                'locale' => Session::selectLocale()
            ];
            return $this->_twig()->render("help.html", $config);
        });

        $router->post('/geojson', function () {
            Header::setHeader('json', Utility::cleanFilename(Utility::loadParam('file_name', 'map'), 'json'));
            return json_encode(GeometryExport::features(array_replace($_POST, ['output'=>'json'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        });
        $router->post('/import', function () {
            Header::setHeader('json');
            if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($_FILES['file']['tmp_name'] ?? null) || !is_uploaded_file($_FILES['file']['tmp_name'])
                || ($_FILES['file']['size'] ?? 0) > RemoteImport::MAX_BYTES
            ) { throw new \InvalidArgumentException('Upload a file no larger than 5 MiB.'); }
            $bytes = file_get_contents($_FILES['file']['tmp_name'], false, null, 0, RemoteImport::MAX_BYTES + 1);
            return json_encode(ImportData::parse($bytes, (string)($_POST['input_format'] ?? 'auto'), (string)($_POST['encoding'] ?? 'UTF-8')), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        });

        $router->post('/kml', function () {
            $clean_filename = Utility::cleanFilename(Utility::loadParam('file_name', time()));
            $download_token = Utility::loadParam('download_token', md5(time()));
            setcookie("fileDownloadToken", $download_token, time()+3600, "/");
            Header::setHeader("kml", $clean_filename . ".kml");
            return $this->_klass("Controller\Kml")->create($_POST);
        });

        $router->get('/login', function () {
            Header::setHeader('html');
            return $this->_twig()->render('login.html', ['login_enabled'=>Session::configured()]);
        });
        $router->post('/logout', function () { Session::destroy(); });

        $router->get('/map/{id:i}', function ($id) { return $this->_privateMap($id, 'png'); });
        $router->get('/map/{id:i}.{ext:(?:kml|svg|json|png|jpg)}', function ($id, $ext) { return $this->_privateMap($id, $ext); });
        $router->get('/public/tmp/preview-{token:[0-9a-f]{32}}.{ext:(?:png|jpg|svg|tif)}', function ($token, $ext) {
            $path = TemporaryImage::readGuest($token, $ext);
            Header::setHeader($ext); $this->_privateHeaders();
            return file_get_contents($path);
        });
        $router->get('/_preview/{token:[0-9a-f]{32}}.{ext:(?:png|jpg|svg|tif)}', function ($token, $ext) {
            $path = TemporaryImage::read($token, $ext);
            Header::setHeader($ext); $this->_privateHeaders();
            return file_get_contents($path);
        });
        $router->get('/shared/{token:[0-9a-f]{64}}', function ($token) {
            $record = Controller\Share::loadToken($token);
            if (!$record) { throw new HttpException(404, 'Share not found.'); }
            Header::setHeader('html'); $this->_privateHeaders();
            return $this->_twig()->render('shared.html', ['map_title'=>$record['title'], 'share_path'=>MAPPR_URL.'/shared/'.$token]);
        });
        $router->get('/shared/{token:[0-9a-f]{64}}.{ext:(?:kml|svg|json|png|jpg)}', function ($token, $ext) {
            $record = Controller\Share::loadToken($token);
            if (!$record) { throw new HttpException(404, 'Share not found.'); }
            Header::setHeader($ext); $this->_privateHeaders();
            return (new \SimpleMappr\Mappr\Application\Map($record, $ext))->execute()->createOutput();
        });

        $router->get('/openapi.json', function () {
            Header::setHeader("json");
            return json_encode($this->_klass("Controller\OpenApi")->index());
        });

        $router->get('/places', function () {
            Header::setHeader("html");
            Session::selectLocale();
            $config = [
                'rows' => $this->_klass("Controller\Place")->index($_GET)->results
            ];
            return $this->_twig()->render("fragments/fragment.places.html", $config);
        });

        $router->get('/places.json', function () {
            Header::setHeader("json");
            return json_encode($this->_klass("Controller\Place")->index($_GET)->results);
        });

        $router->post('/pptx', function () {
            //headers set in Mappr\Pptx class
            Session::selectLocale();
            $klass = $this->_klass("Mappr\Formats\Pptx");
            return $klass->execute()->createOutput();
        });

        $router->post('/query', function () {
            Header::setHeader("json");
            $klass = $this->_klass("Mappr\Application\Query");
            return json_encode($klass->execute()->queryLayer()->data);
        });

        $router->post('/session', function () {
            if (Session::login($_POST)) { Session::redirect(SiteUrl::path().'/'.Session::makeLocaleParam($_SESSION['locale'] ?? 'en_US')); }
            http_response_code(401);
            return $this->_twig()->render('login.html', ['login_enabled'=>Session::configured(), 'login_error'=>'The username or password was not accepted.']);
        });

        $router->group(['before' => 'check_role_user'], function ($router) {
            $router->get('/share', function () {
                Header::setHeader('html');
                Session::selectLocale();
                $results = $this->_klass("Controller\Share")->index($_GET);
                $config = [
                    'rows' => $results->results,
                    'sort' => $results->sort,
                    'dir'  => $results->dir
                ];
                return $this->_twig()->render("fragments/fragment.share.html", $config);
            })
            ->post('/share', function () {
                Header::setHeader('json');
                return json_encode($this->_klass("Controller\Share")->create($_POST));
            })
            ->delete('/share/{id:i}', function ($id) {
                Header::setHeader('json');
                return json_encode($this->_klass("Controller\Share")->destroy($id));
            });
        });

        $router->group(['before' => 'check_role_administrator'], function ($router) {
            $router->get('/user', function () {
                Header::setHeader('html');
                Session::selectLocale();
                $results = $this->_klass("Controller\User")->index($_GET);
                $config = [
                    'total' => User::count(),
                    'rows'  => $results->results,
                    'sort'  => $results->sort,
                    'dir'   => $results->dir
                ];
                return $this->_twig()->render("fragments/fragment.user.html", $config);
            })
            ->delete('/user/{id:i}', function ($id) {
                Header::setHeader('json');
                return json_encode($this->_klass("Controller\User")->destroy($id));
            });
        });

        $router->group(['before' => 'check_role_user'], function ($router) {
            $router->get('/usermap', function () {
                Header::setHeader('html');
                Session::selectLocale();
                $results = $this->_klass("Controller\Map")->index($_GET);
                $config = [
                    'rows' => $results->results,
                    'total' => $results->total,
                    'sort' => $results->sort,
                    'dir' => $results->dir,
                    'filter_username' => $results->filter_username,
                    'filter_uid' => $results->filter_uid,
                    'row_count' => $results->row_count
                ];
                return $this->_twig()->render("fragments/fragment.usermap.html", $config);
            })
            ->post('/usermap/import', function () {
                $raw = $_POST['settings'] ?? file_get_contents('php://input');
                if (!is_string($raw)) { throw new HttpException(422, 'Invalid map settings.'); }
                Header::setHeader('json');
                return json_encode($this->_klass('Controller\\Map')->create(SavedMapDocument::decode($raw)));
            })
            ->get('/usermap/{id:i}/settings.json', function ($id) {
                $record = $this->_klass('Controller\\Map')->show($id);
                Header::setHeader('json', Utility::cleanFilename($record['title']).'.simplemappr.json'); $this->_privateHeaders();
                return SavedMapDocument::encode($record['map']);
            })
            ->get('/usermap/{id:i}.json', function ($id) {
                Header::setHeader('json');
                return json_encode($this->_klass("Controller\Map")->show($id));
            })
            ->post('/usermap', function () {
                Header::setHeader('json');
                return json_encode($this->_klass("Controller\Map")->create($_POST));
            })
            ->delete('/usermap/{id:i}', function ($id) {
                Header::setHeader('json');
                return json_encode($this->_klass("Controller\Map")->destroy($id));
            });
        });

        $router->any('/wfs', function () {
            Header::setHeader("xml");
            $klass = $this->_klass("Mappr\WebServices\Wfs");
            return $klass->makeService()->execute()->createOutput();
        }, ['after' => 'logWFS']);

        $router->any('/wms', function () {
            //headers are set in Mappr\Wms class
            $klass = $this->_klass("Mappr\WebServices\Wms");
            return $klass->makeService()->execute()->createOutput();
        }, ['after' => 'logWMS']);

        try {
            $dispatcher = new Dispatcher($router->getData());
            $parsed_url = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';
            $response = $dispatcher->dispatch($_SERVER['REQUEST_METHOD'], $parsed_url);
            echo $response;
        } catch (\Phroute\Phroute\Exception\HttpRouteNotFoundException $e) {
            echo $this->_renderError(404);

        } catch (HttpException $e) {
            http_response_code($e->status); Header::setHeader('json'); $this->_privateHeaders();
            echo json_encode(['status'=>'failed','error'=>$e->getMessage()]);
        } catch (\InvalidArgumentException|\JsonException $e) {
            http_response_code(400); Header::setHeader('json'); $this->_privateHeaders();
            echo json_encode(['status'=>'failed','error'=>$e->getMessage()]);
        } catch (\Throwable $e) {
            error_log((string)$e);
            http_response_code($e->getCode() === 503 ? 503 : 500);
            Header::setHeader('json'); $this->_privateHeaders();
            echo json_encode(['error' => $e->getCode() === 503 ? $e->getMessage() : 'Map rendering failed. Check the map settings and installed data.']);
        } finally { Session::closeSession(); }
    }

    private function _privateHeaders(): void
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }

    private function _privateMap(int $id, string $extension)
    {
        $user = Session::currentUser();
        $record = $user ? Controller\Map::loadOwned($id, (int)$user->uid) : null;
        if (!$record) { throw new HttpException(404, 'Map not found.'); }
        Header::setHeader($extension); $this->_privateHeaders();
        return (new \SimpleMappr\Mappr\Application\Map($record, $extension))->execute()->createOutput();
    }

    /**
     * Instantiates a new class and passes parameters.
     *
     * @param string $klass  The class name.
     * @param array  ...$params Splat array.
     *
     * @return object $klass  The instance of class.
     */
    private function _klass($klass, ...$params)
    {
        $class = __NAMESPACE__ . '\\' . $klass;
        return new $class(...$params);
    }

    /**
     * Write a timestamp and URI to the logger.
     *
     * @param string $type Type of log, defaults to "API"
     *
     * @return void
     */
    private function _log($type)
    {
        $ip = $_SERVER["REMOTE_ADDR"];
        $message = implode(" - ", [date('Y-m-d H:i:s'), $ip, $type, $_SERVER['REQUEST_METHOD'], $_SERVER["REQUEST_URI"]]);
        error_log($message);
    }

    /**
     * Instantiate the Logger class and execute its tail & parse methods
     *
     * @return string
     */
    private function _tailLog()
    {
        $logger = new Logger(ROOT.'/log/logger.log');
        $entries = $logger->tail(20)->parse(Logger::$capture)->entries;
        $lines = [];
        foreach ($entries as $entry) {
            if (isset($entry['time'],$entry['ip'],$entry['url'],$entry['method'])) {
                $lines[] = htmlspecialchars(implode(' - ',[$entry['time'],$entry['ip'],$entry['method'],mb_substr($entry['url'],0,250)]),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            }
        }
        return $lines ? implode("<br>\n",$lines) : 'Current request events are available through docker compose logs app. No archived log entries.';
    }

    /**
     * Redirect requests or set-up sessions
     *
     * @return array instance of Header class, locales, roles
     */
    private function _main()
    {
        if (!isset($_SERVER['HTTP_HOST'])) {
            echo $this->_renderError(400);
            exit();
        }

        if (MAPPR_PERSISTENCE) { Session::updateActivity(); } else { Session::selectLocale(); }
        $shapes_file = file_get_contents(Mappr::getShapefileConfigPath());
        $shapes = Yaml::parse($shapes_file)['environments'][ENVIRONMENT];
        unset($shapes['layers']['base'], $shapes['layers']['stateprovinces_polygon']);

        $config = [
            'og_url' => MAPPR_URL,
            'og_logo' => MAPPR_URL . '/public/images/logo_og.png',
            'layers' => array_combine(array_keys($shapes['layers']), array_column($shapes['layers'], 'name')),
            'labels' => array_combine(array_keys($shapes['labels']), array_column($shapes['labels'], 'name')),
            'projections' => AcceptedProjections::$projections,
            'marker_sizes' => AcceptedMarkers::sizes(),
            'marker_shapes' => AcceptedMarkers::$shapes,
            'locales' => Session::$accepted_locales,
            'num_textarea' => MAXNUMTEXTAREA
        ];
        return $this->_twig(true)->render("main.html", $config);
    }

    /**
     * Load twig templating engine
     *
     * @param boolean $include_page_elements Indicate if page elements are to be included.
     *
     * @return object
     */
    private function _twig($include_page_elements = false)
    {
        $loader = new FilesystemLoader(ROOT. "/views");
        $cache = (ENVIRONMENT == "development") ? false : ROOT . "/public/tmp";
        $reload = (ENVIRONMENT == "development" || ENVIRONMENT == "testing") ? true : false;
        $twig = new Environment($loader, ['cache' => $cache, 'auto_reload' => $reload]);
        $locale = Session::selectLocale()['locale'];
        if (!isset(Session::$accepted_locales[$locale])) { $locale = 'en_US'; }
        $translator = new Translator($locale);
        $translator->addLoader('po', new PoFileLoader());
        $messages = ROOT.'/i18n/'.Session::$accepted_locales[$locale]['code'].'/LC_MESSAGES/messages.po';
        if (is_file($messages)) {
            $translator->addResource('po', $messages, $locale);
            $catalogue = $translator->getCatalogue($locale);
            foreach ($catalogue->all('messages') as $id => $translation) {
                if ($translation === '') { $catalogue->set($id, $id); }
            }
        }
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addGlobal('environment', ENVIRONMENT);
        $twig->addGlobal('base', SiteUrl::path());

        $qlocale = "?locale={$locale}";

        $session = (array)(Session::currentUser() ?? []);
        unset($session['hash'], $session['identifier']);
        $twig->addGlobal('csrf_token', Session::csrfToken());
        $twig->addGlobal('login_enabled', Session::configured());
        $twig->addGlobal('session', $session);
        $twig->addGlobal('locale', $locale);
        $twig->addGlobal('qlocale', $qlocale);
        $twig->addGlobal('language', Session::$accepted_locales[$locale]['canonical']);
        $twig->addGlobal('roles', User::$roles);

        if ($include_page_elements) {
            $header = new Assets;
            $twig->addGlobal('stylesheet', $header->getCSSHeader());
            $twig->addGlobal('footer', $header->getJSVars() . $header->getJSFooter());
        }

        return $twig;
    }

    /**
     * Render an error document
     *
     * @param integer $code HTTP error code
     *
     * @return string
     */
    private function _renderError($code = 404)
    {
        Header::setHeader('html');
        http_response_code($code);
        $title = " - ";
        switch ($code) {
        case 400:
            $title .= "Bad Request";
            break;
        case 403:
            $title .= "Forbidden";
            break;
        case 404:
            $title .= "Not Found";
            break;
        default:
            $title .= "Error";
        }
        $config = [
            'title' => $title,
            'og_url' => MAPPR_URL,
        ];

        return $this->_twig(true)->render("{$code}.html", $config);
    }
}
