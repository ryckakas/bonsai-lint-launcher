<?php

declare(strict_types=1);

namespace BonsaiLint\Composer;

final class Failure extends \RuntimeException
{
    public static function lastError(): string
    {
        $error = error_get_last();
        if ($error === null) {
            return 'unknown error';
        }
        return (string) preg_replace('/^[\w:]+\(.*?\): /', '', $error['message']);
    }
}
