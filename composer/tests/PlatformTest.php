<?php

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

use BonsaiLint\Composer\Platform;

function every_supported_platform_maps_to_its_release_triple(): void
{
    $cases = [
        ['Darwin', 'arm64', '', 'aarch64-apple-darwin'],
        ['Darwin', 'x86_64', '', 'x86_64-apple-darwin'],
        ['Linux', 'x86_64', '', 'x86_64-unknown-linux-musl'],
        ['Linux', 'amd64', '', 'x86_64-unknown-linux-musl'],
        ['Linux', 'aarch64', '', 'aarch64-unknown-linux-musl'],
        ['Linux', 'arm64', '', 'aarch64-unknown-linux-musl'],
        ['Windows', 'AMD64', '', 'x86_64-pc-windows-msvc'],
        ['Windows', 'ARM64', '', 'x86_64-pc-windows-msvc'],
        ['Windows', 'i586', 'AMD64', 'x86_64-pc-windows-msvc'],
        ['Windows', 'i586', '', null],
        ['Linux', 'armv7l', '', null],
        ['BSD', 'amd64', '', null],
    ];
    foreach ($cases as [$family, $machine, $native, $triple]) {
        same($triple, Platform::triple($family, $machine, $native), "$family $machine $native");
        same(true, $triple === null || in_array($triple, Platform::TRIPLES, true), (string) $triple);
    }
    $mapped = array_filter(array_column($cases, 3));
    sort($mapped);
    $declared = Platform::TRIPLES;
    sort($declared);
    same($declared, array_values(array_unique($mapped)));
}

function linux_always_gets_the_static_build(): void
{
    foreach (['x86_64', 'amd64', 'aarch64', 'arm64'] as $machine) {
        same('-unknown-linux-musl', substr((string) Platform::triple('Linux', $machine), -19), $machine);
    }
    foreach (Platform::TRIPLES as $triple) {
        same(false, strpos($triple, '-linux-gnu') !== false, $triple);
    }
}

function an_unsupported_platform_names_itself_and_the_fallback(): void
{
    $run = Fixture::resolve(Fixture::directory(), [], [Fixture::MUSL => ['x', str_repeat('0', 64)]], '1.2.3', 'BSD', 'amd64');

    same('bonsai-lint v1.2.3: there is no prebuilt binary for BSD amd64; install it with `cargo install bonsai-lint`, or set BONSAI_LINT_BINARY to a binary you have', $run['failure']);
    same([], Fixture::requests());
}

function a_platform_missing_from_the_release_is_unsupported(): void
{
    $run = Fixture::resolve(Fixture::directory(), [], ['aarch64-apple-darwin' => ['x', str_repeat('0', 64)]]);

    holds('there is no prebuilt binary for Linux x86_64', $run['failure']);
}

function every_php_platform_is_in_the_generated_release(): void
{
    $golden = (string) file_get_contents(path(Fixture::root(), 'internal', 'generate', 'testdata', 'Release.php.golden'));

    foreach (Platform::TRIPLES as $triple) {
        holds("'$triple' => [", $golden);
    }
}
