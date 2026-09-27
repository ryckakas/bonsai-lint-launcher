<?php

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

use BonsaiLint\Composer\Launcher;

function the_launcher_is_transparent(): void
{
    $args = ['a b', 'q"uote', '100%', '!bang', ''];
    foreach (Fixture::modes() as $flags) {
        $command = array_merge([PHP_BINARY], $flags, [Fixture::bin(), path(__DIR__, 'helper.php')], $args);

        $result = Fixture::run($command, ['BONSAI_LINT_BINARY' => PHP_BINARY, 'HELPER_EXIT' => '3'], 'input');

        same(3, $result['code'], implode(' ', $flags));
        same('', $result['stderr']);
        same(['args' => $args, 'stdin' => 'input'], json_decode($result['stdout'], true));
    }
}

function a_child_killed_by_a_signal_exits_128_plus_the_signal(): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        skip('Windows has no signals');
    }
    foreach (Fixture::modes() as $flags) {
        $command = array_merge([PHP_BINARY], $flags, [Fixture::bin(), '-c', 'kill -INT $$']);

        $result = Fixture::run($command, ['BONSAI_LINT_BINARY' => '/bin/sh']);

        same(130, $result['code'], implode(' ', $flags));
    }
}

function stdout_stays_empty_during_a_download(): void
{
    $host = Fixture::host();
    if ($host === null || PHP_OS_FAMILY === 'Windows') {
        skip('the fake binary is a shell script');
    }
    $release = Fixture::release($host);
    foreach (Fixture::modes() as $flags) {
        $env = ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => Fixture::directory()];

        $result = Fixture::launch($release, 'run', [], $env, $flags);

        same(0, $result['code'], $result['stderr']);
        same('', $result['stdout']);
        holds('bonsai-lint: downloading v1.2.3 for ' . $host, $result['stderr']);
    }
}

function php_errors_go_to_stderr_before_anything_runs(): void
{
    $code = Launcher::guarded(static function (): int {
        return ini_get('display_errors') === 'stderr' ? 0 : 3;
    });
    restore_error_handler();

    same(0, $code);
    $bin = (string) file_get_contents(Fixture::bin());
    $ini = strpos($bin, "ini_set('display_errors', 'stderr');");
    same(true, $ini !== false && $ini < strpos($bin, 'require_once'));
}

function a_future_deprecation_never_stops_a_published_launcher(): void
{
    same(true, Launcher::escalate(E_DEPRECATED, 'deprecated', __FILE__, __LINE__));
    same(true, Launcher::escalate(E_USER_DEPRECATED, 'deprecated', __FILE__, __LINE__));
    try {
        Launcher::escalate(E_WARNING, 'a warning', __FILE__, __LINE__);
    } catch (\ErrorException $warning) {
        same('a warning', $warning->getMessage());
        return;
    }
    throw new \LogicException('a warning did not throw');
}

function a_php_warning_never_reaches_stdout(): void
{
    $host = Fixture::host() ?? Fixture::MUSL;
    $release = ['archives' => [$host => [Fixture::archiveName($host), str_repeat('0', 64)]]];
    $env = ['BONSAI_LINT_DOWNLOAD_URL' => 'http://127.0.0.1:1', 'BONSAI_LINT_CACHE' => Fixture::directory()];

    $result = Fixture::launch($release, 'run', [], $env, ['-d', 'display_errors=1', '-d', 'error_reporting=-1']);

    same(1, $result['code']);
    same('', $result['stdout']);
    holds('bonsai-lint: downloading http://127.0.0.1:1/', $result['stderr']);
}
