<?php

// The launcher's tests, dependency-free like the launcher: php composer/tests/run.php [filter]

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

use BonsaiLint\Composer\Launcher;

foreach (['Failure', 'Platform', 'Proxy', 'Http', 'Archive', 'Process', 'Release', 'Launcher'] as $class) {
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}
require_once __DIR__ . '/Fixture.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

function run_one(string $test): bool
{
    $name = substr($test, strrpos($test, '\\') + 1);
    try {
        $test();
        echo "ok    $name\n";
    } catch (Skipped $skipped) {
        echo "skip  $name: {$skipped->getMessage()}\n";
    } catch (\Throwable $error) {
        printf("FAIL  %s\n      %s: %s (%s:%d)\n", $name, get_class($error), $error->getMessage(), basename($error->getFile()), $error->getLine());
        return false;
    } finally {
        Fixture::cleanup();
    }
    return true;
}

$filter = $argv[1] ?? '';
$ran = 0;
$failed = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $before = get_defined_functions()['user'];
    require $file;
    foreach (array_diff(get_defined_functions()['user'], $before) as $test) {
        if ($filter === '' || strpos($test, $filter) !== false) {
            $ran++;
            $failed += run_one($test) ? 0 : 1;
        }
    }
}
Fixture::stop();
printf("%d tests, %d failed\n", $ran, $failed);
exit($failed === 0 ? 0 : 1);
