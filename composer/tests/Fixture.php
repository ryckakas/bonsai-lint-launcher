<?php

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

use BonsaiLint\Composer\Archive;
use BonsaiLint\Composer\Failure;
use BonsaiLint\Composer\Launcher;
use BonsaiLint\Composer\Platform;

final class Skipped extends \RuntimeException
{
}

// A stream that accepts no bytes, as a full disk does.
final class FullDisk
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }
}

function same($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        $prefix = $what === '' ? '' : $what . ': ';
        throw new \LogicException(sprintf('%sexpected %s, got %s', $prefix, var_export($expected, true), var_export($actual, true)));
    }
}

function holds(string $needle, ?string $haystack): void
{
    if ($haystack === null || strpos($haystack, $needle) === false) {
        throw new \LogicException(sprintf('expected %s in %s', var_export($needle, true), var_export($haystack, true)));
    }
}

function fails(callable $action): string
{
    try {
        $action();
    } catch (Failure $failure) {
        return $failure->getMessage();
    }
    throw new \LogicException('expected a Failure');
}

function skip(string $reason): void
{
    throw new Skipped($reason);
}

function path(string ...$parts): string
{
    return implode(DIRECTORY_SEPARATOR, $parts);
}

final class Fixture
{
    public const FAKE = "#!/bin/sh\nexit 0\n";
    public const MUSL = 'x86_64-unknown-linux-musl';

    private static ?array $server = null;
    private static array $paths = [];
    private static ?string $cwd = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function bin(): string
    {
        return path(self::root(), 'composer', 'bin', 'bonsai-lint');
    }

    public static function host(): ?string
    {
        return Platform::triple(PHP_OS_FAMILY, php_uname('m'), (string) getenv('PROCESSOR_ARCHITEW6432'));
    }

    public static function directory(): string
    {
        self::$cwd = self::$cwd ?? getcwd();
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bonsai-lint-composer-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        self::$paths[] = $dir;
        return (string) realpath($dir);
    }

    public static function archiveName(string $triple): string
    {
        return 'bonsai-lint-' . $triple . (substr($triple, -13) === '-windows-msvc' ? '.zip' : '.tar.gz');
    }

    public static function resolve(string $package, array $env, array $archives, string $version = '1.2.3', string $family = 'Linux', string $machine = 'x86_64'): array
    {
        $stderr = fopen('php://memory', 'w+');
        $getenv = self::getenv($env);
        $result = ['binary' => null, 'failure' => null];
        try {
            $result['binary'] = (new Launcher($package, $version, $archives, $getenv, $family, $machine, $stderr))->resolve();
        } catch (Failure $failure) {
            $result['failure'] = $failure->getMessage();
        }
        rewind($stderr);
        $result['stderr'] = stream_get_contents($stderr);
        return $result;
    }

    public static function leftovers(string $dir): array
    {
        return array_values(array_filter((array) scandir($dir), static function (string $file): bool {
            return strpos($file, '.archive-') === 0 || strpos($file, '.binary-') === 0;
        }));
    }

    public static function server(): string
    {
        self::$server = self::$server ?? self::start();
        return 'http://127.0.0.1:' . self::$server['port'];
    }

    public static function port(): int
    {
        self::server();
        return self::$server['port'];
    }

    public static function publish(string $name, string $content): string
    {
        self::server();
        file_put_contents(self::$server['root'] . '/files/' . $name, $content);
        return self::server() . '/files/' . $name;
    }

    public static function release(string $triple, string $content = self::FAKE, ?string $checksum = null): array
    {
        $name = self::archiveName($triple);
        $binary = Platform::executable($triple);
        $stem = 'bonsai-lint-' . $triple;
        $archive = substr($name, -4) === '.zip'
            ? self::zip([['name' => 'README.md', 'content' => 'readme'], ['name' => $binary, 'content' => $content]])
            : self::tarGz([
                ['name' => $stem . '/', 'type' => '5'],
                ['name' => $stem . '/README.md', 'content' => 'readme', 'mode' => 0644],
                ['name' => $stem . '/' . $binary, 'content' => $content],
            ]);
        self::publish($name, $archive);
        return ['base' => self::server() . '/files', 'archives' => [$triple => [$name, $checksum ?? hash('sha256', $archive)]]];
    }

    public static function launch(array $release, string $mode, array $args = [], array $env = [], array $flags = [], string $stdin = ''): array
    {
        return self::run(self::launcherCommand($release, $mode, $args, $flags), $env, $stdin);
    }

    public static function launcherCommand(array $release, string $mode, array $args = [], array $flags = []): array
    {
        $fixture = path(self::directory(), 'release.json');
        $package = $release['package'] ?? self::directory();
        file_put_contents($fixture, json_encode(['package' => $package, 'version' => $release['version'] ?? '1.2.3', 'archives' => $release['archives']]));
        return array_merge([PHP_BINARY], $flags, [path(__DIR__, 'launch.php'), $fixture, $mode], $args);
    }

    public static function modes(): array
    {
        return PHP_OS_FAMILY === 'Windows' ? [[]] : [[], ['-d', 'disable_functions=pcntl_exec']];
    }

    public static function getenv(array $env): callable
    {
        return static function (string $name) use ($env): string {
            return (string) ($env[$name] ?? '');
        };
    }

