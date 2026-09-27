<?php

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

use BonsaiLint\Composer\Launcher;

function the_first_run_downloads_verifies_and_caches(): void
{
    $release = Fixture::release(Fixture::MUSL, 'the binary');
    $cache = Fixture::directory();
    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => $cache], $release['archives']);

    same(null, $run['failure']);
    same(path($cache, '1.2.3', Fixture::MUSL, 'bonsai-lint'), $run['binary']);
    same('the binary', file_get_contents($run['binary']));
    same("bonsai-lint: downloading v1.2.3 for x86_64-unknown-linux-musl…\n", $run['stderr']);
    if (PHP_OS_FAMILY !== 'Windows') {
        same('755', substr(sprintf('%o', fileperms($run['binary'])), -3));
    }
    same([], Fixture::leftovers(dirname($run['binary'])));
}

function a_cached_binary_runs_without_the_network_or_a_message(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $env = ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => Fixture::directory()];
    $package = Fixture::directory();
    $first = Fixture::resolve($package, $env, $release['archives']);
    Fixture::forget();

    $second = Fixture::resolve($package, $env, $release['archives']);

    same($first['binary'], $second['binary']);
    same('', $second['stderr']);
    same([], Fixture::requests());
}

function the_cache_defaults_to_the_package_directory(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $package = Fixture::directory();

    $run = Fixture::resolve($package, ['BONSAI_LINT_DOWNLOAD_URL' => $release['base']], $release['archives']);

    same(path($package, 'composer', 'cache', '1.2.3', Fixture::MUSL, 'bonsai-lint'), $run['binary']);
}

function a_relative_cache_is_taken_from_the_working_directory(): void
{
    $release = Fixture::release(Fixture::MUSL);
    chdir(Fixture::directory());

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => 'relative'], $release['archives']);

    same(path(getcwd(), 'relative', '1.2.3', Fixture::MUSL, 'bonsai-lint'), $run['binary']);
}

function the_binary_override_skips_everything_even_unreleased(): void
{
    chdir(Fixture::directory());

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_BINARY' => 'bin/custom'], [], '', 'FreeBSD', 'amd64');

    same(null, $run['failure']);
    same(getcwd() . DIRECTORY_SEPARATOR . 'bin/custom', $run['binary']);
    same([], Fixture::requests());
}

function an_unreleased_launcher_says_so(): void
{
    $run = Fixture::resolve(Fixture::directory(), [], [], '');

    same('this launcher is an unreleased build with no binary to fetch; require a released version (composer require --dev bonsai-lint/bonsai-lint) or set BONSAI_LINT_BINARY', $run['failure']);
}

function an_unwritable_cache_names_the_variable(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $file = path(Fixture::directory(), 'file');
    file_put_contents($file, '');

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => path($file, 'cache')], $release['archives']);

    holds('; set BONSAI_LINT_CACHE', $run['failure']);
}

function concurrent_first_runs_download_once_and_all_succeed(): void
{
    $host = Fixture::host();
    if ($host === null) {
        skip('there is no prebuilt binary for this host');
    }
    $release = Fixture::release($host);
    $env = ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => Fixture::directory()];
    $command = Fixture::launcherCommand($release, 'resolve');

    $handles = [];
    for ($run = 0; $run < 4; $run++) {
        $handles[] = Fixture::spawn($command, $env);
    }
    $results = array_map([Fixture::class, 'wait'], $handles);

    foreach ($results as $result) {
        same(0, $result['code'], $result['stderr']);
    }
    same(1, count(array_unique(array_column($results, 'stdout'))));
    $fetches = array_filter(Fixture::requests(), static function (array $request) use ($host): bool {
        return $request['path'] === '/files/' . Fixture::archiveName($host);
    });
    same(1, count($fetches));
}

function an_existing_destination_counts_as_installed(): void
{
    $dir = Fixture::directory();
    file_put_contents(path($dir, 'bonsai-lint'), 'placed by another run');

    Launcher::place(path($dir, '.binary-gone'), path($dir, 'bonsai-lint'));

    same('placed by another run', file_get_contents(path($dir, 'bonsai-lint')));
}

function a_rename_that_fails_without_a_destination_is_an_error(): void
{
    $dir = Fixture::directory();

    holds('cannot install ' . path($dir, 'bonsai-lint'), fails(static function () use ($dir): void {
        Launcher::place(path($dir, '.binary-gone'), path($dir, 'bonsai-lint'));
    }));
}
