<?php

declare(strict_types=1);

namespace BonsaiLint\Composer;

final class Launcher
{
    public const ARCHIVE_CAP = 256 << 20;
    public const BINARY_CAP = 512 << 20;
    private const RELEASES = 'https://github.com/ryckakas/bonsai-lint/releases/download/v';

    private string $packageDir;
    private string $version;
    private array $archives;
    private $getenv;
    private string $family;
    private string $machine;
    private $stderr;

    public function __construct(string $packageDir, string $version, array $archives, callable $getenv, string $family, string $machine, $stderr)
    {
        $this->packageDir = $packageDir;
        $this->version = $version;
        $this->archives = $archives;
        $this->getenv = $getenv;
        $this->family = $family;
        $this->machine = $machine;
        $this->stderr = $stderr;
    }

    public static function main(string $packageDir, array $args): int
    {
        return self::guarded(static function () use ($packageDir, $args): int {
            $launcher = new self($packageDir, Release::VERSION, Release::ARCHIVES, [self::class, 'env'], PHP_OS_FAMILY, php_uname('m'), STDERR);
            return Process::run($launcher->resolve(), $args);
        });
    }

    public static function guarded(callable $body): int
    {
        // PHP's CLI prints errors on stdout, which carries the binary's JSON.
        ini_set('display_errors', 'stderr');
        set_error_handler([self::class, 'escalate']);
        try {
            return $body();
        } catch (Failure $failure) {
            fwrite(STDERR, 'bonsai-lint: ' . $failure->getMessage() . "\n");
        } catch (\Throwable $error) {
            fwrite(STDERR, 'bonsai-lint: launcher error: ' . $error->getMessage() . "\n");
        }
        return 1;
    }

    // A published version runs on PHP releases that do not exist yet, so their deprecations must
    // not stop it; the tests turn deprecations into failures with a stricter handler.
    public static function escalate(int $severity, string $message, string $file, int $line): bool
    {
        if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
            return true;
        }
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public static function env(string $name): string
    {
        return (string) getenv($name);
    }

    // A cached binary is trusted as it is: whoever can write the cache can already rewrite this
    // launcher, and hashing it on every run would slow each editor scan down.
    public function resolve(): string
    {
        $override = ($this->getenv)('BONSAI_LINT_BINARY');
        if ($override !== '') {
            return self::absolute($override);
        }
        if ($this->version === '') {
            throw new Failure('this launcher is an unreleased build with no binary to fetch; require a released version (composer require --dev bonsai-lint/bonsai-lint) or set BONSAI_LINT_BINARY');
        }
        $triple = Platform::triple($this->family, $this->machine, ($this->getenv)('PROCESSOR_ARCHITEW6432'));
        if ($triple === null || !isset($this->archives[$triple])) {
            throw new Failure(sprintf(
                'bonsai-lint v%s: there is no prebuilt binary for %s %s; install it with `cargo install bonsai-lint`, or set BONSAI_LINT_BINARY to a binary you have',
                $this->version,
                $this->family,
                $this->machine
            ));
        }
        $dir = $this->cacheRoot() . DIRECTORY_SEPARATOR . $this->version . DIRECTORY_SEPARATOR . $triple;
        $binary = $dir . DIRECTORY_SEPARATOR . Platform::executable($triple);
        if (!is_file($binary)) {
            $this->install($triple, $dir, $binary);
        }
        return $binary;
    }

    // A concurrent first run may have got there first, and its copy is the same bytes, so an
    // existing destination counts as success. Antivirus scanners briefly hold a fresh .exe open.
    public static function place(string $extracted, string $binary): void
    {
        $deadline = microtime(true) + 2;
        while (!@rename($extracted, $binary) && !is_file($binary)) {
            if (PHP_OS_FAMILY !== 'Windows' || microtime(true) > $deadline) {
                throw new Failure(sprintf('cannot install %s (%s)', $binary, Failure::lastError()));
            }
            usleep(50000);
        }
        clearstatcache(true, $binary);
        if (filesize($binary) === 0) {
            @unlink($binary);
            throw new Failure(sprintf('%s was emptied after it was installed, as antivirus software can do; run bonsai-lint again', $binary));
        }
    }

