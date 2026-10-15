<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Domain;

/**
 * Delivery note lifecycle matching the frontend board.
 * planned → picking → packed → dispatched → delivered | failed
 * failed → planned (reschedule)
 */
final class DeliveryStatus
{
    public const PLANNED = 'planned';
    public const PICKING = 'picking';
    public const PACKED = 'packed';
    public const DISPATCHED = 'dispatched';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::PLANNED,
        self::PICKING,
        self::PACKED,
        self::DISPATCHED,
        self::DELIVERED,
        self::FAILED,
        self::CANCELLED,
    ];
}
