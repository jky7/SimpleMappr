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

final class Session
{
    public static $accepted_locales = [
        'en_US' => ['canonical'=>'en','locale'=>'en_US','hreflang'=>'en-us','native'=>'English','code'=>'en_US.UTF-8'],
        'fr_FR' => ['canonical'=>'fr','locale'=>'fr_FR','hreflang'=>'fr-fr','native'=>'Français','code'=>'fr_FR.UTF-8'],
    ];
    public static $domain = 'messages';

    public static function setSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '7200');
        session_name('simplemappr_session');
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with(getenv('SIMPLEMAPPR_URL') ?: '', 'https://');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function closeSession(): void { if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); } }
    public static function csrfToken(): string { self::setSession(); return $_SESSION['csrf']; }

    public static function requireCsrf(?string $token = null): void
    {
        $token ??= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals(self::csrfToken(), $token)) { throw new HttpException(403, 'Invalid CSRF token. Reload the page and try again.'); }
    }

    public static function configured(): bool
    {
        return self::localConfigured() || (MAPPR_PERSISTENCE && SocialLoginConfig::providers() !== []);
    }

    public static function localConfigured(): bool
    {
        $hash = getenv('SIMPLEMAPPR_OWNER_PASSWORD_HASH') ?: '';
        return MAPPR_PERSISTENCE && (getenv('SIMPLEMAPPR_OWNER_USERNAME') ?: '') !== '' && password_get_info($hash)['algo'] !== null;
    }

    private static function fingerprint(): string
    {
        return hash('sha256', (getenv('SIMPLEMAPPR_OWNER_USERNAME') ?: '')."\0".(getenv('SIMPLEMAPPR_OWNER_PASSWORD_HASH') ?: ''));
    }

    public static function currentUser(): ?object
    {
        self::setSession();
        if (!MAPPR_PERSISTENCE || !isset($_SESSION['uid'])) { return null; }
        $method = $_SESSION['authentication_method'] ?? 'local';
        $fingerprint = match ($method) {
            'local' => self::localConfigured() ? self::fingerprint() : null,
            'google', 'microsoft' => SocialLoginConfig::fingerprint($method),
            default => null,
        };
        if ($fingerprint === null || !hash_equals($fingerprint, $_SESSION['credential_fingerprint'] ?? '') || ($_SESSION['activity'] ?? 0) < time()-7200) {
            unset($_SESSION['uid'], $_SESSION['credential_fingerprint'], $_SESSION['activity'], $_SESSION['authentication_method']);
            return null;
        }
        $user = (new User)->show((int)$_SESSION['uid'])->results;
        if (!$user) { unset($_SESSION['uid']); return null; }
        if ($method !== 'local' && !str_starts_with($user->identifier, 'oidc:'.$method.':')) {
            unset($_SESSION['uid']);
            return null;
        }
        $_SESSION['activity'] = time();
        return $user;
    }

    public static function login(#[\SensitiveParameter] array $input): bool
    {
        self::requireCsrf();
        if (!self::localConfigured()) { return false; }
        $db = Database::getInstance();
        $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local');
        $now = time();
        $db->exec('DELETE FROM login_attempts WHERE window_start < '.($now-900));
        $db->prepare('INSERT INTO login_attempts(attempt_key,window_start,attempts) VALUES(:key,:now,1) ON DUPLICATE KEY UPDATE attempts=attempts+1');
        $db->bindParam(':key', $key); $db->bindParam(':now', $now); $db->execute();
        $db->prepare('SELECT attempts FROM login_attempts WHERE attempt_key=:key'); $db->bindParam(':key', $key);
        if ($db->fetchFirstObject()->attempts > 10) { throw new HttpException(429, 'Too many login attempts. Try again in 15 minutes.'); }
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        if (!is_string($username) || !is_string($password) || strlen($password) > 4096) { return false; }
        $hash = getenv('SIMPLEMAPPR_OWNER_PASSWORD_HASH');
        if (!password_verify($password, $hash) || !hash_equals(getenv('SIMPLEMAPPR_OWNER_USERNAME'), $username)) { return false; }
        $user = (new User)->showByIdentifier('local:owner')->results;
        if (!$user || !hash_equals($hash, $user->hash)) { return false; }
        self::establish($user, 'local', self::fingerprint());
        $db->prepare('DELETE FROM login_attempts WHERE attempt_key=:key'); $db->bindParam(':key', $key); $db->execute();
        return true;
    }

    public static function loginSocial(string $provider, #[\SensitiveParameter] array $callback): void
    {
        $identity = (new SocialLogin())->complete($provider, $callback);
        $user = (new User)->resolveSocialIdentity($identity);
        self::establish($user, $provider, SocialLoginConfig::fingerprint($provider));
    }

    private static function establish(object $user, string $method, string $fingerprint): void
    {
        $locale = $_SESSION['locale'] ?? 'en_US';
        session_regenerate_id(true);
        $_SESSION = [
            'locale' => $locale,
            'uid' => (int)$user->uid,
            'authentication_method' => $method,
            'credential_fingerprint' => $fingerprint,
            'activity' => time(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
    }

    public static function destroy(): void
    {
        self::requireCsrf();
        $locale = $_SESSION['locale'] ?? 'en_US';
        $_SESSION = ['locale'=>$locale, 'csrf'=>bin2hex(random_bytes(32))];
        session_regenerate_id(true);
        self::redirect(SiteUrl::path().'/'.self::makeLocaleParam($locale));
    }

    public static function updateActivity(): void { self::selectLocale(); self::currentUser(); }
    public static function redirect($url): never { header('Location: '.$url, true, 303); exit; }
    public static function makeLocaleParam($locale = ''): string { return $locale && $locale !== 'en_US' ? '?locale='.rawurlencode($locale) : ''; }

    public static function selectLocale(): array
    {
        self::setSession();
        $choice = $_GET['locale'] ?? $_POST['locale'] ?? $_SESSION['locale'] ?? 'en_US';
        if (!is_string($choice) || !isset(self::$accepted_locales[$choice])) { throw new HttpException(404, 'Unknown language.'); }
        $_SESSION['locale'] = $choice;
        $locale = self::$accepted_locales[$choice];
        putenv('LC_ALL='.$locale['code']);
        setlocale(LC_MESSAGES, $locale['code']);
        bindtextdomain(self::$domain, ROOT.'/i18n');
        bind_textdomain_codeset(self::$domain, 'UTF-8');
        textdomain(self::$domain);
        return $locale;
    }
}