    private function cacheRoot(): string
    {
        $root = ($this->getenv)('BONSAI_LINT_CACHE');
        if ($root !== '') {
            return self::absolute($root);
        }
        return $this->packageDir . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'cache';
    }

    // The same variable dist's shell and PowerShell installers read, with the same meaning.
    private function downloadBase(): string
    {
        $base = ($this->getenv)('BONSAI_LINT_DOWNLOAD_URL');
        return $base !== '' ? rtrim($base, '/') : self::RELEASES . $this->version;
    }

    private function install(string $triple, string $dir, string $binary): void
    {
        $base = $this->downloadBase();
        self::preflight($base);
        self::makeDirectory($dir);
        $lock = self::lock(dirname($dir) . DIRECTORY_SEPARATOR . '.' . $triple . '.lock');
        try {
            if (!is_file($binary)) {
                fwrite($this->stderr, sprintf("bonsai-lint: downloading v%s for %s…\n", $this->version, $triple));
                $this->download($base . '/' . $this->archives[$triple][0], $triple, $dir, $binary);
            }
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    // Nothing unverified is ever unpacked: the archive must match the checksum this launcher was
    // released with.
    private function download(string $url, string $triple, string $dir, string $binary): void
    {
        [$name, $expected] = $this->archives[$triple];
        $archive = self::temporary($dir, '.archive-');
        $extracted = self::temporary($dir, '.binary-');
        try {
            $actual = $this->fetch($url, $archive);
            if (!hash_equals($expected, $actual)) {
                throw new Failure(sprintf('%s does not match its published checksum (expected %s, got %s); nothing was installed', $url, $expected, $actual));
            }
            Archive::extract($archive, $name, Platform::executable($triple), $extracted, self::BINARY_CAP);
            chmod($extracted, 0755);
            self::place($extracted, $binary);
        } finally {
            self::remove($archive);
            self::remove($extracted);
        }
    }

    private function fetch(string $url, string $path): string
    {
        $response = (new Http('bonsai-lint-composer/' . $this->version, $this->getenv))->get($url);
        if ($response['status'] !== 200) {
            fclose($response['stream']);
            throw new Failure(sprintf('downloading %s: %s', $url, $response['reason']));
        }
        $out = @fopen($path, 'xb');
        if ($out === false) {
            fclose($response['stream']);
            throw new Failure(sprintf('cannot write %s (%s)', $path, Failure::lastError()));
        }
        try {
            return Http::save($response, $url, $out, self::ARCHIVE_CAP);
        } finally {
            fclose($out);
        }
    }

    private static function preflight(string $base): void
    {
        $fix = ', or set BONSAI_LINT_BINARY to a binary you have';
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new Failure('downloading needs allow_url_fopen; set allow_url_fopen=1 in php.ini' . $fix);
        }
        if (stripos($base, 'https://') === 0 && !in_array('https', stream_get_wrappers(), true)) {
            throw new Failure('downloading needs the openssl extension; enable extension=openssl in php.ini' . $fix);
        }
        if (!function_exists('inflate_init') || !in_array('compress.zlib', stream_get_wrappers(), true)) {
            throw new Failure('unpacking needs the zlib extension; enable it in php.ini' . $fix);
        }
    }

    // Concurrent first runs create the same parents, and PHP's recursive mkdir gives up when one
    // appears under it; each attempt gets further.
    private static function makeDirectory(string $dir): void
    {
        for ($attempt = 0; $attempt < 5 && !is_dir($dir); $attempt++) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new Failure(sprintf('cannot create %s (%s); set BONSAI_LINT_CACHE', $dir, Failure::lastError()));
        }
    }

    private static function lock(string $path)
    {
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        if (!@flock($handle, LOCK_EX)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private static function temporary(string $dir, string $prefix): string
    {
        return $dir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function absolute(string $path): string
    {
        if (preg_match('{^([a-zA-Z]:)?[/\\\\]}', $path) === 1) {
            return $path;
        }
        return getcwd() . DIRECTORY_SEPARATOR . $path;
    }
}
