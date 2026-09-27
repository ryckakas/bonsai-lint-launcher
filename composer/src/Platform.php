<?php

declare(strict_types=1);

namespace BonsaiLint\Composer;

final class Platform
{
    public const TRIPLES = [
        'aarch64-apple-darwin',
        'x86_64-apple-darwin',
        'aarch64-unknown-linux-musl',
        'x86_64-unknown-linux-musl',
        'x86_64-pc-windows-msvc',
    ];

    private const ARCHITECTURES = [
        'x86_64' => 'x86_64',
        'amd64' => 'x86_64',
        'aarch64' => 'aarch64',
        'arm64' => 'aarch64',
    ];

    public static function triple(string $family, string $machine, string $nativeMachine = ''): ?string
    {
        if ($family === 'Windows') {
            return self::windows($nativeMachine !== '' ? $nativeMachine : $machine);
        }
        $architecture = self::ARCHITECTURES[strtolower($machine)] ?? null;
        if ($architecture === null) {
            return null;
        }
        // The static musl build runs on any Linux, so there is no glibc to detect.
        if ($family === 'Linux') {
            return $architecture . '-unknown-linux-musl';
        }
        return $family === 'Darwin' ? $architecture . '-apple-darwin' : null;
    }

    public static function executable(string $triple): string
    {
        return self::isWindows($triple) ? 'bonsai-lint.exe' : 'bonsai-lint';
    }

    // Windows on ARM runs the x64 build under emulation, as the npm package and Go launcher do.
    private static function windows(string $machine): ?string
    {
        return isset(self::ARCHITECTURES[strtolower($machine)]) ? 'x86_64-pc-windows-msvc' : null;
    }

    private static function isWindows(string $triple): bool
    {
        return substr($triple, -13) === '-windows-msvc';
    }
}
