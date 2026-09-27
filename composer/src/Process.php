<?php

declare(strict_types=1);

namespace BonsaiLint\Composer;

final class Process
{
    private const POLL = 10000;

    public static function run(string $binary, array $args): int
    {
        if (!is_file($binary)) {
            throw new Failure(sprintf('cannot run %s: no such file', $binary));
        }
        // The binary replaces PHP, so signals, the terminal and the exit status are its own.
        if (self::canExec()) {
            @pcntl_exec($binary, $args);
            throw new Failure(sprintf('cannot run %s: %s', $binary, pcntl_strerror(pcntl_get_last_error())));
        }
        return self::spawn($binary, $args);
    }

    public static function canExec(): bool
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('pcntl_exec')) {
            return false;
        }
        // PHP 7.4 keeps a disabled function defined, so function_exists alone cannot tell.
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('pcntl_exec', $disabled, true);
    }

    private static function spawn(string $binary, array $args): int
    {
        // Ctrl+C reaches every process on a Windows console; the binary handles it, not PHP.
        if (PHP_SAPI === 'cli' && function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function (int $event): void {
            });
        }
        $process = @proc_open(array_merge([$binary], array_values($args)), [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) {
            throw new Failure(sprintf('cannot run %s: %s', $binary, Failure::lastError()));
        }
        // proc_close() reports a signal death as the raw wait status, so the last poll decides.
        $status = self::status($process, $binary);
        while ($status['running']) {
            usleep(self::POLL);
            $status = self::status($process, $binary);
        }
        proc_close($process);
        if ($status['signaled']) {
            return 128 + $status['termsig'];
        }
        return $status['exitcode'] >= 0 ? $status['exitcode'] : 1;
    }

    private static function status($process, string $binary): array
    {
        $status = proc_get_status($process);
        if ($status === false) {
            throw new Failure(sprintf('lost track of %s', $binary));
        }
        return $status;
    }
}
