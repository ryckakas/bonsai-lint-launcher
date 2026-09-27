<?php

declare(strict_types=1);

namespace BonsaiLint\Composer;

// Composer's own reading of the proxy variables, since that is what PHP developers already set.
final class Proxy
{
    public static function for(string $url, callable $getenv): ?array
    {
        $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        $setting = self::variable($getenv, $https ? 'https_proxy' : 'http_proxy');
        if ($setting === '' || self::bypassed($url, self::variable($getenv, 'no_proxy'))) {
            return null;
        }
        return self::parse($setting, !$https);
    }

    private static function variable(callable $getenv, string $name): string
    {
        $value = (string) $getenv($name);
        return $value !== '' ? $value : (string) $getenv(strtoupper($name));
    }

    private static function parse(string $setting, bool $fullUri): array
    {
        $parts = parse_url(strpos($setting, '://') === false ? 'http://' . $setting : $setting);
        if ($parts === false || !isset($parts['host'])) {
            throw new Failure('the proxy in the environment is not a URL');
        }
        $secure = strtolower($parts['scheme'] ?? '') === 'https';
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        return [
            'proxy' => ($secure ? 'ssl://' : 'tcp://') . $parts['host'] . ':' . $port,
            'request_fulluri' => $fullUri,
            'authorization' => self::authorization($parts),
        ];
    }

    private static function authorization(array $parts): ?string
    {
        if (!isset($parts['user'])) {
            return null;
        }
        $credentials = rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? '');
        return 'Basic ' . base64_encode($credentials);
    }

    private static function bypassed(string $url, string $noProxy): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        $port = parse_url($url, PHP_URL_PORT);
        if (!is_int($port)) {
            $port = $https ? 443 : 80;
        }
        foreach (preg_split('/[\s,]+/', strtolower($noProxy), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
            if (self::matches($rule, $host, $port)) {
                return true;
            }
        }
        return false;
    }

    private static function matches(string $rule, string $host, int $port): bool
    {
        if ($rule === '*') {
            return true;
        }
        [$ruleHost, $rulePort] = self::splitPort($rule);
        if ($rulePort !== null && $rulePort !== $port) {
            return false;
        }
        if (strpos($ruleHost, '/') !== false) {
            return self::inRange($host, $ruleHost);
        }
        $ruleHost = ltrim($ruleHost, '.');
        return $host === $ruleHost || substr($host, -strlen($ruleHost) - 1) === '.' . $ruleHost;
    }

    private static function splitPort(string $rule): array
    {
        if (preg_match('/^\[(.+)\](?::(\d+))?$/', $rule, $match)) {
            return [$match[1], isset($match[2]) ? (int) $match[2] : null];
        }
        if (substr_count($rule, ':') === 1) {
            [$host, $port] = explode(':', $rule);
            return [$host, ctype_digit($port) ? (int) $port : null];
        }
        return [$rule, null];
    }

    private static function inRange(string $host, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range, 2);
        if (!filter_var($host, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP) || !ctype_digit($bits)) {
            return false;
        }
        $address = (string) inet_pton($host);
        $network = (string) inet_pton($subnet);
        if (strlen($address) !== strlen($network) || (int) $bits > strlen($address) * 8) {
            return false;
        }
        return self::prefix($address, (int) $bits) === self::prefix($network, (int) $bits);
    }

    private static function prefix(string $address, int $bits): string
    {
        $bytes = intdiv($bits, 8);
        $prefix = substr($address, 0, $bytes);
        if ($bits % 8 === 0) {
            return $prefix;
        }
        return $prefix . chr(ord($address[$bytes]) & (0xFF << (8 - $bits % 8)) & 0xFF);
    }
}
