<?php
namespace SimpleMappr;

final class SiteUrl
{
    public static function base(?array $server = null, ?string $configured = null): string
    {
        $server ??= $_SERVER;
        $configured ??= MAPPR_URL;
        if ($configured !== '' && !str_starts_with($configured,'/')) {
            $parts = parse_url($configured);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '',['http','https'],true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\s\\\\]/',$configured) || filter_var($configured,FILTER_VALIDATE_URL) === false) { throw new \RuntimeException('Configure SIMPLEMAPPR_URL as an HTTP or HTTPS base URL.'); }
            return rtrim($configured,'/');
        }
        if (!preg_match('#^(?:/[A-Za-z0-9._~-]+)*$#D',$configured)) { throw new \RuntimeException('Invalid deployment URL path.'); }
        $host = $server['HTTP_HOST'] ?? 'localhost';
        if (!is_string($host) || !preg_match('/^(?:[a-z0-9.-]+|\[[0-9a-f:]+\])(?::[0-9]{1,5})?$/iD',$host) || filter_var('http://'.$host,FILTER_VALIDATE_URL) === false) { throw new HttpException(400,'Invalid request host.'); }
        return ((!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http').'://'.$host.$configured;
    }
}
