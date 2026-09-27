<?php

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

use BonsaiLint\Composer\Http;
use BonsaiLint\Composer\Proxy;

function a_redirect_to_another_host_is_followed(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $base = Fixture::server() . '/elsewhere';

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $base, 'BONSAI_LINT_CACHE' => Fixture::directory()], $release['archives']);

    same(null, $run['failure']);
    same(['127.0.0.1:' . Fixture::port(), 'localhost:' . Fixture::port()], array_column(Fixture::requests(), 'host'));
}

function redirects_stop_after_five(): void
{
    Fixture::publish('x.bin', 'data');
    $http = new Http('test', Fixture::getenv([]));

    $response = $http->get(Fixture::server() . '/redirect/5/x.bin');
    fclose($response['stream']);

    same(200, $response['status']);
    holds('more than 5 redirects', fails(static function () use ($http): void {
        $http->get(Fixture::server() . '/redirect/6/x.bin');
    }));
}

function a_redirect_from_https_to_http_is_refused(): void
{
    same('https://github.com/b', Http::follow('https://github.com/a', '/b'));
    same('http://localhost:8/b', Http::follow('http://127.0.0.1:8/a', 'http://localhost:8/b'));
    holds('refused a redirect from https to http://example.com/b', fails(static function (): void {
        Http::follow('https://github.com/a', 'http://example.com/b');
    }));
}

function no_request_carries_an_authorization_header_even_with_tokens_in_the_environment(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $env = [
        'BONSAI_LINT_DOWNLOAD_URL' => Fixture::server() . '/elsewhere',
        'BONSAI_LINT_CACHE' => Fixture::directory(),
        'GITHUB_TOKEN' => 'ghp_secret',
        'GH_TOKEN' => 'ghp_secret',
        'COMPOSER_AUTH' => '{"github-oauth":{"github.com":"ghp_secret"}}',
    ];

    $run = Fixture::resolve(Fixture::directory(), $env, $release['archives']);

    same(null, $run['failure']);
    foreach (Fixture::requests() as $request) {
        same(false, isset($request['headers']['authorization']), $request['target']);
    }
}

function only_the_archive_is_requested(): void
{
    $release = Fixture::release(Fixture::MUSL);

    Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => Fixture::directory()], $release['archives']);

    same(['/files/' . Fixture::archiveName(Fixture::MUSL)], array_column(Fixture::requests(), 'path'));
}

function every_request_names_the_launcher_in_its_user_agent(): void
{
    $release = Fixture::release(Fixture::MUSL);

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => Fixture::server() . '/elsewhere', 'BONSAI_LINT_CACHE' => Fixture::directory()], $release['archives']);

    same(null, $run['failure']);
    same(2, count(Fixture::requests()));
    foreach (Fixture::requests() as $request) {
        same('bonsai-lint-composer/1.2.3', $request['headers']['user-agent'] ?? null);
    }
}

function a_download_error_names_the_url_and_status(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $base = Fixture::server() . '/status/500';

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $base, 'BONSAI_LINT_CACHE' => Fixture::directory()], $release['archives']);

    same('downloading ' . $base . '/' . Fixture::archiveName(Fixture::MUSL) . ': 500 Internal Server Error', $run['failure']);
}

function a_missing_archive_names_the_url_and_404(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $base = Fixture::server() . '/files/missing';

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $base, 'BONSAI_LINT_CACHE' => Fixture::directory()], $release['archives']);

    same('downloading ' . $base . '/' . Fixture::archiveName(Fixture::MUSL) . ': 404 Not Found', $run['failure']);
}

