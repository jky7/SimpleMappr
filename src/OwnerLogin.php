<?php
namespace SimpleMappr;

/** Where the local owner signs in. Unlisted, so a deployment can move it with SIMPLEMAPPR_OWNER_LOGIN_PATH. */
final class OwnerLogin
{
    public const DEFAULT_PATH = '/owner';
    public const PATTERN = '#^/[a-z0-9][a-z0-9-]{2,62}$#D';
    private const RESERVED = [
        'about', 'api', 'apidoc', 'application', 'auth', 'citation', 'data-credits', 'feedback', 'geojson',
        'help', 'import', 'kml', 'login', 'logout', 'openapi', 'places', 'public', 'query', 'session', 'share', 'shared', 'user', 'usermap', 'wfs', 'wms',
    ];

    public static function path(): string
    {
        $path = getenv('SIMPLEMAPPR_OWNER_LOGIN_PATH');
        if ($path === false || $path === '') { return self::DEFAULT_PATH; }
        if (!preg_match(self::PATTERN, $path)) {
            throw new \RuntimeException('SIMPLEMAPPR_OWNER_LOGIN_PATH must be one lowercase path segment of 3 to 63 letters, digits or hyphens, such as /owner-4f9c2a1b.');
        }
        if (in_array(substr($path, 1), self::RESERVED, true)) {
            throw new \RuntimeException('SIMPLEMAPPR_OWNER_LOGIN_PATH collides with a built-in page; choose another name.');
        }
        return $path;
    }
}
