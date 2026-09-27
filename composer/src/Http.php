<?php

declare(strict_types=1);

namespace BonsaiLint\Composer;

final class Http
{
    public const REDIRECTS = 5;
    private const DEADLINE = 300;
    private const IDLE = 60;
    private const CHUNK = 65536;

    private string $userAgent;
    private $getenv;

    public function __construct(string $userAgent, callable $getenv)
    {
        $this->userAgent = $userAgent;
        $this->getenv = $getenv;
    }

    // Redirects are followed here rather than by PHP, so no header ever reaches another origin.
    public function get(string $url): array
    {
        $from = $url;
        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            $response = $this->request($url);
            if ($response['location'] === null || !in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            fclose($response['stream']);
            $url = self::follow($url, $response['location']);
        }
        throw new Failure(sprintf('downloading %s: more than %d redirects', $from, self::REDIRECTS));
    }

    public static function follow(string $from, string $location): string
    {
        if (preg_match('{^https?://}i', $location) === 1) {
            $next = $location;
        } elseif (strpos($location, '/') === 0 && strpos($location, '//') !== 0) {
            $next = self::origin($from) . $location;
        } else {
            throw new Failure(sprintf('downloading %s: cannot follow a redirect to %s', $from, $location));
        }
        if (stripos($from, 'https://') === 0 && stripos($next, 'https://') !== 0) {
            throw new Failure(sprintf('downloading %s: refused a redirect from https to %s', $from, $next));
        }
        return $next;
    }

    public static function save(array $response, string $url, $out, int $cap): string
    {
        $hash = hash_init('sha256');
        self::drain($response, $url, $cap, static function (string $chunk) use ($out, $hash, $url): void {
            hash_update($hash, $chunk);
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw new Failure(sprintf('downloading %s: cannot write the archive (%s)', $url, Failure::lastError()));
            }
        });
        return hash_final($hash);
    }

    // A failed open raises several warnings and the last one is the vaguest ("operation failed"),
    // so all of them are kept: a certificate problem is named only in the first.
    public static function explain(array $warnings): string
    {
        $reason = $warnings === [] ? 'unknown error' : implode('; ', array_unique($warnings));
        if (strpos($reason, 'certificate verify failed') !== false) {
            return 'PHP cannot verify the server\'s certificate; install the system CA certificates, or point openssl.cafile in php.ini at a CA bundle';
        }
        return (string) preg_replace('/\s+/', ' ', $reason);
    }

    private function request(string $url): array
    {
        $proxy = Proxy::for($url, $this->getenv);
        if ($proxy !== null && !$proxy['request_fulluri']) {
            self::tunnel($proxy, $url);
        }
        $context = stream_context_create(['http' => $this->options($url, $proxy)]);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = (string) preg_replace('/^[\w:]+\(.*?\): /', '', $message);
            return true;
        });
        try {
            $stream = fopen($url, 'rb', false, $context);
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            throw new Failure(sprintf('downloading %s: %s', $url, self::explain($warnings)));
        }
        return ['stream' => $stream] + self::headers(stream_get_meta_data($stream)['wrapper_data'] ?? []);
    }

    // PHP 8.4 and 8.5 can crash when a proxy refuses a CONNECT (seen on 8.4.10 and 8.5.4, not on
    // 8.4.26 or 8.5.11), so the proxy is asked first on a socket of our own, which also says why.
    private static function tunnel(array $proxy, string $url): void
    {
        $target = parse_url($url, PHP_URL_HOST) . ':' . (parse_url($url, PHP_URL_PORT) ?: 443);
        $socket = @stream_socket_client($proxy['proxy'], $errno, $error, 30);
        if ($socket === false) {
            throw new Failure(sprintf('downloading %s: cannot reach the proxy %s (%s)', $url, $proxy['proxy'], $error));
        }
        stream_set_timeout($socket, 60);
        $authorization = $proxy['authorization'] === null ? '' : 'Proxy-Authorization: ' . $proxy['authorization'] . "\r\n";
        fwrite($socket, "CONNECT $target HTTP/1.1\r\nHost: $target\r\n$authorization\r\n");
        $status = trim((string) fgets($socket));
        fclose($socket);
        if (preg_match('{^HTTP/\S+\s+2\d\d}', $status) !== 1) {
            throw new Failure(sprintf('downloading %s: the proxy refused to connect to %s (%s)', $url, $target, $status === '' ? 'no answer' : $status));
        }
    }

    private function options(string $url, ?array $proxy): array
    {
        $options = [
            'method' => 'GET',
            'follow_location' => 0,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
            'timeout' => 60,
            'header' => ['User-Agent: ' . $this->userAgent, 'Connection: close'],
        ];
        if ($proxy === null) {
            return $options;
        }
        $options['proxy'] = $proxy['proxy'];
        $options['request_fulluri'] = $proxy['request_fulluri'];
        if ($proxy['authorization'] !== null) {
            $options['header'][] = 'Proxy-Authorization: ' . $proxy['authorization'];
        }
        return $options;
    }

    // A proxy's own status line can come first, so only the last response counts.
    private static function headers(array $lines): array
    {
        $response = ['status' => 0, 'reason' => '', 'location' => null, 'length' => null];
        foreach ($lines as $line) {
            if (preg_match('{^HTTP/\S+\s+(\d{3})\s*(.*)$}', $line, $match) === 1) {
                $response = ['status' => (int) $match[1], 'reason' => trim($match[1] . ' ' . $match[2]), 'location' => null, 'length' => null];
                continue;
            }
            $response = self::header($response, $line);
        }
        return $response;
    }

    private static function header(array $response, string $line): array
    {
        $parts = explode(':', $line, 2);
        $value = trim($parts[1] ?? '');
        $field = strtolower(trim($parts[0]));
        if ($field === 'location') {
            $response['location'] = $value;
        } elseif ($field === 'content-length' && ctype_digit($value)) {
            $response['length'] = (int) $value;
        }
        return $response;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new Failure(sprintf('cannot follow a redirect from %s', $url));
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    private static function drain(array $response, string $url, int $cap, callable $sink): void
    {
        $stream = $response['stream'];
        $clock = ['deadline' => microtime(true) + self::DEADLINE, 'idle' => microtime(true) + self::IDLE];
        $total = 0;
        try {
            while (!feof($stream)) {
                $chunk = (string) fread($stream, self::CHUNK);
                $clock = self::tick($clock, $chunk !== '', $url);
                $total += strlen($chunk);
                if ($total > $cap) {
                    throw new Failure(sprintf('downloading %s: larger than %d bytes', $url, $cap));
                }
                if ($chunk !== '') {
                    $sink($chunk);
                }
            }
        } finally {
            fclose($stream);
        }
        self::complete($url, $total, $response['length']);
    }

    // PHP's own timed_out flag was seen set 4 ms into a healthy read on Windows, so the launcher keeps
    // its own clocks: a minute without data, or five minutes in all.
    private static function tick(array $clock, bool $data, string $url): array
    {
        $now = microtime(true);
        if ($now > $clock['deadline'] || (!$data && $now > $clock['idle'])) {
            throw new Failure(sprintf('downloading %s: timed out', $url));
        }
        if ($data) {
            $clock['idle'] = $now + self::IDLE;
        }
        return $clock;
    }

    private static function complete(string $url, int $total, ?int $length): void
    {
        if ($total === 0) {
            throw new Failure(sprintf('downloading %s: the response was empty', $url));
        }
        if ($length !== null && $total !== $length) {
            throw new Failure(sprintf('downloading %s: the connection closed after %d of %d bytes', $url, $total, $length));
        }
    }
}
