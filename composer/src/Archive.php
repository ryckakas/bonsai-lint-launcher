<?php

declare(strict_types=1);

namespace BonsaiLint\Composer;

// Reads exactly one regular file out of a release archive with nothing but zlib: ext-zip and
// ext-phar are often missing, and the destination is chosen here, never by an entry's name.
final class Archive
{
    private const CHUNK = 65536;

    public static function extract(string $archive, string $name, string $binary, string $destination, int $cap): void
    {
        $out = self::open($destination, 'xb', $name);
        try {
            if (substr($name, -7) === '.tar.gz') {
                self::fromTarGz($archive, $name, $binary, $out, $cap);
            } elseif (substr($name, -4) === '.zip') {
                self::fromZip($archive, $name, $binary, $out, $cap);
            } else {
                throw new Failure($name . ': unsupported archive format');
            }
        } finally {
            fclose($out);
        }
    }

    private static function open(string $path, string $mode, string $name)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new Failure(sprintf('%s: cannot open %s (%s)', $name, $path, Failure::lastError()));
        }
        return $handle;
    }

    private static function unpacked(string $format, string $data, string $name): array
    {
        $fields = unpack($format, $data);
        if ($fields === false) {
            throw new Failure($name . ' is damaged');
        }
        return $fields;
    }

    private static function write($out, string $data, string $name): void
    {
        if (fwrite($out, $data) !== strlen($data)) {
            throw new Failure(sprintf('%s: cannot write the extracted binary (%s)', $name, Failure::lastError()));
        }
    }

    public static function holds(string $entry, string $name, string $binary): bool
    {
        $entry = str_replace('\\', '/', $entry);
        while (strpos($entry, './') === 0) {
            $entry = substr($entry, 2);
        }
        $stem = (string) preg_replace('/\.(tar\.gz|zip)$/', '', $name);
        return $entry === $binary || $entry === $stem . '/' . $binary;
    }

    private static function fromTarGz(string $archive, string $name, string $binary, $out, int $cap): void
    {
        $in = self::open('compress.zlib://' . $archive, 'rb', $name);
        try {
            while (($entry = self::tarEntry($in, $name)) !== null) {
                if (($entry['type'] === '0' || $entry['type'] === "\0") && self::holds($entry['path'], $name, $binary)) {
                    self::copy($in, $out, $entry['size'], $cap, $name, $binary);
                    return;
                }
                self::skip($in, $entry['size'] + (512 - $entry['size'] % 512) % 512);
            }
        } finally {
            fclose($in);
        }
        throw new Failure(sprintf('%s holds no %s', $name, $binary));
    }

    private static function tarEntry($in, string $name): ?array
    {
        $header = self::read($in, 512);
        if (strlen($header) < 512 || trim($header, "\0") === '') {
            return null;
        }
        $path = rtrim(substr($header, 0, 100), "\0");
        if (substr($header, 257, 6) === "ustar\0" && trim(substr($header, 345, 155), "\0") !== '') {
            $path = rtrim(substr($header, 345, 155), "\0") . '/' . $path;
        }
        $size = trim(substr($header, 124, 12), " \0");
        if (preg_match('/^[0-7]+$/', $size) !== 1) {
            throw new Failure($name . ': an entry has a size this launcher cannot read');
        }
        return ['path' => $path, 'size' => (int) octdec($size), 'type' => $header[156]];
    }

    private static function copy($in, $out, int $size, int $cap, string $name, string $binary): void
    {
        if ($size > $cap) {
            throw new Failure(sprintf('%s: %s is larger than %d bytes', $name, $binary, $cap));
        }
        for ($left = $size; $left > 0; $left -= strlen($chunk)) {
            $chunk = (string) fread($in, min($left, self::CHUNK));
            if ($chunk === '') {
                throw new Failure($name . ' is truncated');
            }
            self::write($out, $chunk, $name);
        }
    }

    private static function skip($in, int $length): void
    {
        for ($left = $length; $left > 0; $left -= strlen($chunk)) {
            $chunk = (string) fread($in, min($left, self::CHUNK));
            if ($chunk === '') {
                return;
            }
        }
    }

    private static function read($in, int $length): string
    {
        $data = '';
        for ($want = $length; $want > 0; $want = $length - strlen($data)) {
            $chunk = (string) fread($in, $want);
            if ($chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private static function fromZip(string $archive, string $name, string $binary, $out, int $cap): void
    {
        $in = self::open($archive, 'rb', $name);
        try {
            $entry = self::zipEntry($in, $name, $binary);
            fseek($in, $entry['offset']);
            $local = self::read($in, 30);
            if (strlen($local) < 30 || substr($local, 0, 4) !== "PK\x03\x04") {
                throw new Failure($name . ' has a damaged entry');
            }
            $lengths = self::unpacked('vname/vextra', substr($local, 26, 4), $name);
            fseek($in, $entry['offset'] + 30 + $lengths['name'] + $lengths['extra']);
            self::inflate($in, $out, $entry, $cap, $name, $binary);
        } finally {
            fclose($in);
        }
    }

    private static function zipEntry($in, string $name, string $binary): array
    {
        $directory = self::centralDirectory($in, $name);
        for ($at = 0; strlen($directory) - $at >= 46; $at += 46 + $field['name'] + $field['extra'] + $field['comment']) {
            if (substr($directory, $at, 4) !== "PK\x01\x02") {
                throw new Failure($name . ' has a damaged central directory');
            }
            $field = self::unpacked('vflags/vmethod/x4/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/x4/Vexternal/Voffset', substr($directory, $at + 8, 38), $name);
            $field['path'] = substr($directory, $at + 46, $field['name']);
            if (self::isRegular($field) && self::holds($field['path'], $name, $binary)) {
                return $field;
            }
        }
        throw new Failure(sprintf('%s holds no %s', $name, $binary));
    }

    private static function isRegular(array $field): bool
    {
        $type = ($field['external'] >> 16) & 0xF000;
        return substr($field['path'], -1) !== '/' && ($type === 0 || $type === 0x8000);
    }

    private static function centralDirectory($in, string $name): string
    {
        $stat = fstat($in);
        if ($stat === false) {
            throw new Failure($name . ' cannot be read');
        }
        $size = $stat['size'];
        $tailLength = min($size, 65557);
        fseek($in, $size - $tailLength);
        $tail = self::read($in, $tailLength);
        $at = strrpos($tail, "PK\x05\x06");
        if ($at === false || strlen($tail) - $at < 22) {
            throw new Failure($name . ' is not a zip archive');
        }
        $end = self::unpacked('ventries/Vsize/Voffset', substr($tail, $at + 10, 10), $name);
        if ($end['entries'] === 0xFFFF || $end['offset'] === 0xFFFFFFFF || ($at >= 20 && substr($tail, $at - 20, 4) === "PK\x06\x07")) {
            throw new Failure($name . ' is a Zip64 archive, which this launcher cannot read');
        }
        fseek($in, $end['offset']);
        return self::read($in, $end['size']);
    }

    private static function readable(array $entry, string $name, string $binary): void
    {
        if ($entry['method'] !== 0 && $entry['method'] !== 8) {
            throw new Failure(sprintf('%s: %s is compressed with method %d, which this launcher cannot read', $name, $binary, $entry['method']));
        }
        if (($entry['flags'] & 1) === 1) {
            throw new Failure(sprintf('%s: %s is encrypted', $name, $binary));
        }
    }

    private static function inflate($in, $out, array $entry, int $cap, string $name, string $binary): void
    {
        self::readable($entry, $name, $binary);
        $inflate = $entry['method'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
        if ($inflate === false) {
            throw new Failure('zlib cannot inflate ' . $name);
        }
        $crc = hash_init('crc32b');
        $written = 0;
        for ($left = $entry['compressed']; $left > 0; $left -= strlen($chunk)) {
            $chunk = (string) fread($in, min($left, self::CHUNK));
            if ($chunk === '') {
                throw new Failure($name . ' is truncated');
            }
            $data = $inflate === null ? $chunk : (string) inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
            $written += strlen($data);
            if ($written > $cap) {
                throw new Failure(sprintf('%s: %s is larger than %d bytes', $name, $binary, $cap));
            }
            hash_update($crc, $data);
            self::write($out, $data, $name);
        }
        if ($written !== $entry['size'] || hash_final($crc) !== sprintf('%08x', $entry['crc'])) {
            throw new Failure(sprintf('%s: %s fails its CRC check', $name, $binary));
        }
    }
}
