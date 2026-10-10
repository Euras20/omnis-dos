<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Canonical stock_movement.movement_type values.
 * qty is signed: positive increases on-hand, negative decreases.
 */
final class MovementType
{
    public const RECEIPT = 'receipt';

    public const ISSUE = 'issue';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public const ADJUSTMENT = 'adjustment';

    public const DELIVERY = 'delivery';

    public const OPENING = 'opening';

    public const RETURN = 'return';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::RECEIPT,
            self::ISSUE,
            self::TRANSFER_IN,
            self::TRANSFER_OUT,
            self::ADJUSTMENT,
            self::DELIVERY,
            self::OPENING,
            self::RETURN,
        ];
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * Whether this type is expected to reduce on-hand (qty should be negative).
     */
    public static function isOutbound(string $type): bool
    {
        return in_array($type, [
            self::ISSUE,
            self::TRANSFER_OUT,
            self::DELIVERY,
        ], true);
    }
}
