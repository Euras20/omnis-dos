<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain;

final class QuotationStatus
{
    public const DRAFT = 'draft';
    public const SENT = 'sent';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const CONVERTED = 'converted';

    public static function isKnown(string $status): bool
    {
        return in_array($status, [
            self::DRAFT,
            self::SENT,
            self::ACCEPTED,
            self::DECLINED,
            self::EXPIRED,
            self::CONVERTED,
        ], true);
    }

    /** Statuses that may transition via POST /quotations/{id}/status */
    public static function isTransitionTarget(string $status): bool
    {
        return in_array($status, [
            self::SENT,
            self::ACCEPTED,
            self::DECLINED,
            self::EXPIRED,
        ], true);
    }
}
