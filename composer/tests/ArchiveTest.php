<?php

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

function the_unix_archive_holds_the_binary_below_its_directory(): void
{
    $stem = 'bonsai-lint-' . Fixture::MUSL;
    $bytes = Fixture::tarGz([
        ['name' => $stem . '/', 'type' => '5'],
        ['name' => $stem . '/README.md', 'content' => 'readme'],
        ['name' => $stem . '/bonsai-lint', 'content' => 'binary'],
    ]);

    same('binary', Fixture::extract($bytes, $stem . '.tar.gz'));
}

function a_tar_gz_without_its_top_directory_still_works(): void
{
    $bytes = Fixture::tarGz([['name' => './bonsai-lint', 'content' => 'binary']]);

    same('binary', Fixture::extract($bytes, 'bonsai-lint-' . Fixture::MUSL . '.tar.gz'));
}

function a_posix_tar_with_a_prefix_is_read(): void
{
    $stem = 'bonsai-lint-' . Fixture::MUSL;
    $bytes = Fixture::tarGz([['name' => 'bonsai-lint', 'prefix' => $stem, 'magic' => "ustar\x0000", 'content' => 'binary']]);

    same('binary', Fixture::extract($bytes, $stem . '.tar.gz'));
}

function the_windows_zip_is_flat(): void
{
    $bytes = Fixture::zip([['name' => 'README.md', 'content' => 'readme'], ['name' => 'bonsai-lint.exe', 'content' => 'binary']]);

    same('binary', Fixture::extract($bytes, 'bonsai-lint-x86_64-pc-windows-msvc.zip', 'bonsai-lint.exe'));
}

function a_stored_zip_entry_is_copied_as_is(): void
{
    $bytes = Fixture::zip([['name' => 'bonsai-lint.exe', 'content' => 'binary', 'method' => 0]]);

    same('binary', Fixture::extract($bytes, 'bonsai-lint-x86_64-pc-windows-msvc.zip', 'bonsai-lint.exe'));
}

function an_archive_without_the_binary_is_an_error(): void
{
    $tar = Fixture::tarGz([['name' => 'README.md', 'content' => 'readme']]);
    $zip = Fixture::zip([['name' => 'README.md', 'content' => 'readme']]);

    holds('holds no bonsai-lint', fails(static function () use ($tar): void {
        Fixture::extract($tar, 'bonsai-lint-' . Fixture::MUSL . '.tar.gz');
    }));
    holds('holds no bonsai-lint.exe', fails(static function () use ($zip): void {
        Fixture::extract($zip, 'bonsai-lint-x86_64-pc-windows-msvc.zip', 'bonsai-lint.exe');
    }));
}

function only_a_regular_file_is_taken(): void
{
    $stem = 'bonsai-lint-' . Fixture::MUSL;
    $tar = Fixture::tarGz([
        ['name' => $stem . '/bonsai-lint', 'type' => '2', 'link' => '/etc/passwd'],
        ['name' => $stem . '/bonsai-lint', 'type' => '1', 'link' => $stem . '/README.md'],
        ['name' => $stem . '/bonsai-lint', 'content' => 'real'],
    ]);
    $zip = Fixture::zip([
        ['name' => 'bonsai-lint.exe', 'content' => '/etc/passwd', 'mode' => 0120777],
        ['name' => 'bonsai-lint.exe/', 'mode' => 040755],
        ['name' => 'bonsai-lint.exe', 'content' => 'real'],
    ]);

    same('real', Fixture::extract($tar, $stem . '.tar.gz'));
    same('real', Fixture::extract($zip, 'bonsai-lint-x86_64-pc-windows-msvc.zip', 'bonsai-lint.exe'));
}

function entry_names_never_choose_the_destination(): void
{
    $tar = Fixture::tarGz([
        ['name' => '../../bonsai-lint', 'content' => 'escaped'],
        ['name' => '/tmp/bonsai-lint', 'content' => 'escaped'],
    ]);

    holds('holds no bonsai-lint', fails(static function () use ($tar): void {
        Fixture::extract($tar, 'bonsai-lint-' . Fixture::MUSL . '.tar.gz');
    }));
}

function a_zip_entry_failing_its_crc_is_refused(): void
{
    $zip = Fixture::zip([['name' => 'bonsai-lint.exe', 'content' => 'binary', 'crc' => 12345]]);

    holds('bonsai-lint.exe fails its CRC check', fails(static function () use ($zip): void {
        Fixture::extract($zip, 'bonsai-lint-x86_64-pc-windows-msvc.zip', 'bonsai-lint.exe');
    }));
}

function zip64_and_unknown_compression_are_refused(): void
{
    $bzip2 = Fixture::zip([['name' => 'bonsai-lint.exe', 'content' => 'binary', 'method' => 12]]);
    $zip64 = Fixture::zip([['name' => 'bonsai-lint.exe', 'content' => 'binary']], true);

    holds('compressed with method 12', fails(static function () use ($bzip2): void {
        Fixture::extract($bzip2, 'bonsai-lint-x86_64-pc-windows-msvc.zip', 'bonsai-lint.exe');
    }));
    holds('is a Zip64 archive', fails(static function () use ($zip64): void {
        Fixture::extract($zip64, 'bonsai-lint-x86_64-pc-windows-msvc.zip', 'bonsai-lint.exe');
    }));
}
