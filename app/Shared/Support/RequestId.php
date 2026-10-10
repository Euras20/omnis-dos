<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Str;

/**
 * Generates or extracts the correlation request ID used on every response
 * and stored on journals / audit_event / outbox rows.
 */
final class RequestId
{
    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    public static function fromHeader(?string $value): string
    {
        if ($value !== null && $value !== '' && Str::isUuid($value)) {
            return $value;
        }

        return self::generate();
    }
}
