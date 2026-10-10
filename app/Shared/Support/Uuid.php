<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid as RamseyUuid;

/**
 * Thin wrapper so domain code does not import framework helpers directly
 * when only a UUID string is needed.
 */
final class Uuid
{
    public static function v4(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Prefer time-ordered UUIDs for new entity primary keys when available.
     * Falls back to v4 when the runtime lacks a v7 generator.
     */
    public static function v7(): string
    {
        if (method_exists(Str::class, 'uuid7')) {
            return (string) Str::uuid7();
        }

        return self::v4();
    }

    public static function isValid(string $value): bool
    {
        return RamseyUuid::isValid($value);
    }
}