function a_mirror_replaces_the_github_url(): void
{
    $release = Fixture::release(Fixture::MUSL);

    $run = Fixture::resolve(Fixture::directory(), ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'] . '/', 'BONSAI_LINT_CACHE' => Fixture::directory()], $release['archives']);

    same(null, $run['failure']);
    same(['/files/' . Fixture::archiveName(Fixture::MUSL)], array_column(Fixture::requests(), 'target'));
}

function a_proxy_from_the_environment_carries_the_download(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $env = [
        'http_proxy' => 'http://user:secret@127.0.0.1:' . Fixture::port(),
        'BONSAI_LINT_DOWNLOAD_URL' => 'http://origin.invalid/files',
        'BONSAI_LINT_CACHE' => Fixture::directory(),
    ];

    $run = Fixture::resolve(Fixture::directory(), $env, $release['archives']);

    same(null, $run['failure']);
    $request = Fixture::requests()[0];
    same('http://origin.invalid/files/' . Fixture::archiveName(Fixture::MUSL), $request['target']);
    same('Basic ' . base64_encode('user:secret'), $request['headers']['proxy-authorization'] ?? null);
}

function the_proxy_is_chosen_again_for_every_redirect(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $env = [
        'http_proxy' => 'http://u:p@127.0.0.1:' . Fixture::port(),
        'no_proxy' => 'localhost',
        'BONSAI_LINT_DOWNLOAD_URL' => Fixture::server() . '/elsewhere',
        'BONSAI_LINT_CACHE' => Fixture::directory(),
    ];

    $run = Fixture::resolve(Fixture::directory(), $env, $release['archives']);

    same(null, $run['failure']);
    [$first, $second] = Fixture::requests();
    same(Fixture::server() . '/elsewhere/' . Fixture::archiveName(Fixture::MUSL), $first['target']);
    same('Basic ' . base64_encode('u:p'), $first['headers']['proxy-authorization'] ?? null);
    same('/files/' . Fixture::archiveName(Fixture::MUSL), $second['target']);
    same('localhost:' . Fixture::port(), $second['host']);
    same(false, isset($second['headers']['proxy-authorization']));
}

function no_proxy_bypasses_the_proxy(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $env = [
        'http_proxy' => 'http://127.0.0.1:1',
        'no_proxy' => 'example.com, 127.0.0.1',
        'BONSAI_LINT_DOWNLOAD_URL' => $release['base'],
        'BONSAI_LINT_CACHE' => Fixture::directory(),
    ];

    $run = Fixture::resolve(Fixture::directory(), $env, $release['archives']);

    same(null, $run['failure']);
    same('/files/' . Fixture::archiveName(Fixture::MUSL), Fixture::requests()[0]['target']);
}

function proxy_variables_follow_composer(): void
{
    $proxy = static function (string $url, array $env): ?array {
        return Proxy::for($url, Fixture::getenv($env));
    };

    same('tcp://lower:80', $proxy('http://x/', ['http_proxy' => 'http://lower', 'HTTP_PROXY' => 'http://upper'])['proxy']);
    same(null, $proxy('https://x/', ['http_proxy' => 'http://only-http:3128']));
    same(['proxy' => 'ssl://p:8443', 'request_fulluri' => false, 'authorization' => null], $proxy('https://x/', ['HTTPS_PROXY' => 'https://p:8443']));
    same(['proxy' => 'tcp://proxy:3128', 'request_fulluri' => true, 'authorization' => null], $proxy('http://x/', ['http_proxy' => 'proxy:3128']));
    same('Basic ' . base64_encode('u:p w'), $proxy('http://x/', ['http_proxy' => 'http://u:p%20w@h:1'])['authorization']);
    same(null, $proxy('http://x/', ['http_proxy' => 'http://h:1', 'no_proxy' => '*']));
    same(null, $proxy('https://a.example.com/', ['https_proxy' => 'http://h:1', 'no_proxy' => '.example.com']));
    same(null, $proxy('https://example.com/', ['https_proxy' => 'http://h:1', 'no_proxy' => '.example.com']));
    same('tcp://h:1', $proxy('https://badexample.com/', ['https_proxy' => 'http://h:1', 'no_proxy' => '.example.com'])['proxy']);
    same(null, $proxy('http://10.1.2.3/', ['http_proxy' => 'http://h:1', 'no_proxy' => '10.0.0.0/8']));
    same(null, $proxy('http://example.com:8080/', ['http_proxy' => 'http://h:1', 'no_proxy' => 'example.com:8080']));
    same('tcp://h:1', $proxy('http://example.com/', ['http_proxy' => 'http://h:1', 'no_proxy' => 'example.com:8080'])['proxy']);
}

function without_allow_url_fopen_the_fix_is_named(): void
{
    $release = Fixture::release(Fixture::MUSL);
    $env = ['BONSAI_LINT_DOWNLOAD_URL' => $release['base'], 'BONSAI_LINT_CACHE' => Fixture::directory()];
    $host = Fixture::host() ?? Fixture::MUSL;

    $result = Fixture::launch(['archives' => [$host => $release['archives'][Fixture::MUSL]]], 'resolve', [], $env, ['-d', 'allow_url_fopen=0']);

    same(1, $result['code']);
    holds('set allow_url_fopen=1 in php.ini', $result['stderr']);
}

function a_certificate_failure_names_the_fix(): void
{
    $warnings = [
        'SSL operation failed with code 1. OpenSSL Error messages:\nerror:0A000086:SSL routines::certificate verify failed',
        'Failed to enable crypto',
        'Failed to open stream: operation failed',
    ];

    same("PHP cannot verify the server's certificate; install the system CA certificates, or point openssl.cafile in php.ini at a CA bundle", Http::explain($warnings));
}

function every_warning_of_a_failed_open_is_kept(): void
{
    $warnings = ['SSL: Success', 'Failed to enable crypto', 'Failed to enable crypto', "Failed to open stream:\noperation failed"];

    same('SSL: Success; Failed to enable crypto; Failed to open stream: operation failed', Http::explain($warnings));
}

// How many warnings PHP raises here differs by platform: one on Windows, three elsewhere.
function a_failed_open_names_the_url_and_what_php_said(): void
{
    $url = 'https://127.0.0.1:' . Fixture::port() . '/files/x';
    $http = new Http('test', Fixture::getenv([]));

    $failure = fails(static function () use ($http, $url): void {
        $http->get($url);
    });

    holds('downloading ' . $url . ': ', $failure);
    holds('failed to open stream', strtolower($failure));
}

function a_proxy_that_refuses_the_tunnel_is_reported(): void
{
    $portFile = path(Fixture::directory(), 'port');
    $proxy = Fixture::spawn([PHP_BINARY, path(__DIR__, 'refusing-proxy.php'), $portFile]);
    for ($wait = 0; $wait < 100 && (string) @file_get_contents($portFile) === ''; $wait++) {
        usleep(20000);
    }
    $env = [
        'https_proxy' => 'http://user:wrong@127.0.0.1:' . file_get_contents($portFile),
        'BONSAI_LINT_CACHE' => Fixture::directory(),
    ];

    $run = Fixture::resolve(Fixture::directory(), $env, [Fixture::MUSL => ['bonsai-lint-' . Fixture::MUSL . '.tar.gz', str_repeat('0', 64)]]);
    proc_terminate($proxy['process']);
    Fixture::wait($proxy);

    holds('the proxy refused to connect to github.com:443 (HTTP/1.1 407 Proxy Authentication Required)', $run['failure']);
}
