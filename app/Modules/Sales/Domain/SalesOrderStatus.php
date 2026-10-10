<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain;

final class SalesOrderStatus
{
    public const DRAFT = 'draft';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const CLOSED = 'closed';

    public static function isKnown(string $status): bool
    {
        return in_array($status, [
            self::DRAFT,
            self::CONFIRMED,
            self::CANCELLED,
            self::CLOSED,
        ], true);
    }
}
