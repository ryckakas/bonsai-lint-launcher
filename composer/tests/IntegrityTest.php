<?php

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

use BonsaiLint\Composer\Http;

function an_archive_that_fails_its_checksum_installs_nothing(): void
{
    $release = Fixture::release(Fixture::MUSL, Fixture::FAKE, str_repeat('0', 64));
    $cache = Fixture::directory();

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => $cache], $release['archives']);

    holds('does not match its published checksum (expected ' . str_repeat('0', 64) . ', got ', $run['failure']);
    holds('; nothing was installed', $run['failure']);
    $dir = path($cache, '1.2.3', Fixture::MUSL);
    same(false, file_exists(path($dir, 'bonsai-lint')));
    same([], Fixture::leftovers($dir));
}

function an_empty_download_is_refused(): void
{
    $name = Fixture::archiveName(Fixture::MUSL);
    Fixture::publish($name, '');

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => Fixture::server() . '/files', 'BONSAI_LINT_CACHE' => Fixture::directory()], [Fixture::MUSL => [$name, hash('sha256', '')]]);

    holds('the response was empty', $run['failure']);
}

function a_download_over_the_cap_is_refused(): void
{
    $url = Fixture::publish('big.bin', str_repeat('x', 100));
    $out = fopen(path(Fixture::directory(), 'out'), 'wb');
    $http = new Http('test', Fixture::getenv([]));

    $failure = fails(static function () use ($http, $url, $out): void {
        Http::save($http->get($url), $url, $out, 10);
    });

    holds('larger than 10 bytes', $failure);
}

function a_short_write_is_refused(): void
{
    $url = Fixture::publish('x.bin', str_repeat('x', 100));
    $http = new Http('test', Fixture::getenv([]));
    $response = $http->get($url);

    if (!in_array('full-disk', stream_get_wrappers(), true)) {
        stream_wrapper_register('full-disk', FullDisk::class);
    }
    $out = fopen('full-disk://out', 'wb');

    $failure = fails(static function () use ($response, $url, $out): void {
        Http::save($response, $url, $out, 1 << 20);
    });

    holds('cannot write the archive', $failure);
}

function a_download_cut_short_says_so(): void
{
    Fixture::publish('short.bin', str_repeat('x', 100));
    $url = Fixture::server() . '/short/short.bin';
    $out = fopen(path(Fixture::directory(), 'out'), 'wb');
    $http = new Http('test', Fixture::getenv([]));

    $failure = fails(static function () use ($http, $url, $out): void {
        Http::save($http->get($url), $url, $out, 1000);
    });

    holds('the connection closed after 100 of 200 bytes', $failure);
}
