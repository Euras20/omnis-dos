<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

final class QcStatus
{
    public const PENDING = 'pending';
    public const CERTIFIED = 'certified';
    public const QUARANTINE = 'quarantine';
    public const REJECTED = 'rejected';
    public const PARTIAL = 'partial';

    public static function isKnown(string $status): bool
    {
        return in_array($status, [
            self::PENDING,
            self::CERTIFIED,
            self::QUARANTINE,
            self::REJECTED,
            self::PARTIAL,
        ], true);
    }
}
