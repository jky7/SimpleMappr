<?php
namespace SimpleMappr;

final class SocialLoginConfig
{
    private const PROVIDERS = [
        'google' => [
            'name' => 'Google',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
        ],
        'microsoft' => [
            'name' => 'Microsoft',
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'jwks_url' => 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
        ],
    ];

    public static function providers(): array
    {
        $providers = [];
        foreach (self::configuration() as $id => $config) {
            $providers[] = ['id' => $id, 'name' => $config['name']];
        }
        return $providers;
    }

    public static function provider(string $id): array
    {
        $config = self::configuration();
        if (!isset($config[$id])) {
            throw new HttpException(404, 'This sign-in provider is not available.');
        }
        return $config[$id];
    }

    public static function fingerprint(string $id): ?string
    {
        $config = self::configuration()[$id] ?? null;
        return $config === null ? null : hash('sha256', json_encode([
            $id, $config['client_id'], $config['client_secret'], $config['redirect_uri'],
        ], JSON_THROW_ON_ERROR));
    }

    public static function validate(): void
    {
        self::configuration();
    }

    private static function configuration(): array
    {
        $result = [];
        foreach (self::PROVIDERS as $id => $provider) {
            $prefix = 'SIMPLEMAPPR_'.strtoupper($id);
            $client = getenv($prefix.'_CLIENT_ID');
            $secret = getenv($prefix.'_CLIENT_SECRET');
            $client = $client === false ? '' : $client;
            $secret = $secret === false ? '' : $secret;
            if ($client === '' && $secret === '') {
                continue;
            }
            if ($client === '' || $secret === '' || strlen($client) > 512 || strlen($secret) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $client.$secret)) {
                throw new \RuntimeException('Configure both client ID and secret for '.$provider['name'].'.');
            }
            if (!MAPPR_PERSISTENCE) {
                throw new \RuntimeException('Social sign-in requires database persistence.');
            }
            $origin = getenv('SIMPLEMAPPR_URL') ?: '';
            $parts = parse_url($origin);
            if (!is_array($parts) || !isset($parts['host']) || filter_var($origin, FILTER_VALIDATE_URL) === false
                || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || !in_array($parts['path'] ?? '', ['', '/'], true) || preg_match('/[\s\\\\]/', $origin)
                || isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
                throw new \RuntimeException('Social sign-in requires SIMPLEMAPPR_URL as an absolute origin without a path.');
            }
            if ($parts['scheme'] !== 'https' && !in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true)) {
                throw new \RuntimeException('Social sign-in requires HTTPS outside localhost.');
            }
            $result[$id] = $provider + [
                'id' => $id,
                'client_id' => $client,
                'client_secret' => $secret,
                'redirect_uri' => rtrim($origin, '/').'/auth/'.$id.'/callback',
            ];
        }
        return $result;
    }
}
