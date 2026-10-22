<?php
namespace SimpleMappr;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

final class SocialLogin
{
    private const FLOW_TTL = 300;
    private const CLOCK_SKEW = 60;
    private const MAX_FLOWS = 5;
    private const MAX_BODY = 1048576;
    private ?\Closure $request;

    public function __construct(?callable $request = null)
    {
        $this->request = $request === null ? null : \Closure::fromCallable($request);
    }

    public function begin(string $provider): string
    {
        Session::requireCsrf();
        if (isset($_SESSION['uid'])) {
            throw new HttpException(409, 'Sign out before signing in with another account.');
        }
        $config = SocialLoginConfig::provider($provider);
        $now = time();
        $flows = $_SESSION['social_flows'] ?? [];
        $flows = array_filter($flows, static fn(array $flow): bool => $flow['created'] > $now - self::FLOW_TTL);
        while (count($flows) >= self::MAX_FLOWS) {
            array_shift($flows);
        }
        $state = self::base64Url(random_bytes(32));
        $nonce = self::base64Url(random_bytes(32));
        $verifier = self::base64Url(random_bytes(32));
        $flows[$state] = [
            'provider' => $provider, 'nonce' => $nonce, 'verifier' => $verifier,
            'created' => $now, 'fingerprint' => SocialLoginConfig::fingerprint($provider),
        ];
        $_SESSION['social_flows'] = $flows;
        return $config['authorize_url'].'?'.http_build_query([
            'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code', 'response_mode' => 'query', 'scope' => 'openid profile',
            'state' => $state, 'nonce' => $nonce, 'code_challenge_method' => 'S256',
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function complete(string $provider, #[\SensitiveParameter] array $query): SocialIdentity
    {
        Session::setSession();
        $state = $query['state'] ?? null;
        if (!is_string($state) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $state)
            || !isset($_SESSION['social_flows'][$state])) {
            throw new HttpException(400, 'This sign-in request has expired. Start again.');
        }
        $flow = $_SESSION['social_flows'][$state];
        unset($_SESSION['social_flows'][$state]);
        try {
            if (isset($_SESSION['uid']) || $flow['provider'] !== $provider
                || $flow['created'] <= time() - self::FLOW_TTL || $flow['created'] > time()
                || !hash_equals($flow['fingerprint'], SocialLoginConfig::fingerprint($provider) ?? '')) {
                throw new \UnexpectedValueException();
            }
            if (count($query) > 16) {
                throw new \UnexpectedValueException();
            }
            foreach ($query as $name => $value) {
                if (!is_string($name) || strlen($name) > 64 || !is_string($value)
                    || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new \UnexpectedValueException();
                }
            }
            if (isset($query['error']) || !isset($query['code']) || $query['code'] === '') {
                throw new \UnexpectedValueException();
            }
            $config = SocialLoginConfig::provider($provider);
            $tokens = $this->fetch($config['token_url'], [
                'grant_type' => 'authorization_code', 'code' => $query['code'],
                'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
                'redirect_uri' => $config['redirect_uri'], 'code_verifier' => $flow['verifier'],
            ]);
            if (!isset($tokens['id_token']) || !is_string($tokens['id_token'])
                || isset($tokens['error'])
                || !is_string($tokens['token_type'] ?? null)
                || strcasecmp($tokens['token_type'], 'Bearer') !== 0) {
                throw new \UnexpectedValueException();
            }
            return $this->identity($provider, $tokens['id_token'], $config, $flow);
        } catch (\Throwable) {
            throw new HttpException(400, 'Sign-in could not be verified. Start again.');
        }
    }

    private function identity(string $provider, #[\SensitiveParameter] string $token, array $config, array $flow): SocialIdentity
    {
        if (strlen($token) > 32768 || !preg_match('/\A([A-Za-z0-9_-]{1,2048})\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\z/', $token, $parts)) {
            throw new \UnexpectedValueException();
        }
        $header = json_decode(self::decode64($parts[1]), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256'
            || !is_string($header['kid'] ?? null) || $header['kid'] === '' || strlen($header['kid']) > 256
            || isset($header['typ']) && $header['typ'] !== 'JWT'
            || array_diff(array_keys($header), ['alg', 'kid', 'typ', 'x5t', 'x5t#S256'])) {
            throw new \UnexpectedValueException();
        }
        foreach (['x5t', 'x5t#S256'] as $name) {
            if (isset($header[$name]) && (!is_string($header[$name]) || strlen($header[$name]) > 256)) {
                throw new \UnexpectedValueException();
            }
        }
        $jwks = $this->fetch($config['jwks_url'], null);
        if (!isset($jwks['keys']) || !is_array($jwks['keys']) || !array_is_list($jwks['keys'])
            || count($jwks['keys']) > 64) {
            throw new \UnexpectedValueException();
        }
        $matches = array_values(array_filter($jwks['keys'], static fn($key): bool => is_array($key) && ($key['kid'] ?? null) === $header['kid']));
        if (count($matches) !== 1) {
            throw new \UnexpectedValueException();
        }
        $jwk = $matches[0];
        if (($jwk['kty'] ?? null) !== 'RSA' || ($jwk['alg'] ?? 'RS256') !== 'RS256'
            || ($jwk['use'] ?? 'sig') !== 'sig'
            || isset($jwk['key_ops']) && $jwk['key_ops'] !== ['verify']
            || array_intersect(array_keys($jwk), ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k', 'jku', 'x5u'])
            || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) {
            throw new \UnexpectedValueException();
        }
        $modulus = self::decode64($jwk['n']);
        $exponent = self::decode64($jwk['e']);
        if (strlen($modulus) < 256 || strlen($modulus) > 1024 || ord($modulus[0]) < 128
            || strlen($exponent) < 1 || strlen($exponent) > 4
            || ord($exponent[0]) === 0 || (ord(substr($exponent, -1)) & 1) !== 1
            || strlen($exponent) === 1 && ord($exponent) < 3) {
            throw new \UnexpectedValueException();
        }
        $key = JWK::parseKey($jwk, 'RS256');
        $previousLeeway = JWT::$leeway;
        try {
            JWT::$leeway = self::CLOCK_SKEW;
            $claims = (array)JWT::decode($token, $key);
        } finally {
            JWT::$leeway = $previousLeeway;
        }
        $now = time();
        foreach (['iss', 'sub', 'nonce'] as $name) {
            if (!is_string($claims[$name] ?? null) || $claims[$name] === '' || strlen($claims[$name]) > 2048) {
                throw new \UnexpectedValueException();
            }
        }
        if (!is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)
            || $claims['iat'] < $flow['created'] - self::CLOCK_SKEW || $claims['iat'] > $now + self::CLOCK_SKEW
            || $claims['exp'] <= $now - self::CLOCK_SKEW
            || $claims['exp'] <= $claims['iat']
            || array_key_exists('nbf', $claims) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + self::CLOCK_SKEW || $claims['nbf'] >= $claims['exp'])
            || !hash_equals($flow['nonce'], $claims['nonce'])) {
            throw new \UnexpectedValueException();
        }
        $audiences = is_string($claims['aud'] ?? null) ? [$claims['aud']] : ($claims['aud'] ?? null);
        if (!is_array($audiences) || !array_is_list($audiences) || !$audiences || count($audiences) > 16
            || count(array_filter($audiences, 'is_string')) !== count($audiences)
            || !in_array($config['client_id'], $audiences, true)
            || (array_key_exists('azp', $claims) || count($audiences) > 1) && ($claims['azp'] ?? null) !== $config['client_id']) {
            throw new \UnexpectedValueException();
        }
        if ($provider === 'google') {
            if (!in_array($claims['iss'], ['accounts.google.com', 'https://accounts.google.com'], true)) {
                throw new \UnexpectedValueException();
            }
            $issuer = 'https://accounts.google.com';
        } else {
            $tenant = $claims['tid'] ?? null;
            if (!is_string($tenant) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $tenant)) {
                throw new \UnexpectedValueException();
            }
            $issuer = 'https://login.microsoftonline.com/'.$tenant.'/v2.0';
            if ($claims['iss'] !== $issuer || !is_string($jwk['issuer'] ?? null)
                || str_replace('{tenantid}', $tenant, $jwk['issuer']) !== $issuer) {
                throw new \UnexpectedValueException();
            }
        }
        $name = is_string($claims['name'] ?? null) ? $claims['name'] : '';
        $name = trim(preg_replace('/[\p{C}]/u', '', $name) ?? '');
        if ($name === '') {
            $name = $config['name'].' user';
        }
        return new SocialIdentity($provider, 'oidc:'.$provider.':'.hash('sha256', $issuer."\0".$claims['sub']), mb_substr($name, 0, 125, 'UTF-8'));
    }

    private function fetch(string $url, #[\SensitiveParameter] ?array $form): array
    {
        if ($this->request !== null) {
            return ($this->request)($url, $form);
        }
        $body = '';
        $headers = 0;
        $curl = curl_init($url);
        try {
            curl_setopt_array($curl, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    $headers += strlen($line);
                    return $headers <= 65536 ? strlen($line) : 0;
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BODY) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if ($form !== null) {
                curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986)]);
            }
            if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \UnexpectedValueException();
            }
            $value = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value) || array_is_list($value)) {
                throw new \UnexpectedValueException();
            }
            return $value;
        } finally {
            curl_close($curl);
        }
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode64(string $value): string
    {
        if ($value === '' || strlen($value) > 32768 || !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            throw new \UnexpectedValueException();
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || self::base64Url($decoded) !== $value) {
            throw new \UnexpectedValueException();
        }
        return $decoded;
    }
}
