<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

final class PurchaseOrderStatus
{
    public const DRAFT = 'draft';
    public const APPROVED = 'approved';
    public const CANCELLED = 'cancelled';
    public const CLOSED = 'closed';

    public static function isKnown(string $status): bool
    {
        return in_array($status, [
            self::DRAFT,
            self::APPROVED,
            self::CANCELLED,
            self::CLOSED,
        ], true);
    }
}