    public static function extract(string $bytes, string $name, string $binary = 'bonsai-lint'): string
    {
        $dir = self::directory();
        file_put_contents(path($dir, $name), $bytes);
        Archive::extract(path($dir, $name), $name, $binary, path($dir, 'out'), 1 << 20);
        return (string) file_get_contents(path($dir, 'out'));
    }

    public static function requests(): array
    {
        if (self::$server === null || !is_file(self::$server['root'] . '/requests.log')) {
            return [];
        }
        $lines = file(self::$server['root'] . '/requests.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map(static function (string $line): array {
            return json_decode($line, true);
        }, $lines);
    }

    public static function forget(): void
    {
        if (self::$server !== null) {
            @unlink(self::$server['root'] . '/requests.log');
        }
    }

    public static function tarGz(array $entries): string
    {
        $tar = '';
        foreach ($entries as $entry) {
            $content = $entry['content'] ?? '';
            $tar .= self::tarHeader($entry, strlen($content)) . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
        }
        return (string) gzencode($tar . str_repeat("\0", 1024));
    }

    public static function zip(array $entries, bool $zip64 = false): string
    {
        $data = '';
        $directory = '';
        foreach ($entries as $entry) {
            $content = $entry['content'] ?? '';
            $method = $entry['method'] ?? 8;
            $body = $method === 8 ? (string) gzdeflate($content) : $content;
            $crc = $entry['crc'] ?? crc32($content);
            $name = $entry['name'];
            $offset = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, $crc, strlen($body), strlen($content), strlen($name), 0) . $name . $body;
            $external = ($entry['mode'] ?? 0100755) << 16;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x031e, 20, 0, $method, 0, 0, $crc, strlen($body), strlen($content), strlen($name), 0, 0, 0, 0, $external, $offset) . $name;
        }
        $count = $zip64 ? 0xFFFF : count($entries);
        return $data . $directory . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($directory), strlen($data), 0);
    }

    public static function run(array $command, array $env = [], string $stdin = ''): array
    {
        return self::wait(self::spawn($command, $env, $stdin));
    }

    public static function spawn(array $command, array $env = [], string $stdin = ''): array
    {
        $dir = self::directory();
        $files = ['in' => path($dir, 'in'), 'out' => path($dir, 'out'), 'err' => path($dir, 'err')];
        file_put_contents($files['in'], $stdin);
        $spec = [['file', $files['in'], 'r'], ['file', $files['out'], 'w'], ['file', $files['err'], 'w']];
        $process = proc_open($command, $spec, $pipes, null, array_merge(self::environment(), $env));
        return ['process' => $process] + $files;
    }

    public static function wait(array $handle): array
    {
        $status = proc_get_status($handle['process']);
        while ($status['running']) {
            usleep(10000);
            $status = proc_get_status($handle['process']);
        }
        proc_close($handle['process']);
        return [
            'code' => $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'],
            'stdout' => (string) file_get_contents($handle['out']),
            'stderr' => (string) file_get_contents($handle['err']),
        ];
    }

    public static function cleanup(): void
    {
        if (self::$cwd !== null) {
            chdir(self::$cwd);
        }
        foreach (self::$paths as $path) {
            self::delete($path);
        }
        self::$paths = [];
        if (self::$server !== null) {
            self::forget();
            foreach ((array) glob(self::$server['root'] . '/files/*') as $file) {
                unlink($file);
            }
        }
    }

    public static function stop(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server['process']);
            proc_close(self::$server['process']);
            self::delete(self::$server['root']);
            self::$server = null;
        }
    }

    private static function environment(): array
    {
        return array_filter(getenv(), static function (string $name): bool {
            return strpos(strtoupper($name), 'BONSAI_LINT_') !== 0 && stripos($name, 'proxy') === false;
        }, ARRAY_FILTER_USE_KEY);
    }

    private static function start(): array
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bonsai-lint-composer-server-' . bin2hex(random_bytes(6));
        mkdir($root . '/files', 0777, true);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $log = $root . DIRECTORY_SEPARATOR . 'server.log';
        $spec = [['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], ['file', $log, 'a'], ['file', $log, 'a']];
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, __DIR__ . DIRECTORY_SEPARATOR . 'server.php'];
        $process = proc_open($command, $spec, $pipes);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
            if ($client !== false) {
                fclose($client);
                return ['process' => $process, 'port' => $port, 'root' => $root];
            }
            usleep(50000);
        }
        throw new \RuntimeException('the test server did not start: ' . file_get_contents($log));
    }

    private static function tarHeader(array $entry, int $size): string
    {
        $header = str_pad($entry['name'], 100, "\0")
            . sprintf("%07o\0%07o\0%07o\0%011o\0%011o\0", $entry['mode'] ?? 0755, 0, 0, $size, 0)
            . '        '
            . ($entry['type'] ?? '0')
            . str_pad($entry['link'] ?? '', 100, "\0")
            . ($entry['magic'] ?? "ustar  \0");
        $header = str_pad($header, 512, "\0");
        if (isset($entry['prefix'])) {
            $header = substr_replace($header, str_pad($entry['prefix'], 155, "\0"), 345, 155);
        }
        $sum = array_sum(array_map('ord', str_split($header)));
        return substr_replace($header, sprintf("%06o\0 ", $sum), 148, 8);
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::delete($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
