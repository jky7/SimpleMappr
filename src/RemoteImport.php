<?php
namespace SimpleMappr;

class RemoteImport
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SECONDS = 15;
    public const MAX_REDIRECTS = 3;

    public function fetch(string $url): string
    {
        $deadline = microtime(true) + self::MAX_SECONDS;
        for ($redirects = 0; ; $redirects++) {
            $target = self::target($url);
            $addresses = $this->resolve($target['host'], $deadline);
            if (!$addresses) {
                throw new \InvalidArgumentException('The import host could not be resolved.');
            }
            foreach ($addresses as $ip) {
                if (!self::publicAddress($ip)) {
                    throw new \InvalidArgumentException('Imports may only access public internet addresses.');
                }
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \InvalidArgumentException('The remote import timed out.');
            }
            $response = $this->transfer($target, $addresses[0], $remaining);
            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                if ($redirects >= self::MAX_REDIRECTS || $response['location'] === '') {
                    throw new \InvalidArgumentException('The remote import exceeded its redirect limit.');
                }
                $next = self::redirect($target['url'], $response['location']);
                if ($target['scheme'] === 'https' && self::target($next)['scheme'] !== 'https') {
                    throw new \InvalidArgumentException('The remote import redirected to an insecure connection.');
                }
                $url = $next;
                continue;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \InvalidArgumentException('The import server returned an unsuccessful response.');
            }
            if ($response['body'] === '') {
                throw new \InvalidArgumentException('The imported file is empty.');
            }
            return $response['body'];
        }
    }

    public static function target(string $url): array
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $url)) {
            throw new \InvalidArgumentException('The import URL is invalid.');
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Import URLs cannot contain credentials or fragments.');
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Import URLs must use HTTP or HTTPS.');
        }
        $host = strtolower(rtrim(trim($parts['host'] ?? '', '[]'), '.'));
        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException('The import host is invalid.');
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/iD', $host)) { throw new \InvalidArgumentException('The import host must use a canonical IP address or a DNS name.'); }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port !== ($scheme === 'https' ? 443 : 80)) {
            throw new \InvalidArgumentException('Import URLs must use their standard HTTP or HTTPS port.');
        }
        $authority = str_contains($host, ':') ? '['.$host.']' : $host;
        return ['url' => $scheme.'://'.$authority.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : ''), 'scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    public static function publicAddress(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        $packed = inet_pton($ip);
        if (strlen($packed) === 16) {
            return (ord($packed[0]) & 0xe0) === 0x20
                && substr($packed, 0, 2) !== "\x20\x02"
                && !(substr($packed, 0, 2) === "\x20\x01" && ord($packed[2]) < 2);
        }
        return ord($packed[0]) < 224 && substr($packed, 0, 3) !== "\xc0\x00\x00";
    }

    protected function resolve(string $host, float $deadline): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) { return [$host]; }
        return $this->resolveProcess([PHP_BINDIR.'/php', dirname(__DIR__).'/scripts/resolve-import-host'], $host, $deadline);
    }

    protected function resolveProcess(array $command, string $host, float $deadline): array
    {
        $process = proc_open($command, [['pipe','r'], ['pipe','w'], ['file','/dev/null','a']], $pipes);
        if (!is_resource($process)) { throw new \InvalidArgumentException('The import resolver is unavailable.'); }
        fwrite($pipes[0], $host); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $result = '';
        try {
            while (true) {
                $result .= stream_get_contents($pipes[1]);
                if (strlen($result) > 65536) { throw new \InvalidArgumentException('The import DNS response is too large.'); }
                if (!proc_get_status($process)['running']) { break; }
                if (microtime(true) >= $deadline) { throw new \InvalidArgumentException('The import host resolution timed out.'); }
                usleep(10000);
            }
            $result .= stream_get_contents($pipes[1]);
            $addresses = json_decode($result, true);
            return is_array($addresses) ? $addresses : [];
        } finally {
            proc_terminate($process, 9);
            fclose($pipes[1]); proc_close($process);
        }
    }

    protected function transfer(array $target, string $ip, float $remaining): array
    {
        $body = '';
        $location = '';
        $tooLarge = false;
        $headerBytes = 0;
        $handle = curl_init($target['url']);
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        curl_setopt_array($handle, [
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$address],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, (int)ceil($remaining * 1000)),
            CURLOPT_TIMEOUT_MS => (int)ceil($remaining * 1000),
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'SimpleMappr import',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location, &$headerBytes, &$tooLarge): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 65536) { return 0; }
                if (preg_match('/^HTTP\//i', $line)) { $location = ''; }
                if (stripos($line, 'Location:') === 0) { $location = trim(substr($line, 9)); }
                if (stripos($line, 'Content-Length:') === 0 && (float)trim(substr($line, 15)) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $success = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($success === false) {
            throw new \InvalidArgumentException($tooLarge ? 'The imported file exceeds the 5 MiB limit.' : 'The remote import failed or timed out.');
        }
        return ['status' => $status, 'body' => $body, 'location' => $location];
    }

    public static function redirect(string $base, string $location): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) { return $location; }
        $parts = parse_url($base);
        if (str_starts_with($location, '//')) { return $parts['scheme'].':'.$location; }
        $host = $parts['scheme'].'://'.$parts['host'];
        if (str_starts_with($location, '/')) { return $host.$location; }
        if (str_starts_with($location, '?')) { return $host.($parts['path'] ?? '/').$location; }
        $path = $parts['path'] ?? '/';
        return $host.substr($path, 0, strrpos($path, '/') + 1).$location;
    }
}
