<?php

// Runs the launcher from a test fixture instead of Release.php:
// php launch.php <release.json> resolve|run [args…]

declare(strict_types=1);

use BonsaiLint\Composer\Launcher;
use BonsaiLint\Composer\Process;

foreach (['Failure', 'Platform', 'Proxy', 'Http', 'Archive', 'Process', 'Release', 'Launcher'] as $class) {
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}

$release = json_decode((string) file_get_contents($argv[1]), true);
$mode = $argv[2];
$args = array_slice($argv, 3);

exit(Launcher::guarded(static function () use ($release, $mode, $args): int {
    // guarded() installs the production handler, which lets deprecations pass; tests fail on them.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $launcher = new Launcher($release['package'], $release['version'], $release['archives'], [Launcher::class, 'env'], PHP_OS_FAMILY, php_uname('m'), STDERR);
    $binary = $launcher->resolve();
    if ($mode === 'resolve') {
        echo $binary;
        return 0;
    }
    return Process::run($binary, $args);
}));
